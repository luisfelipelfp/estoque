// js/api.js
const API_URL = "http://192.168.15.100/estoque/api/actions.php";
const AUTH_URL = "http://192.168.15.100/estoque/api"; // login.php, logout.php, usuario.php ficam aqui

async function apiRequest(acao, dados = null, metodo = "GET") {
  try {
    let url;

    // Roteamento especial para autenticação
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
      credentials: "include" // 🔑 mantém sessão PHP
    };

    if (metodo === "GET" && dados) {
      const query = new URLSearchParams(dados).toString();
      url += (url.includes("?") ? "&" : "?") + query;
    } else if (metodo === "POST" && dados) {
      // 🔧 normaliza chaves (id -> produto_id)
      if (dados.id && !dados.produto_id) {
        dados.produto_id = dados.id;
        delete dados.id;
      }
      options.headers = { "Content-Type": "application/json" };
      options.body = JSON.stringify(dados);
    }

    const resp = await fetch(url, options);
    if (!resp.ok) throw new Error(`Erro HTTP: ${resp.status}`);

    return await resp.json();
  } catch (err) {
    console.error("Erro em apiRequest:", err);
    return { sucesso: false, mensagem: "Erro de comunicação com o servidor." };
  }
}
