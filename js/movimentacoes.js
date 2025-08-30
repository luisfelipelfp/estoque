// js/movimentacoes.js

// Função que preenche o select do filtro com produtos buscados da API
async function preencherFiltroProdutos() {
    try {
        const resp = await apiRequest("listar_produtos");
        const produtos = Array.isArray(resp) ? resp : (resp?.dados || []);

        const select = document.querySelector("#filtroProduto") || document.querySelector("select[name='produto']");
        if (!select) return;

        const current = select.value; // guarda seleção atual

        select.innerHTML = "";
        select.appendChild(new Option("Todos os Produtos", ""));

        produtos.forEach(p => {
            const opt = new Option(p.nome, p.id);
            if (String(p.id) === String(current)) opt.selected = true;
            select.appendChild(opt);
        });
    } catch (err) {
        console.error("Erro ao preencher filtro de produtos:", err);
    }
}

// Função que lista movimentações usando filtros
async function listarMovimentacoes(filtros = {}) {
    try {
        const params = { ...filtros };

        // Se "produto" veio, transforma em produto_id
        if (params.produto && !params.produto_id) {
            if (!isNaN(params.produto) && params.produto !== "") {
                params.produto_id = params.produto;
            }
            delete params.produto;
        }

        // Remove chaves inválidas
        Object.keys(params).forEach(k => {
            if (!params[k]) delete params[k];
        });

        const resp = await apiRequest("relatorio", params, "GET");
        const movimentacoes = Array.isArray(resp) ? resp : (resp?.dados || []);

        const tabela = document.querySelector("#tabelaMovimentacoes tbody");
        if (!tabela) return;
        tabela.innerHTML = "";

        if (movimentacoes.length === 0) {
            const tr = document.createElement("tr");
            tr.innerHTML = `<td colspan="6" class="text-center">Nenhuma movimentação encontrada</td>`;
            tabela.appendChild(tr);
            return;
        }

        movimentacoes.forEach(mov => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${mov.id}</td>
                <td>${mov.produto_nome || "-"}</td>
                <td>${mov.tipo}</td>
                <td>${mov.quantidade}</td>
                <td>${mov.data}</td>
                <td>${mov.usuario || "-"}</td>
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar movimentações:", err);
    }
}

// Lê valor de campo de formulário
function lerCampoDoForm(form, keys = []) {
    for (const key of keys) {
        const byId = form.querySelector(`#${key}`);
        if (byId) return byId.value;
        const byName = form.querySelector(`[name="${key}"]`);
        if (byName) return byName.value;
    }
    return "";
}

// Conecta formulário de filtros
function conectarFormFiltros() {
    const idsPossiveis = ["formFiltrosMovimentacoes", "formFiltroMovs", "formFiltrosMovs"];
    let form = idsPossiveis.map(id => document.querySelector(`#${id}`)).find(f => f);

    if (!form) {
        const possibleForm = document.querySelector("form");
        if (possibleForm && (possibleForm.querySelector("#filtroProduto") || possibleForm.querySelector("[name='produto']"))) {
            form = possibleForm;
        }
    }
    if (!form) return;

    form.addEventListener("submit", function (e) {
        e.preventDefault();

        const filtros = {
            data_inicio: lerCampoDoForm(form, ["filtroDataInicio", "data_inicio"]) || undefined,
            data_fim: lerCampoDoForm(form, ["filtroDataFim", "data_fim"]) || undefined,
            tipo: lerCampoDoForm(form, ["filtroTipo", "tipo"]) || undefined,
            produto: lerCampoDoForm(form, ["filtroProduto", "produto"]) || undefined
        };

        listarMovimentacoes(filtros);
    });
}

// Inicialização
(async function initMovimentacoesModule() {
    await preencherFiltroProdutos();
    conectarFormFiltros();
    listarMovimentacoes();
})();
