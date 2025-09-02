// Função para preencher filtro de produtos
async function preencherFiltroProdutos() {
    try {
        const produtos = await apiRequest("listarProdutos");
        const select = document.getElementById("filtroProduto");
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

// Função para listar movimentações
async function listarMovimentacoes(filtros = {}) {
    try {
        const movimentacoes = await apiRequest("listarMovimentacoes", filtros);
        const tabela = document.querySelector("#tabelaMovimentacoes tbody");
        tabela.innerHTML = "";

        if (movimentacoes.length === 0) {
            tabela.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted">Nenhuma movimentação encontrada</td>
                </tr>
            `;
            return;
        }

        movimentacoes.forEach(mov => {
            const row = `
                <tr>
                    <td>${mov.id}</td>
                    <td>${mov.produto_nome ?? "-"}</td>
                    <td>${mov.tipo}</td>
                    <td>${mov.quantidade ?? "-"}</td>
                    <td>${mov.data}</td>
                    <td>${mov.usuario ?? "-"}</td>
                </tr>
            `;
            tabela.innerHTML += row;
        });

    } catch (error) {
        console.error("Erro ao listar movimentações:", error);
    }
}

// Conectar formulário de filtros
function conectarFormFiltros() {
    const form = document.getElementById("formFiltrosMovimentacoes");
    form.addEventListener("submit", async (e) => {
        e.preventDefault();

        const filtros = {
            data_inicio: document.getElementById("filtroDataInicio").value,
            data_fim: document.getElementById("filtroDataFim").value,
            tipo: document.getElementById("filtroTipo").value,
            produto: document.getElementById("filtroProduto").value
        };

        await listarMovimentacoes(filtros);
    });
}

// Conectar formulário de movimentações (entrada/saída/remover via produtos.js)
function conectarFormMovimentacoes() {
    // Caso precise, pode ser expandido aqui futuramente
}

// Inicialização
(async function initMovimentacoesModule() {
    await preencherFiltroProdutos();
    conectarFormFiltros();
    conectarFormMovimentacoes();
    // Não chama listarMovimentacoes() automaticamente
})();
