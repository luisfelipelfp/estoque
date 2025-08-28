// js/movimentacoes.js

// Função que preenche o select do filtro com produtos buscados da API
async function preencherFiltroProdutos() {
    try {
        const resp = await apiRequest("listar_produtos");
        // resp pode ser array ou { dados: [...] }
        const produtos = Array.isArray(resp) ? resp : (resp?.dados || []);

        // Tenta encontrar o select por id ou por name
        const selectById = document.querySelector("#filtroProduto");
        const selectByName = document.querySelector("select[name='produto']");
        const select = selectById || selectByName;

        if (!select) return; // nada pra preencher

        // guarda valor selecionado para não perder seleção ao atualizar
        const current = select.value;

        // limpa
        select.innerHTML = "";
        const optTodos = document.createElement("option");
        optTodos.value = "";
        optTodos.textContent = "Todos os Produtos";
        select.appendChild(optTodos);

        produtos.forEach(p => {
            const opt = document.createElement("option");
            opt.value = p.id;
            opt.textContent = p.nome;
            if (String(p.id) === String(current)) opt.selected = true;
            select.appendChild(opt);
        });
    } catch (err) {
        console.error("Erro ao preencher filtro de produtos:", err);
    }
}

// Função que lista movimentações usando filtros
// filtros: { data_inicio, data_fim, tipo, produto_id } (produto_id opcional)
async function listarMovimentacoes(filtros = {}) {
    try {
        // Se usuário passou 'produto' (nome ou id), transformamos em produto_id para API
        const params = { ...filtros };

        // se o filtro vier como 'produto' (ex: select name='produto'), renomeia
        if (params.produto && !params.produto_id) {
            // se veio como id (numérico), envia como produto_id; senão envia como produto (nome)
            if (!isNaN(params.produto) && params.produto !== "") {
                params.produto_id = params.produto;
                delete params.produto;
            }
        }

        // remove chaves undefined/"" para não poluir a querystring
        Object.keys(params).forEach(k => {
            if (params[k] === undefined || params[k] === "" || params[k] === null) delete params[k];
        });

        // chama a API (usar "relatorio" que já implementamos no backend)
        const resp = await apiRequest("relatorio", params, "GET");
        const movimentacoes = Array.isArray(resp) ? resp : (resp?.dados || []);

        const tabela = document.querySelector("#tabelaMovimentacoes tbody");
        if (!tabela) return;
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
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar movimentações:", err);
    }
}

// Helper: lê valor de campo do formulário por várias estratégias (id ou name)
function lerCampoDoForm(form, idOrNameVariants = []) {
    for (const key of idOrNameVariants) {
        const byId = form.querySelector(`#${key}`);
        if (byId) return byId.value;
        const byName = form.querySelector(`[name="${key}"]`);
        if (byName) return byName.value;
    }
    return "";
}

// Conecta o submit do(s) formulário(s) de filtros (suporta id diferente)
function conectarFormFiltros() {
    const idsPossiveis = ["formFiltrosMovimentacoes", "formFiltroMovs", "formFiltrosMovs"];
    let form = null;
    for (const id of idsPossiveis) {
        form = document.querySelector(`#${id}`);
        if (form) break;
    }
    // se não encontrou por id, tenta encontrar qualquer form que contenha o select #filtroProduto
    if (!form) {
        const possibleForm = document.querySelector("form");
        if (possibleForm && (possibleForm.querySelector("#filtroProduto") || possibleForm.querySelector("[name='produto']"))) {
            form = possibleForm;
        }
    }
    if (!form) return;

    form.addEventListener("submit", function (e) {
        e.preventDefault();

        // Tenta ler usando nomes/ids comuns
        const data_inicio = lerCampoDoForm(form, ["filtroDataInicio", "data_inicio"]);
        const data_fim = lerCampoDoForm(form, ["filtroDataFim", "data_fim"]);
        const tipo = lerCampoDoForm(form, ["filtroTipo", "tipo"]);
        const produto = lerCampoDoForm(form, ["filtroProduto", "produto"]);

        const filtros = {
            data_inicio: data_inicio || undefined,
            data_fim: data_fim || undefined,
            tipo: tipo || undefined,
            produto: produto || undefined
        };

        listarMovimentacoes(filtros);
    });
}

// Inicialização: preencher select e ligar listeners
(async function initMovimentacoesModule() {
    await preencherFiltroProdutos();    // preenche select com produtos
    conectarFormFiltros();              // conecta o(s) formulário(s) de filtros
    listarMovimentacoes();              // lista inicial (sem filtros)
})();
