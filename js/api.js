// js/api.js
const BASE_URL = "http://192.168.15.100/estoque/api";
const API_URL = `${BASE_URL}/actions.php`;
const AUTH_URL = BASE_URL; // login.php, logout.php, usuario.php ficam aqui

async function apiRequest(acao, dados = null, metodo = "GET") {
  try {
    let url;

    // 🔑 Rotas especiais de autenticação
    if (acao === "login") {
      url = `${AUTH_URL}/login.php`;
    } else if (acao === "logout") {
      url = `${AUTH_URL}/logout.php`;
    } else if (acao === "usuario_atual") {
      url = `${AUTH_URL}/usuario.php`;
    } else {
      // Demais ações vão para actions.php
      url = `${API_URL}?acao=${encodeURIComponent(acao)}`;
    }

    let options = {
      method: metodo,
      credentials: "include" // 🔑 mantém sessão ativa no PHP
    };

    if (metodo === "GET" && dados) {
      // Adiciona query string para GET
      const query = new URLSearchParams(dados).toString();
      url += (url.includes("?") ? "&" : "?") + query;
    } else if (metodo === "POST" && dados) {
      // 🔧 normaliza chaves (id -> produto_id) quando necessário
      if (dados.id && !dados.produto_id) {
        dados.produto_id = dados.id;
        delete dados.id;
      }
      options.headers = { "Content-Type": "application/json" };
      options.body = JSON.stringify(dados);
    }

    const resp = await fetch(url, options);
    if (!resp.ok) {
      throw new Error(`Erro HTTP: ${resp.status} - ${resp.statusText}`);
    }

    const json = await resp.json();
    return json;
  } catch (err) {
    console.error("Erro em apiRequest:", err);
    return { sucesso: false, mensagem: "Erro de comunicação com o servidor." };
  }
}
