// ===============================
// Função genérica para chamar API
// ===============================
async function apiRequest(acao, dados = {}) {
    try {
        const resp = await fetch(`api/actions.php?acao=${acao}`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: Object.keys(dados).length > 0 ? JSON.stringify(dados) : null
        });

        if (!resp.ok) {
            throw new Error(`Erro HTTP ${resp.status}`);
        }

        const json = await resp.json();
        if (json.erro) {
            throw new Error(json.erro);
        }
        return json;
    } catch (e) {
        console.error("Erro na API:", e);
        throw e;
    }
}

// ===============================
// Listar Produtos
// ===============================
async function listarProdutos() {
    try {
        const res = await apiRequest("listarprodutos");
        if (res.sucesso) {
            const tabela = document.getElementById("tabelaProdutos").querySelector("tbody");
            tabela.innerHTML = "";
            res.dados.forEach(p => {
                const tr = document.createElement("tr");
                tr.innerHTML = `
                    <td>${p.id}</td>
                    <td>${p.nome}</td>
                    <td>${p.quantidade}</td>
                    <td>
                        <button onclick="entradaProduto(${p.id})">Entrada</button>
                        <button onclick="saidaProduto(${p.id})">Saída</button>
                        <button onclick="removerProduto(${p.id})">Remover</button>
                    </td>`;
                tabela.appendChild(tr);
            });
        }
    } catch (e) {
        console.error("Erro ao listar produtos");
    }
}

// ===============================
// Listar Movimentações
// ===============================
async function listarMovimentacoes(filtros = {}) {
    try {
        const res = await apiRequest("listarmovimentacoes", filtros);
        if (res.sucesso) {
            const tabela = document.getElementById("tabelaMovimentacoes").querySelector("tbody");
            tabela.innerHTML = "";
            res.dados.forEach(m => {
                const tr = document.createElement("tr");
                tr.innerHTML = `
                    <td>${m.id}</td>
                    <td>${m.produto_nome ?? "(Removido)"}</td>
                    <td>${m.tipo}</td>
                    <td>${m.quantidade}</td>
                    <td>${m.data}</td>`;
                tabela.appendChild(tr);
            });
        }
    } catch (e) {
        console.error("Erro ao listar movimentações");
    }
}

// ===============================
// Operações: Entrada / Saída / Remover
// ===============================
async function entradaProduto(id) {
    const qtd = prompt("Quantidade de entrada:");
    if (!qtd) return;
    await apiRequest("entrada", { id: id, quantidade: parseInt(qtd) });
    listarProdutos();
    listarMovimentacoes();
}

async function saidaProduto(id) {
    const qtd = prompt("Quantidade de saída:");
    if (!qtd) return;
    await apiRequest("saida", { id: id, quantidade: parseInt(qtd) });
    listarProdutos();
    listarMovimentacoes();
}

async function removerProduto(id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;
    await apiRequest("remover", { id: id });
    listarProdutos();
    listarMovimentacoes();
}

// ===============================
// Cadastro de novo produto
// ===============================
async function cadastrarProduto() {
    const nome = document.getElementById("nome").value;
    const qtd = document.getElementById("quantidade").value;
    if (!nome) {
        alert("Nome é obrigatório!");
        return;
    }
    await apiRequest("adicionar", { nome: nome, quantidade: parseInt(qtd) || 0 });
    listarProdutos();
}

// ===============================
// Inicialização
// ===============================
document.addEventListener("DOMContentLoaded", () => {
    listarProdutos();
    listarMovimentacoes();

    document.getElementById("formCadastro")?.addEventListener("submit", async e => {
        e.preventDefault();
        cadastrarProduto();
    });

    document.getElementById("formFiltroMov")?.addEventListener("submit", async e => {
        e.preventDefault();
        const filtros = {
            produto: document.getElementById("filtroProduto").value,
            tipo: document.getElementById("filtroTipo").value,
            de: document.getElementById("filtroDe").value,
            ate: document.getElementById("filtroAte").value
        };
        listarMovimentacoes(filtros);
    });
});
