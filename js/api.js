const API_URL = "http://192.168.15.100/estoque/api/actions.php";

async function apiRequest(acao, dados = null, metodo = "GET") {
    let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;
    let options = { method: metodo };

    if (metodo === "GET" && dados) {
        const query = new URLSearchParams(dados).toString();
        url += "&" + query;
    } else if (metodo === "POST" && dados) {
        options.headers = {
            "Content-Type": "application/json"
        };
        options.body = JSON.stringify(dados);
    }

    const resp = await fetch(url, options);
    return resp.json();
}