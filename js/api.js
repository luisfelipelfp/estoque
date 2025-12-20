// js/api.js
import { logJsError } from "./logger.js";

const BASE_URL = "http://192.168.15.100/estoque/public";
const API_URL  = `${BASE_URL}/api`;
const ACTIONS_URL = `${API_URL}/actions.php`;

export async function apiRequest(acao, dados = null, metodo = "GET") {
  try {
    let url;

    if (acao === "login") {
      url = `${API_URL}/login.php`;
    } else if (acao === "logout") {
      url = `${API_URL}/logout.php`;
    } else if (acao === "usuario_atual") {
      url = `${API_URL}/usuario.php`;
    } else {
      url = `${ACTIONS_URL}?acao=${encodeURIComponent(acao)}`;
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
      formData.append("acao", acao);

      for (const k in dados) {
        formData.append(k, dados[k]);
      }

      options.body = formData;
    }

    const resp = await fetch(url, options);

    if (!resp.ok) {
      throw new Error(`HTTP ${resp.status} - ${resp.statusText}`);
    }

    return await resp.json();
  } catch (err) {
    console.error("Erro em apiRequest:", err);

    logJsError({
      origem: "apiRequest",
      mensagem: err.message,
      stack: err.stack
    });

    return { sucesso: false, mensagem: "Erro de comunicação com o servidor." };
  }
}
