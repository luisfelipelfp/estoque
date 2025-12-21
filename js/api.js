// js/api.js

// Base da API (NGINX root: /var/www/estoque/public)
const BASE_URL = "/api";
const API_URL = `${BASE_URL}/actions.php`;
const AUTH_URL = BASE_URL;

/**
 * Função central de comunicação com a API
 */
export async function apiRequest(acao, dados = null, metodo = "GET") {
  try {
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
      credentials: "include"
    };

    if (metodo === "GET" && dados) {
      const query = new URLSearchParams(dados).toString();
      url += (url.includes("?") ? "&" : "?") + query;
    }

    if (metodo === "POST" && dados) {
      const formData = new FormData();
      for (const k in dados) {
        formData.append(k, dados[k]);
      }
      options.body = formData;
    }

    const resp = await fetch(url, options);

    if (!resp.ok) {
      throw new Error(`HTTP ${resp.status}`);
    }

    return await resp.json();

  } catch (err) {
    console.error("Erro em apiRequest:", err);
    return {
      sucesso: false,
      mensagem: "Erro de comunicação com o servidor."
    };
  }
}
