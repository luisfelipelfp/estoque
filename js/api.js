const APP_BASE = "/estoque";
const API_URL = `${APP_BASE}/api/actions.php`;

export async function apiRequest(acao, dados = null, metodo = "GET") {
  const method = String(metodo || "GET").toUpperCase();
  let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;

  const options = {
    method,
    credentials: "include",
    headers: {
      Accept: "application/json"
    }
  };

  if (method === "GET" && dados && typeof dados === "object") {
    const query = new URLSearchParams();

    for (const [chave, valor] of Object.entries(dados)) {
      if (valor === undefined || valor === null || valor === "") continue;
      query.append(chave, String(valor));
    }

    const qs = query.toString();
    if (qs) {
      url += `&${qs}`;
    }
  }

  if (method === "POST") {
    options.headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(dados || {});
  }

  try {
    const resp = await fetch(url, options);
    const contentType = (resp.headers.get("content-type") || "").toLowerCase();

    let payload;
    if (contentType.includes("application/json")) {
      payload = await resp.json();
    } else {
      const texto = await resp.text();
      payload = {
        sucesso: false,
        mensagem: texto || `Erro HTTP ${resp.status}`
      };
    }

    if (typeof payload !== "object" || payload === null) {
      payload = {
        sucesso: false,
        mensagem: "Resposta inválida do servidor."
      };
    }

    if (!("sucesso" in payload)) {
      payload.sucesso = resp.ok;
    }

    if (!resp.ok && (!payload.mensagem || payload.mensagem === "OK")) {
      payload.mensagem = `Erro HTTP ${resp.status}`;
    }

    return payload;
  } catch (err) {
    return {
      sucesso: false,
      mensagem: err?.message || "Erro de comunicação com o servidor."
    };
  }
} 