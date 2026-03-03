// js/api.js

/**
 * Base do app (onde os arquivos HTML/JS/CSS estão publicados)
 * Como você está usando: https://IP/estoque/...
 */
const APP_BASE = "/estoque";

/**
 * Base da API (pasta api dentro do app)
 */
const BASE_URL = `${APP_BASE}/api`;
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
    credentials: "include", // 🔐 Essencial para sessão PHP
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
    } else {
      // Ajuda muito no debug quando o PHP retorna HTML de erro
      const text = await resp.text();
      payload = { sucesso: false, mensagem: text || "Resposta não-JSON da API." };
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