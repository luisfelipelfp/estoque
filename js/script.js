const API_URL = "http://192.168.15.100/estoque/api/actions.php";

// Função genérica para requisições
async function apiRequest(action, data = {}) {
    try {
        const response = await fetch(API_URL, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action, ...data })
        });

        if (!response.ok) throw new Error("Erro HTTP " + response.status);

        return await response.json();
    } catch (err) {
        console.error("Erro na API:", err);
        throw err;
    }
}

// ==================== PRODUTOS ====================

// Listar produtos
async function listarProdutos() {
    try {
        const produtos = await apiRequest("listarProdutos");
        const tabela = document.getElementById("tabelaProdutos").querySelector("tbody");
        tabela.innerHTML = "";

        produtos.forEach(p => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${p.id}</td>
                <td>${p.nome}</td>
                <td>${p.quantidade}</td>
                <td>
                    <button class="btn btn-danger btn-sm" onclick="removerProduto('${p.nome}')">Remover</button>
                </td>
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar produtos:", err);
    }
}

// Cadastrar produto
async function cadastrarProduto() {
    const nome = document.getElementById("nomeProduto").value.trim();
    const quantidade = parseInt(document.getElementById("quantidadeProduto").value);

    if (!nome || isNaN(quantidade)) {
        alert("Preencha os campos corretamente.");
        return;
    }

    await apiRequest("cadastrar", { nome, quantidade });
    listarProdutos();
    listarMovimentacoes();
}

// Remover produto
async function removerProduto(nome) {
    if (!confirm("Tem certeza que deseja remover o produto '" + nome + "'?")) return;

    await apiRequest("remover", { nome });
    listarProdutos();
    listarMovimentacoes();
}

// ==================== MOVIMENTAÇÕES ====================

// Registrar movimentação
async function movimentarProduto() {
    const nome = document.getElementById("movNome").value.trim();
    const quantidade = parseInt(document.getElementById("movQuantidade").value);
    const tipo = document.getElementById("movTipo").value;

    if (!nome || isNaN(quantidade)) {
        alert("Preencha os campos corretamente.");
        return;
    }

    await apiRequest("movimentar", { nome, quantidade, tipo });
    listarProdutos();
    listarMovimentacoes();
}

// Listar movimentações
async function listarMovimentacoes() {
    try {
        const movs = await apiRequest("listarMovimentacoes");
        const tabela = document.getElementById("tabelaMovimentacoes").querySelector("tbody");
        tabela.innerHTML = "";

        movs.forEach(m => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${m.id}</td>
                <td>${m.produto_nome}</td>
                <td>${m.quantidade}</td>
                <td>${m.tipo}</td>
                <td>${m.data}</td>
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar movimentações:", err);
    }
}

// ==================== RELATÓRIO ====================

// Gerar relatório por data
async function gerarRelatorio() {
    const inicio = document.getElementById("relInicio").value;
    const fim = document.getElementById("relFim").value;

    if (!inicio || !fim) {
        alert("Preencha o período corretamente.");
        return;
    }

    const dados = await apiRequest("relatorio", { inicio, fim });

    const tabela = document.getElementById("tabelaRelatorio").querySelector("tbody");
    tabela.innerHTML = "";

    dados.forEach(r => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${r.id}</td>
            <td>${r.produto_nome}</td>
            <td>${r.quantidade}</td>
            <td>${r.tipo}</td>
            <td>${r.data}</td>
        `;
        tabela.appendChild(tr);
    });
}

// ==================== INICIALIZAÇÃO ====================

// Carregar listas ao abrir a página
document.addEventListener("DOMContentLoaded", () => {
    listarProdutos();
    listarMovimentacoes();

    document.getElementById("btnCadastrar").addEventListener("click", cadastrarProduto);
    document.getElementById("btnMovimentar").addEventListener("click", movimentarProduto);
    document.getElementById("btnRelatorio").addEventListener("click", gerarRelatorio);
});
