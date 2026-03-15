const APP_BASE = "/estoque";
const API_URL = `${APP_BASE}/api/actions.php`;
const DEFAULT_TIMEOUT_MS = 30000;

let csrfTokenCache = null;
let csrfTokenPromise = null;

function isPlainObject(value) {
  return Object.prototype.toString.call(value) === "[object Object]";
}

function isNil(value) {
  return value === undefined || value === null;
}

function normalizarMetodo(metodo) {
  return String(metodo || "GET").trim().toUpperCase();
}

function serializarValorQuery(valor) {
  if (isNil(valor)) return null;

  if (typeof valor === "string") {
    const v = valor.trim();
    return v === "" ? null : v;
  }

  if (typeof valor === "number" || typeof valor === "boolean") {
    return String(valor);
  }

  if (Array.isArray(valor)) {
    if (valor.length === 0) return null;
    return JSON.stringify(valor);
  }

  if (isPlainObject(valor)) {
    const keys = Object.keys(valor);
    if (keys.length === 0) return null;
    return JSON.stringify(valor);
  }

  return String(valor);
}

function montarQueryString(dados) {
  const query = new URLSearchParams();

  if (!isPlainObject(dados)) {
    return query.toString();
  }

  for (const [chave, valor] of Object.entries(dados)) {
    const valorSerializado = serializarValorQuery(valor);
    if (valorSerializado === null) continue;
    query.append(chave, valorSerializado);
  }

  return query.toString();
}

function montarMensagemHttp(status) {
  const mapa = {
    400: "Requisição inválida.",
    401: "Sessão expirada ou usuário não autenticado.",
    403: "Acesso negado.",
    404: "Recurso não encontrado.",
    405: "Método não permitido.",
    408: "Tempo de requisição esgotado.",
    409: "Conflito ao processar a requisição.",
    419: "Falha de validação de segurança da sessão.",
    422: "Dados inválidos enviados ao servidor.",
    429: "Muitas requisições. Tente novamente em instantes.",
    500: "Erro interno no servidor.",
    502: "Falha de comunicação com o servidor.",
    503: "Serviço temporariamente indisponível.",
    504: "Tempo de resposta do servidor esgotado."
  };

  return mapa[status] || `Erro HTTP ${status}.`;
}

async function parseResposta(resp) {
  const contentType = (resp.headers.get("content-type") || "").toLowerCase();

  if (contentType.includes("application/json")) {
    try {
      return await resp.json();
    } catch {
      return {
        sucesso: false,
        mensagem: "O servidor retornou um JSON inválido."
      };
    }
  }

  try {
    const texto = await resp.text();
    return {
      sucesso: false,
      mensagem: texto?.trim() || montarMensagemHttp(resp.status)
    };
  } catch {
    return {
      sucesso: false,
      mensagem: montarMensagemHttp(resp.status)
    };
  }
}

function normalizarPayload(payload, resp = null) {
  if (!isPlainObject(payload)) {
    return {
      sucesso: false,
      mensagem: resp ? montarMensagemHttp(resp.status) : "Resposta inválida do servidor.",
      dados: null
    };
  }

  const normalizado = {
    sucesso: Boolean(payload.sucesso),
    mensagem: typeof payload.mensagem === "string" ? payload.mensagem : "",
    dados: Object.prototype.hasOwnProperty.call(payload, "dados") ? payload.dados : null
  };

  if (resp && !resp.ok && !normalizado.mensagem) {
    normalizado.mensagem = montarMensagemHttp(resp.status);
  }

  if (!resp && !normalizado.mensagem) {
    normalizado.mensagem = normalizado.sucesso
      ? "OK"
      : "Erro de comunicação com o servidor.";
  }

  return normalizado;
}

function limparCsrfToken() {
  csrfTokenCache = null;
  csrfTokenPromise = null;
}

function atualizarCsrfTokenDeHeaders(resp) {
  if (!resp || typeof resp.headers?.get !== "function") return;

  const tokenHeader = resp.headers.get("x-csrf-token");
  if (tokenHeader && tokenHeader.trim() !== "") {
    csrfTokenCache = tokenHeader.trim();
  }
}

function deveIgnorarCsrf(acao) {
  const action = String(acao || "").trim().toLowerCase();

  return [
    "login",
    "logout",
    "csrf_token",
    "csrf",
    "usuario_atual"
  ].includes(action);
}

async function buscarCsrfToken(timeoutMs = DEFAULT_TIMEOUT_MS) {
  if (csrfTokenCache) {
    return csrfTokenCache;
  }

  if (csrfTokenPromise) {
    return csrfTokenPromise;
  }

  csrfTokenPromise = (async () => {
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => {
      controller.abort();
    }, Number.isFinite(timeoutMs) && timeoutMs > 0 ? timeoutMs : DEFAULT_TIMEOUT_MS);

    try {
      const resp = await fetch(`${API_URL}?acao=csrf_token`, {
        method: "GET",
        credentials: "include",
        signal: controller.signal,
        headers: {
          Accept: "application/json"
        }
      });

      const payloadBruto = await parseResposta(resp);
      const payload = normalizarPayload(payloadBruto, resp);

      atualizarCsrfTokenDeHeaders(resp);

      const tokenPayload = payload?.dados?.csrf_token;
      if (typeof tokenPayload === "string" && tokenPayload.trim() !== "") {
        csrfTokenCache = tokenPayload.trim();
      }

      return csrfTokenCache;
    } catch {
      return null;
    } finally {
      window.clearTimeout(timeoutId);
      csrfTokenPromise = null;
    }
  })();

  return csrfTokenPromise;
}

export async function apiRequest(acao, dados = null, metodo = "GET", config = {}) {
  const action = String(acao || "").trim();
  const method = normalizarMetodo(metodo);

  if (!action) {
    return {
      sucesso: false,
      mensagem: "Ação da API não informada.",
      dados: null
    };
  }

  if (!["GET", "POST"].includes(method)) {
    return {
      sucesso: false,
      mensagem: `Método HTTP não suportado: ${method}.`,
      dados: null
    };
  }

  const timeoutMs = Number(config?.timeout ?? DEFAULT_TIMEOUT_MS);
  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => {
    controller.abort();
  }, Number.isFinite(timeoutMs) && timeoutMs > 0 ? timeoutMs : DEFAULT_TIMEOUT_MS);

  let url = `${API_URL}?acao=${encodeURIComponent(action)}`;

  const options = {
    method,
    credentials: "include",
    signal: controller.signal,
    headers: {
      Accept: "application/json"
    }
  };

  const precisaCsrf = method === "POST" && !deveIgnorarCsrf(action);

  if (precisaCsrf) {
    const token = await buscarCsrfToken(timeoutMs);

    if (!token) {
      window.clearTimeout(timeoutId);
      return {
        sucesso: false,
        mensagem: "Não foi possível validar a segurança da sessão. Atualize a página e tente novamente.",
        dados: null
      };
    }

    options.headers["X-CSRF-Token"] = token;
  }

  if (method === "GET") {
    const qs = montarQueryString(dados);
    if (qs) {
      url += `&${qs}`;
    }
  } else if (method === "POST") {
    options.headers["Content-Type"] = "application/json; charset=utf-8";
    options.body = JSON.stringify(isPlainObject(dados) ? dados : {});
  }

  try {
    const resp = await fetch(url, options);
    atualizarCsrfTokenDeHeaders(resp);

    const payloadBruto = await parseResposta(resp);
    const payload = normalizarPayload(payloadBruto, resp);

    if (!resp.ok) {
      payload.sucesso = false;

      if (!payload.mensagem || payload.mensagem === "OK") {
        payload.mensagem = montarMensagemHttp(resp.status);
      }
    }

    if (resp.status === 401 || resp.status === 419) {
      limparCsrfToken();
    }

    return payload;
  } catch (err) {
    if (err?.name === "AbortError") {
      return {
        sucesso: false,
        mensagem: "Tempo de comunicação com o servidor esgotado.",
        dados: null
      };
    }

    return {
      sucesso: false,
      mensagem: err?.message || "Erro de comunicação com o servidor.",
      dados: null
    };
  } finally {
    window.clearTimeout(timeoutId);
  }
}

export function resetApiSecurityCache() {
  limparCsrfToken();
}

export { APP_BASE, API_URL };