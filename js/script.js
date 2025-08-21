async function apiRequest(params) {
    try {
        const response = await fetch("../api/actions.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(params)
        });

        const data = await response.json();
        if (data.erro) {
            alert(data.erro);
            return null;
        }
        return data;
    } catch (err) {
        console.error("Erro na API:", err);
        alert("Erro ao comunicar com o servidor!");
    }
}

// 📌 Listar produtos
async function listarProdutos() {
    const produtos = await apiRequest({ action: "listarProdutos" });
    console.log("Produtos:", produtos);
}

// 📌 Listar movimentações
async function listarMovimentacoes() {
    const movs = await apiRequest({ action: "listarMovimentacoes" });
    console.log("Movimentações:", movs);
}

// 📌 Cadastrar produto
async function cadastrar(nome, quantidade) {
    const res = await apiRequest({ action: "cadastrar", nome, quantidade });
    alert(res?.mensagem);
}

// 📌 Movimentar produto (entrada ou saída)
async function movimentar(nome, quantidade, tipo) {
    const res = await apiRequest({ action: "movimentar", nome, quantidade, tipo });
    alert(res?.mensagem);
}

// 📌 Remover produto
async function remover(nome) {
    const res = await apiRequest({ action: "remover", nome });
    alert(res?.mensagem);
}

// 📌 Relatório por data
async function relatorio(inicio, fim) {
    const dados = await apiRequest({ action: "relatorio", inicio, fim });
    console.log("Relatório:", dados);
}

// 📌 Teste conexão
async function testeConexao() {
    const res = await apiRequest({ action: "testeConexao" });
    alert(res?.mensagem);
}
