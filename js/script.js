const API_URL = "http://192.168.15.100/estoque/api/actions.php";

async function apiRequest(acao, dados = null, metodo = "GET") {
    let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;
    let options = { method: metodo };

    if (metodo === "GET" && dados) {
        const query = new URLSearchParams(dados).toString();
        url += "&" + query;
    }

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
        return await response.json();
    } catch (error) {
        console.error("Erro na requisição:", error);
        return { sucesso: false, mensagem: "Erro na comunicação com o servidor" };
    }
}

// -------------------- PRODUTOS --------------------

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
        if (Number(p.quantidade) <= 2) tr.classList.add("estoque-baixo");

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

async function adicionarProduto(nome, quantidade = 0) {
    const resp = await apiRequest("adicionarproduto", { nome, quantidade }, "POST");
    if (resp.sucesso) {
        carregarProdutos();
    } else {
        alert(resp.mensagem || "Erro ao adicionar produto");
    }
}

async function entradaProduto(id) {
    const qtd = prompt("Quantidade de entrada:");
    if (!qtd || isNaN(qtd)) return;
    const resp = await apiRequest("entrada", { id, quantidade: qtd }, "POST");
    if (resp.sucesso) {
        carregarProdutos();
        carregarMovimentacoes();
    } else {
        alert(resp.mensagem || "Erro na entrada de produto");
    }
}

async function saidaProduto(id) {
    const qtd = prompt("Quantidade de saída:");
    if (!qtd || isNaN(qtd)) return;
    const resp = await apiRequest("saida", { id, quantidade: qtd }, "POST");
    if (resp.sucesso) {
        carregarProdutos();
        carregarMovimentacoes();
    } else {
        alert(resp.mensagem || "Erro na saída de produto");
    }
}

async function removerProduto(id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;
    const resp = await apiRequest("remover", { id }, "POST");
    if (resp.sucesso) {
        carregarProdutos();
        carregarMovimentacoes();
    } else {
        alert(resp.mensagem || "Erro ao remover produto");
    }
}

// -------------------- MOVIMENTAÇÕES --------------------

let paginaAtual = 1;
let ultimaBusca = {};

async function carregarMovimentacoes(filtros = {}) {
    const tabela = document.getElementById("tabelaMovimentacoes").querySelector("tbody");
    tabela.innerHTML = "<tr><td colspan='6'>Carregando...</td></tr>";

    ultimaBusca = { ...filtros, pagina: paginaAtual, limite: 10 };
    const resp = await apiRequest("listarmovimentacoes", ultimaBusca, "GET");

    if (!resp.sucesso || !Array.isArray(resp.dados) || resp.dados.length === 0) {
        tabela.innerHTML = "<tr><td colspan='6'>Nenhuma movimentação encontrada</td></tr>";
        return;
    }

    tabela.innerHTML = "";
    resp.dados.forEach(m => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${m.id}</td>
            <td>${m.produto_nome || "-"}</td>
            <td>${m.tipo}</td>
            <td>${m.quantidade}</td>
            <td>${m.data}</td>
            <td>${m.usuario || "-"}</td>
        `;
        tabela.appendChild(tr);
    });

    const paginacao = document.getElementById("paginacaoMovs");
    paginacao.innerHTML = `
        <button class="btn btn-sm btn-secondary" ${paginaAtual <= 1 ? "disabled" : ""} 
            onclick="paginaAtual--; carregarMovimentacoes(ultimaBusca)">Anterior</button>
        <span class="mx-2">Página ${resp.pagina} de ${resp.paginas}</span>
        <button class="btn btn-sm btn-secondary" ${paginaAtual >= resp.paginas ? "disabled" : ""} 
            onclick="paginaAtual++; carregarMovimentacoes(ultimaBusca)">Próxima</button>
    `;
}

// -------------------- INIT --------------------

window.onload = function () {
    carregarProdutos();
    carregarMovimentacoes();

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

    const formFiltro = document.getElementById("formFiltroMovs");
    if (formFiltro) {
        formFiltro.addEventListener("submit", function (e) {
            e.preventDefault();
            paginaAtual = 1;
            const filtros = Object.fromEntries(new FormData(formFiltro).entries());
            carregarMovimentacoes(filtros);
        });
    }
};
