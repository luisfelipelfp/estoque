// URL base da API (ajuste conforme seu servidor)
const API_URL = "http://192.168.15.100/estoque/api/actions.php";

let paginaAtual = 1;
let ultimaBusca = {};

// Função genérica para requisições à API
async function apiRequest(action, data = {}, method = "POST") {
    try {
        const options = {
            method,
            headers: { "Content-Type": "application/json" },
        };
        if (method === "POST") {
            options.body = JSON.stringify({ action, ...data });
        }
        const url = method === "GET"
            ? `${API_URL}?action=${action}&${new URLSearchParams(data)}`
            : API_URL;

        const resp = await fetch(url, options);
        return await resp.json();
    } catch (e) {
        console.error("Erro na requisição:", e);
        return { sucesso: false, erro: "Falha na conexão com API" };
    }
}

// Carregar produtos
async function listarProdutos() {
    const tabela = document.querySelector("#tabelaProdutos tbody");
    tabela.innerHTML = "<tr><td colspan='3'>Carregando...</td></tr>";

    const resp = await apiRequest("listarprodutos", {}, "GET");

    const selectFiltro = document.getElementById("filtroProduto");
    selectFiltro.innerHTML = "<option value=''>Todos</option>";

    if (!resp?.sucesso || !Array.isArray(resp.dados)) {
        tabela.innerHTML = "<tr><td colspan='3'>Erro ao carregar produtos</td></tr>";
        return;
    }

    tabela.innerHTML = "";
    resp.dados.forEach(prod => {
        // Preenche tabela
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${prod.id}</td>
            <td>${prod.nome}</td>
            <td>${prod.quantidade}</td>
        `;
        tabela.appendChild(tr);

        // Preenche select de filtro
        const opt = document.createElement("option");
        opt.value = prod.id;
        opt.textContent = prod.nome;
        selectFiltro.appendChild(opt);
    });
}

// Carregar movimentações
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
            <td>${m.responsavel || "-"}</td>
            <td>${m.data}</td>
        `;
        tabela.appendChild(tr);
    });

    // Paginação
    const pag = document.getElementById("paginacaoMovs");
    if (pag) {
        pag.innerHTML = `
            <button onclick="mudarPagina(-1)">Anterior</button>
            <span>Página ${paginaAtual}</span>
            <button onclick="mudarPagina(1)">Próxima</button>
        `;
    }
}

function mudarPagina(delta) {
    paginaAtual = Math.max(1, paginaAtual + delta);
    carregarMovimentacoes(ultimaBusca);
}

// Registrar entrada
async function entrada() {
    const produto_id = document.getElementById("movProduto").value;
    const quantidade = parseInt(document.getElementById("movQuantidade").value, 10);
    const responsavel = document.getElementById("movResponsavel").value;

    if (!produto_id || quantidade <= 0) {
        alert("Informe produto e quantidade válida");
        return;
    }

    const resp = await apiRequest("registrarmovimentacao", {
        produto_id,
        quantidade,
        tipo: "entrada",
        responsavel
    });

    if (resp.sucesso) {
        listarProdutos();
        carregarMovimentacoes();
    } else {
        alert(resp.erro || "Erro ao registrar entrada");
    }
}

// Registrar saída
async function saida() {
    const produto_id = document.getElementById("movProduto").value;
    const quantidade = parseInt(document.getElementById("movQuantidade").value, 10);
    const responsavel = document.getElementById("movResponsavel").value;

    if (!produto_id || quantidade <= 0) {
        alert("Informe produto e quantidade válida");
        return;
    }

    // Verifica estoque atual antes de enviar
    const respProdutos = await apiRequest("listarprodutos", {}, "GET");
    const prod = respProdutos.dados.find(p => p.id == produto_id);
    if (prod && quantidade > prod.quantidade) {
        alert("Estoque insuficiente!");
        return;
    }

    const resp = await apiRequest("registrarmovimentacao", {
        produto_id,
        quantidade,
        tipo: "saida",
        responsavel
    });

    if (resp.sucesso) {
        listarProdutos();
        carregarMovimentacoes();
    } else {
        alert(resp.erro || "Erro ao registrar saída");
    }
}

// Filtros
document.getElementById("filtroForm")?.addEventListener("submit", e => {
    e.preventDefault();
    const produto_id = document.getElementById("filtroProduto").value;
    const tipo = document.getElementById("filtroTipo").value;
    const data_inicio = document.getElementById("filtroDataInicio").value;
    const data_fim = document.getElementById("filtroDataFim").value;
    carregarMovimentacoes({ produto_id, tipo, data_inicio, data_fim });
});

// Inicialização
listarProdutos();
carregarMovimentacoes();
