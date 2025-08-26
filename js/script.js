const API_URL = "http://192.168.15.100/estoque/api/actions.php";

// =====================
// Função utilitária para requisições
// =====================
async function apiRequest(acao, dados = null, metodo = "GET") {
    let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;
    let options = { method: metodo };

    if (dados && metodo === "POST") {
        const formData = new FormData();
        for (let key in dados) {
            if (dados[key] !== undefined && dados[key] !== null) {
                formData.append(key, dados[key]);
            }
        }
        options.body = formData;
    }

    try {
        const response = await fetch(url, options);
        // Pode retornar um array (listarprodutos/listarmovimentacoes) ou objeto {sucesso,...}
        const ct = response.headers.get("content-type") || "";
        if (!ct.includes("application/json")) {
            // tenta ler texto para debugar
            const texto = await response.text();
            console.error("Resposta não-JSON:", texto);
            return { sucesso: false, mensagem: "Resposta inesperada do servidor" };
        }
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
    tabela.innerHTML = "<tr><td colspan='4'>Carregando...</td></tr>";

    const produtos = await apiRequest("listarprodutos");

    if (!Array.isArray(produtos) || produtos.length === 0) {
        tabela.innerHTML = "<tr><td colspan='4'>Nenhum produto encontrado</td></tr>";
        return;
    }

    tabela.innerHTML = "";
    produtos.forEach(p => {
        const tr = document.createElement("tr");

        // destaque se estoque for baixo
        if (Number(p.quantidade) <= 2) {
            tr.classList.add("estoque-baixo");
        }

        tr.innerHTML = `
            <td>${p.id}</td>
            <td>${p.nome}</td>
            <td>${p.quantidade}</td>
            <td>
                <button class="btn btn-success btn-sm" onclick="entradaProduto(${p.id})">Entrada</button>
                <button class="btn btn-warning btn-sm" onclick="saidaProduto(${p.id})">Saída</button>
                <button class="btn btn-danger btn-sm" onclick="removerProduto(${p.id})">Remover</button>
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
    tabela.innerHTML = "<tr><td colspan='5'>Carregando...</td></tr>";

    const movs = await apiRequest("listarmovimentacoes");

    if (!Array.isArray(movs) || movs.length === 0) {
        tabela.innerHTML = "<tr><td colspan='5'>Nenhuma movimentação encontrada</td></tr>";
        return;
    }

    tabela.innerHTML = "";
    movs.forEach(m => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${m.id}</td>
            <td>${m.produto_nome}</td>
            <td>${m.tipo}</td>
            <td>${m.quantidade}</td>
            <td>${m.data}</td>
        `;
        tabela.appendChild(tr);
    });
}

// =====================
// Ações de produto
// =====================
async function adicionarProduto(nome, quantidade = 0) {
    if (!nome) return;

    const res = await apiRequest("adicionar", { nome, quantidade }, "POST");
    alert(res.mensagem || (res.sucesso ? "OK" : "Erro ao adicionar"));
    carregarProdutos();
    carregarMovimentacoes();
}

async function entradaProduto(id) {
    const quantidade = prompt("Quantidade de entrada:");
    if (!quantidade) return;

    const qtd = parseInt(quantidade, 10);
    if (isNaN(qtd) || qtd <= 0) {
        alert("Informe uma quantidade válida.");
        return;
    }

    const res = await apiRequest("entrada", { id, quantidade: qtd }, "POST");
    alert(res.mensagem || (res.sucesso ? "OK" : "Erro ao registrar entrada"));
    carregarProdutos();
    carregarMovimentacoes();
}

async function saidaProduto(id) {
    const quantidade = prompt("Quantidade de saída:");
    if (!quantidade) return;

    const qtd = parseInt(quantidade, 10);
    if (isNaN(qtd) || qtd <= 0) {
        alert("Informe uma quantidade válida.");
        return;
    }

    const res = await apiRequest("saida", { id, quantidade: qtd }, "POST");
    alert(res.mensagem || (res.sucesso ? "OK" : "Erro ao registrar saída"));
    carregarProdutos();
    carregarMovimentacoes();
}

async function removerProduto(id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;

    const res = await apiRequest("remover", { id }, "POST");
    alert(res.mensagem || (res.sucesso ? "OK" : "Erro ao remover"));
    carregarProdutos();
    carregarMovimentacoes();
}

// =====================
// Inicialização
// =====================
window.onload = function () {
    carregarProdutos();
    carregarMovimentacoes();

    // evento no formulário de adicionar produto
    const form = document.getElementById("formAdicionarProduto");
    if (form) {
        form.addEventListener("submit", async function (e) {
            e.preventDefault();
            const nome = document.getElementById("nomeProduto").value.trim();
            if (nome) {
                await adicionarProduto(nome, 0);
                this.reset();
            }
        });
    }
};
