// js/api.js

/**
 * Base do app (onde os arquivos HTML/JS/CSS estão publicados)
 * Ex.: https://IP/estoque/...
 */
const APP_BASE = "/estoque";

/**
 * Endpoint único da API (roteador actions.php)
 */
const API_URL = `${APP_BASE}/api/actions.php`;

/**
 * Função central de comunicação com a API
 */
export async function apiRequest(acao, dados = null, metodo = "GET") {
  let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;

  const options = {
    method: metodo,
    credentials: "include", // ✅ essencial para enviar/receber PHPSESSID
    headers: {
      Accept: "application/json"
    },
    cache: "no-store"
  };

  // ========================
  // GET → querystring
  // ========================
  if (metodo === "GET" && dados) {
    const query = new URLSearchParams(dados).toString();
    url += (url.includes("?") ? "&" : "?") + query;
  }

  // ========================
  // POST → JSON
  // ========================
  if (metodo === "POST") {
    options.headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(dados || {});
  }

  try {
    const resp = await fetch(url, options);

    const contentType = resp.headers.get("content-type") || "";
    let payload;

    if (contentType.includes("application/json")) {
      payload = await resp.json();
    } else {
      const text = await resp.text();
      payload = { sucesso: false, mensagem: text || "Resposta não-JSON da API." };
    }

    // Se a API devolver 401/403, ainda retornamos o JSON (pra main.js redirecionar)
    if (!resp.ok) {
      return {
        sucesso: false,
        mensagem: payload?.mensagem || `Erro HTTP ${resp.status}`,
        status: resp.status,
        dados: payload?.dados ?? null
      };
    }

    return payload;

  } catch (err) {
    console.error("Erro em apiRequest:", err);
    return {
      sucesso: false,
      mensagem: err?.message || "Erro de comunicação com o servidor."
    };
  }
}