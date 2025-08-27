// js/script.js
const API_URL = "http://192.168.15.100/estoque/api/actions.php";

/**
 * Faz requisição à API.
 */
async function apiRequest(acao, dados = {}, metodo = "GET") {
    let url = API_URL;
    const options = { method: metodo, headers: {} };

    if (metodo === "GET") {
        const query = new URLSearchParams({ acao, ...dados }).toString();
        url += "?" + query;
    } else {
        options.headers["Content-Type"] = "application/json";
        options.body = JSON.stringify({ acao, ...dados });
    }

    try {
        const response = await fetch(url, options);
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch {
            console.error("Resposta inválida:", text);
            return { sucesso: false, mensagem: "Resposta inválida do servidor" };
        }
    } catch (error) {
        console.error("Erro na requisição:", error);
        return { sucesso: false, mensagem: "Erro de comunicação com o servidor" };
    }
}

// -------------------- PRODUTOS --------------------

async function carregarProdutos() {
    const tbody = document.querySelector("#tabelaProdutos tbody");
    tbody.innerHTML = "<tr><td colspan='4'>Carregando...</td></tr>";

    const resp = await apiRequest("listarprodutos", {}, "GET");
    let produtos = Array.isArray(resp) ? resp : (resp?.dados || []);

    if (!produtos.length) {
        tbody.innerHTML = "<tr><td colspan='4'>Nenhum produto encontrado</td></tr>";
        return;
    }

    tbody.innerHTML = "";
    produtos.forEach(p => {
        const tr = document.createElement("tr");
        if (Number(p.quantidade) <= 2) tr.classList.add("estoque-baixo");

        const btnEntrada = `<button class="btn btn-success btn-sm" data-acao="entrada" data-id="${p.id}">Entrada</button>`;
        const btnSaida   = `<button class="btn btn-warning btn-sm" data-acao="saida" data-id="${p.id}">Saída</button>`;
        const btnRemover = `<button class="btn btn-danger btn-sm" data-acao="remover" data-id="${p.id}">Remover</button>`;

        tr.innerHTML = `
            <td>${p.id}</td>
            <td>${p.nome}</td>
            <td>${p.quantidade}</td>
            <td>${btnEntrada} ${btnSaida} ${btnRemover}</td>
        `;
        tbody.appendChild(tr);
    });

    // Eventos dos botões
    tbody.querySelectorAll("button[data-acao]").forEach(btn => {
        btn.addEventListener("click", () => {
            const id = btn.dataset.id;
            if (btn.dataset.acao === "entrada") entradaProduto(id);
            if (btn.dataset.acao === "saida")   saidaProduto(id);
            if (btn.dataset.acao === "remover") removerProduto(id);
        });
    });

    // Atualiza o filtro de produtos com os nomes carregados
    atualizarFiltroProdutos(produtos);
}

async function adicionarProduto(nome, quantidade = 0) {
    const resp = await apiRequest("adicionar", { nome, quantidade }, "POST");
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
        carregarMovimentacoes(ultimaBusca);
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
        carregarMovimentacoes(ultimaBusca);
    } else {
        alert(resp.mensagem || "Erro na saída de produto");
    }
}

async function removerProduto(id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;
    const resp = await apiRequest("remover", { id }, "POST");
    if (resp.sucesso) {
        carregarProdutos();
        carregarMovimentacoes(ultimaBusca);
    } else {
        alert(resp.mensagem || "Erro ao remover produto");
    }
}

// -------------------- MOVIMENTAÇÕES --------------------

let paginaAtual = 1;
let ultimaBusca = {};

async function carregarMovimentacoes(filtros = {}) {
    const tabela = document.querySelector("#tabelaMovimentacoes tbody");
    tabela.innerHTML = "<tr><td colspan='6'>Carregando...</td></tr>";

    ultimaBusca = { ...filtros, pagina: paginaAtual, limite: 10 };
    const resp = await apiRequest("listarmovimentacoes", ultimaBusca, "GET");

    if (!resp?.sucesso || !Array.isArray(resp.dados) || !resp.dados.length) {
        tabela.innerHTML = "<tr><td colspan='6'>Nenhuma movimentação encontrada</td></tr>";
        document.getElementById("paginacaoMovs")?.innerHTML = "";
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
    if (paginacao) {
        paginacao.innerHTML = `
            <button id="btnAnterior" class="btn btn-sm btn-secondary" ${paginaAtual <= 1 ? "disabled" : ""}>Anterior</button>
            <span class="mx-2">Página ${resp.pagina} de ${resp.paginas}</span>
            <button id="btnProxima" class="btn btn-sm btn-secondary" ${paginaAtual >= resp.paginas ? "disabled" : ""}>Próxima</button>
        `;

        paginacao.querySelector("#btnAnterior")?.addEventListener("click", () => {
            if (paginaAtual > 1) {
                paginaAtual--;
                carregarMovimentacoes(ultimaBusca);
            }
        });
        paginacao.querySelector("#btnProxima")?.addEventListener("click", () => {
            if (paginaAtual < resp.paginas) {
                paginaAtual++;
                carregarMovimentacoes(ultimaBusca);
            }
        });
    }
}

// -------------------- FILTRO DE PRODUTOS --------------------

function atualizarFiltroProdutos(produtos) {
    const select = document.getElementById("filtroProduto");
    if (!select) return;

    select.innerHTML = `<option value="">Todos os produtos</option>`;
    produtos.forEach(p => {
        const opt = document.createElement("option");
        opt.value = p.id;
        opt.textContent = p.nome;
        select.appendChild(opt);
    });
}

// -------------------- INIT --------------------

window.addEventListener("DOMContentLoaded", () => {
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
});
