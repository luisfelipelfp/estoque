// js/api.js
const APP_BASE = "/estoque";
const API_URL = `${APP_BASE}/api/actions.php`;

export async function apiRequest(acao, dados = null, metodo = "GET") {
  let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;

  const options = {
    method: metodo,
    credentials: "include",
    headers: { Accept: "application/json" }
  };

  if (metodo === "GET" && dados) {
    const query = new URLSearchParams(dados).toString();
    url += "&" + query;
  }

  if (metodo === "POST") {
    options.headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(dados || {});
  }

  try {
    const resp = await fetch(url, options);

    const contentType = resp.headers.get("content-type") || "";
    const payload = contentType.includes("application/json")
      ? await resp.json()
      : { sucesso: false, mensagem: await resp.text() };

    // NÃO joga exception em 401: devolve payload para o caller decidir
    return payload;
  } catch (err) {
    return {
      sucesso: false,
      mensagem: err?.message || "Erro de comunicação com o servidor."
    };
  }
} 