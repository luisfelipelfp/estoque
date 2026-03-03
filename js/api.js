// js/api.js

// Base da API (NGINX root: /var/www/estoque/public)
const BASE_URL = "/api";
const API_URL = `${BASE_URL}/actions.php`;
const AUTH_URL = BASE_URL;

/**
 * Função central de comunicação com a API
 */
export async function apiRequest(acao, dados = null, metodo = "GET") {
  let url;

  if (acao === "login") {
    url = `${AUTH_URL}/login.php`;
  } else if (acao === "logout") {
    url = `${AUTH_URL}/logout.php`;
  } else if (acao === "usuario_atual") {
    url = `${AUTH_URL}/usuario.php`;
  } else {
    url = `${API_URL}?acao=${encodeURIComponent(acao)}`;
  }

  const options = {
    method: metodo,
    credentials: "include", // 🔐 ESSENCIAL para sessão PHP
    headers: {}
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
  if (metodo === "POST" && dados) {
    options.headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(dados);
  }

  try {
    const resp = await fetch(url, options);

    const contentType = resp.headers.get("content-type") || "";

    let payload = null;
    if (contentType.includes("application/json")) {
      payload = await resp.json();
    }

    if (!resp.ok) {
      const msg = payload?.mensagem || `Erro HTTP ${resp.status}`;
      throw new Error(msg);
    }

    return payload;

  } catch (err) {
    console.error("Erro em apiRequest:", err);

    return {
      sucesso: false,
      mensagem: err.message || "Erro de comunicação com o servidor."
    };
  }
}
