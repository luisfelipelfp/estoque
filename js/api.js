// js/api.js

const APP_BASE = "/estoque";
const API_URL = `${APP_BASE}/api/actions.php`;

export async function apiRequest(acao, dados = null, metodo = "GET") {
  let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;

  const options = {
    method: metodo,
    credentials: "include",
    headers: {}
  };

  // GET → querystring
  if (metodo === "GET" && dados) {
    const query = new URLSearchParams(dados).toString();
    url += (url.includes("?") ? "&" : "?") + query;
  }

  // POST → JSON
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

    // mantém o payload mesmo em erro http
    if (!resp.ok) return payload;

    return payload;

  } catch (err) {
    console.error("Erro em apiRequest:", err);
    return { sucesso: false, mensagem: err.message || "Erro de comunicação com o servidor." };
  }
}