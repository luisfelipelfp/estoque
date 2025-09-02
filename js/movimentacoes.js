// movimentacoes.js

async function listarMovimentacoes(filtros = {}) {
    try {
        const resposta = await apiRequest("relatorio", filtros);
        const movimentacoes = resposta.dados || []; // garante que seja array

        const tabela = document.querySelector("#tabelaMovimentacoes tbody");
        tabela.innerHTML = "";

        if (movimentacoes.length === 0) {
            tabela.innerHTML = `
                <tr>
                    <td colspan="7" class="text-muted">Nenhuma movimentação encontrada.</td>
                </tr>`;
            return;
        }

        movimentacoes.forEach(m => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${m.id}</td>
                <td>${m.produto_nome || "-"}</td>
                <td>${m.tipo}</td>
                <td>${m.quantidade}</td>
                <td>${m.data}</td>
                <td>${m.usuario}</td>
                <td>${m.responsavel || "-"}</td>
            `;
            tabela.appendChild(tr);
        });

    } catch (err) {
        console.error("Erro ao listar movimentações:", err);
    }
}

// Inicializa a tabela de movimentações como vazia
document.addEventListener("DOMContentLoaded", () => {
    const tabela = document.querySelector("#tabelaMovimentacoes tbody");
    tabela.innerHTML = `
        <tr>
            <td colspan="7" class="text-muted">Use os filtros acima para buscar movimentações.</td>
        </tr>`;
});

// Evento do formulário de filtros
document.getElementById("formFiltrosMovimentacoes").addEventListener("submit", async (e) => {
    e.preventDefault();

    const filtros = {
        data_inicio: document.getElementById("filtroDataInicio").value,
        data_fim: document.getElementById("filtroDataFim").value,
        tipo: document.getElementById("filtroTipo").value,
        produto: document.getElementById("filtroProduto").value,
    };

    await listarMovimentacoes(filtros);
});
