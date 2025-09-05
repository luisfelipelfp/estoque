// ==============================
// js/movimentacoes.js (corrigido + global)
// ==============================

// Flag global: só lista após o usuário pesquisar
window._podeListarMovs = false;

// ==============================
// Mensagens auxiliares
// ==============================
function renderPlaceholderInicial() {
    const tbody = document.querySelector("#tabelaMovimentacoes tbody");
    if (!tbody) return;
    tbody.innerHTML = `
        <tr>
            <td colspan="6" class="text-center text-muted">
                Use os filtros acima para buscar movimentações
            </td>
        </tr>
    `;
}

function renderNenhumResultado() {
    const tbody = document.querySelector("#tabelaMovimentacoes tbody");
    if (!tbody) return;
    tbody.innerHTML = `
        <tr>
            <td colspan="6" class="text-center text-muted">
                Nenhuma movimentação encontrada
            </td>
        </tr>
    `;
}

// ==============================
// Carregar opções de produto no filtro
// ==============================
async function preencherFiltroProdutos() {
    try {
        const resp = await apiRequest("listarprodutos"); // 👈 padronizado em minúsculo
        const produtos = Array.isArray(resp) ? resp : (resp?.dados || []);
        const select = document.getElementById("filtroProduto");
        if (!select) return;

        select.innerHTML = '<option value="">Todos os Produtos</option>';
        produtos.forEach(produto => {
            const option = document.createElement("option");
            option.value = produto.id;
            option.textContent = produto.nome;
            select.appendChild(option);
        });
    } catch (error) {
        console.error("Erro ao carregar produtos no filtro:", error);
    }
}

// ==============================
// Listagem de movimentações
// ==============================
async function listarMovimentacoes(filtros = {}, force = false) {
    try {
        // Só lista após pesquisa ou se for forçado
        if (!force && !window._podeListarMovs) {
            renderPlaceholderInicial();
            return;
        }

        // Normaliza filtros
        const params = { ...filtros };

        if (params.produto && !params.produto_id) {
            if (!isNaN(params.produto) && params.produto !== "") {
                params.produto_id = params.produto;
            }
            delete params.produto;
        }

        // Remove chaves vazias
        Object.keys(params).forEach(k => {
            if (params[k] === "" || params[k] === null || params[k] === undefined) {
                delete params[k];
            }
        });

        // 👇 corrigido: usar sempre minúsculo
        const resposta = await apiRequest("listarmovimentacoes", params, "GET");
        const movimentacoes = Array.isArray(resposta) ? resposta : (resposta?.dados || []);

        const tabela = document.querySelector("#tabelaMovimentacoes tbody");
        if (!tabela) return;
        tabela.innerHTML = "";

        if (!Array.isArray(movimentacoes) || movimentacoes.length === 0) {
            renderNenhumResultado();
            return;
        }

        movimentacoes.forEach(mov => {
            // Usa diretamente o campo "usuario" que vem do banco
            let usuario = mov.usuario && mov.usuario.trim() !== "" 
                ? mov.usuario 
                : "Sistema";

            const row = `
                <tr>
                    <td>${mov.id}</td>
                    <td>${mov.produto_nome ?? "-"}</td>
                    <td>${mov.tipo}</td>
                    <td>${mov.quantidade ?? "-"}</td>
                    <td>${mov.data}</td>
                    <td>${usuario}</td>
                </tr>
            `;
            tabela.insertAdjacentHTML("beforeend", row);
        });

    } catch (error) {
        console.error("Erro ao listar movimentações:", error);
        renderNenhumResultado();
    }
}

// 🔑 Expondo a função globalmente (assim como no produtos.js)
window.listarMovimentacoes = listarMovimentacoes;

// ==============================
// Conexão do formulário de filtros
// ==============================
function conectarFormFiltros() {
    const form = document.getElementById("formFiltrosMovimentacoes");
    if (!form) return;

    form.addEventListener("submit", async (e) => {
        e.preventDefault();

        // Marca que já pode listar
        window._podeListarMovs = true;

        const filtros = {
            data_inicio: document.getElementById("filtroDataInicio").value,
            data_fim: document.getElementById("filtroDataFim").value,
            tipo: document.getElementById("filtroTipo").value,
            produto: document.getElementById("filtroProduto").value
        };

        await listarMovimentacoes(filtros, true); // força a busca
    });
}

// Mantido para evoluções futuras (entrada/saída via produtos.js)
function conectarFormMovimentacoes() {
    // vazio por enquanto
}

// ==============================
// Inicialização
// ==============================
(async function initMovimentacoesModule() {
    await preencherFiltroProdutos();
    conectarFormFiltros();
    conectarFormMovimentacoes();

    // Tabela começa com mensagem inicial
    renderPlaceholderInicial();
})();
