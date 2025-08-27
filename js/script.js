const API_URL = "http://192.168.15.100/estoque/api/actions.php";

async function apiRequest(acao, dados = null, metodo = "GET") {
    let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;
    let options = { method: metodo };

    if (metodo === "GET" && dados) {
        const query = new URLSearchParams(dados).toString();
        url += "&" + query;
    } else if (metodo === "POST" && dados) {
        options.body = new FormData();
        for (let key in dados) {
            options.body.append(key, dados[key]);
        }
    }

    const resp = await fetch(url, options);
    return resp.json();
}

// ---------------- Produtos ----------------
async function listarProdutos() {
    try {
        const resp = await apiRequest("listarprodutos");
        let produtos = Array.isArray(resp) ? resp : (resp?.dados || []);
        const tabela = document.querySelector("#tabelaProdutos tbody");
        tabela.innerHTML = "";

        produtos.forEach(prod => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${prod.id}</td>
                <td>${prod.nome}</td>
                <td>${prod.quantidade}</td>
                <td>
                    <button onclick="entrada(${prod.id})">Entrada</button>
                    <button onclick="saida(${prod.id})">Saída</button>
                    <button onclick="remover(${prod.id})">Remover</button>
                </td>
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar produtos:", err);
    }
}

// ---------------- Movimentações ----------------
async function listarMovimentacoes() {
    try {
        const resp = await apiRequest("listarmovimentacoes");
        let movimentacoes = Array.isArray(resp) ? resp : (resp?.dados || []);
        const tabela = document.querySelector("#tabelaMovimentacoes tbody");
        tabela.innerHTML = "";

        movimentacoes.forEach(mov => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${mov.id}</td>
                <td>${mov.produto_nome || "-"}</td>
                <td>${mov.tipo}</td>
                <td>${mov.quantidade}</td>
                <td>${mov.data}</td>
                <td>${mov.usuario || "-"}</td>
                <td>${mov.responsavel || "-"}</td>
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar movimentações:", err);
    }
}

// ---------------- Ações ----------------
async function entrada(id) {
    const qtd = prompt("Quantidade de entrada:");
    const usuario = prompt("Usuário:");
    const responsavel = prompt("Responsável:");
    if (qtd) {
        await apiRequest("entrada", { id, quantidade: qtd, usuario, responsavel }, "POST");
        listarProdutos();
        listarMovimentacoes();
    }
}

async function saida(id) {
    const qtd = prompt("Quantidade de saída:");
    const usuario = prompt("Usuário:");
    const responsavel = prompt("Responsável:");
    if (qtd) {
        await apiRequest("saida", { id, quantidade: qtd, usuario, responsavel }, "POST");
        listarProdutos();
        listarMovimentacoes();
    }
}

async function remover(id) {
    const usuario = prompt("Usuário:");
    const responsavel = prompt("Responsável:");
    if (confirm("Deseja remover este produto?")) {
        await apiRequest("remover", { id, usuario, responsavel }, "POST");
        listarProdutos();
        listarMovimentacoes();
    }
}

// ---------------- Inicialização ----------------
window.onload = () => {
    listarProdutos();
    listarMovimentacoes();
};
