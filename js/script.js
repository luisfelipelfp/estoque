const API_URL = "http://192.168.15.100/estoque/api/actions.php";

// =====================
// Função utilitária para requisições
// =====================
async function apiRequest(acao, dados = null, metodo = "GET") {
    let url = `${API_URL}?acao=${acao}`;
    let options = { method: metodo };

    if (dados && metodo === "POST") {
        const formData = new FormData();
        for (let key in dados) {
            formData.append(key, dados[key]);
        }
        options.body = formData;
    }

    try {
        const response = await fetch(url, options);
        return await response.json();
    } catch (error) {
        console.error("Erro na requisição:", error);
        return { sucesso: false, mensagem: "Erro na comunicação com o servidor" };
    }
}

// =====================
// Listar produtos
// =====================
async function carregarProdutos() {
    const tabela = document.getElementById("tabelaProdutos").querySelector("tbody");
    tabela.innerHTML = "<tr><td colspan='3'>Carregando...</td></tr>";

    const produtos = await apiRequest("listarprodutos");

    if (!produtos || produtos.length === 0) {
        tabela.innerHTML = "<tr><td colspan='3'>Nenhum produto encontrado</td></tr>";
        return;
    }

    tabela.innerHTML = "";
    produtos.forEach(p => {
        let tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${p.id}</td>
            <td>${p.nome}</td>
            <td>${p.quantidade}</td>
            <td>
                <button onclick="entradaProduto(${p.id})">Entrada</button>
                <button onclick="saidaProduto(${p.id})">Saída</button>
                <button onclick="removerProduto(${p.id})">Remover</button>
            </td>
        `;
        tabela.appendChild(tr);
    });
}

// =====================
// Listar movimentações
// =====================
async function carregarMovimentacoes() {
    const tabela = document.getElementById("tabelaMovimentacoes").querySelector("tbody");
    tabela.innerHTML = "<tr><td colspan='6'>Carregando...</td></tr>";

    const movs = await apiRequest("listarmovimentacoes");

    if (!movs || movs.length === 0) {
        tabela.innerHTML = "<tr><td colspan='6'>Nenhuma movimentação encontrada</td></tr>";
        return;
    }

    tabela.innerHTML = "";
    movs.forEach(m => {
        let tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${m.id}</td>
            <td>${m.produto_nome}</td>
            <td>${m.tipo}</td>
            <td>${m.quantidade}</td>
            <td>${m.data}</td>
            <td>${m.usuario} / ${m.responsavel}</td>
        `;
        tabela.appendChild(tr);
    });
}

// =====================
// Ações de produto
// =====================
async function adicionarProduto() {
    const nome = prompt("Digite o nome do produto:");
    const quantidade = prompt("Digite a quantidade inicial:");

    if (!nome || !quantidade) return;

    const res = await apiRequest("adicionar", { nome, quantidade }, "POST");
    alert(res.mensagem);
    carregarProdutos();
    carregarMovimentacoes();
}

async function entradaProduto(id) {
    const quantidade = prompt("Quantidade de entrada:");
    if (!quantidade) return;

    const res = await apiRequest("entrada", { id, quantidade }, "POST");
    alert(res.mensagem);
    carregarProdutos();
    carregarMovimentacoes();
}

async function saiaProduto(id) {
    const quantidade = prompt("Quantidade de saída:");
    if (!quantidade) return;

    const res = await apiRequest("saida", { id, quantidade }, "POST");
    alert(res.mensagem);
    carregarProdutos();
    carregarMovimentacoes();
}

async function removerProduto(id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;

    const res = await apiRequest("remover", { id }, "POST");
    alert(res.mensagem);
    carregarProdutos();
    carregarMovimentacoes();
}

// =====================
// Inicialização
// =====================
window.onload = function() {
    carregarProdutos();
    carregarMovimentacoes();

    document.getElementById("btnAdicionar").addEventListener("click", adicionarProduto);
};
