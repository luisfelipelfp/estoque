const API_URL = "http://192.168.15.100/estoque/api/actions.php";

async function apiRequest(acao, dados = null, metodo = "GET") {
  try {
    let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;
    let options = {
      method: metodo,
      credentials: "include" // 🔑 envia cookies de sessão
    };

    if (metodo === "GET" && dados) {
      const query = new URLSearchParams(dados).toString();
      url += "&" + query;
    } else if (metodo === "POST" && dados) {
      // 🔧 Normaliza chaves: converte "id" -> "produto_id"
      if (dados.id && !dados.produto_id) {
        dados.produto_id = dados.id;
        delete dados.id;
      }

      options.headers = {
        "Content-Type": "application/json"
      };
      options.body = JSON.stringify(dados);
    }

    const resp = await fetch(url, options);

    if (!resp.ok) {
      throw new Error(`Erro HTTP: ${resp.status}`);
    }

    const data = await resp.json();
    return data;
  } catch (err) {
    console.error("Erro em apiRequest:", err);
    return { sucesso: false, mensagem: "Erro de comunicação com o servidor." };
  }
}
