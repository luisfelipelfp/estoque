// Função para listar movimentações com filtros
async function listarMovimentacoes(filtros = {}) {
    try {
        const resp = await apiRequest("relatorio", filtros);
        let movimentacoes = Array.isArray(resp) ? resp : (resp?.dados || []);
        const tabela = document.querySelector("#tabelaMovimentacoes tbody");
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

// Evento do formulário de filtros
document.querySelector("#formFiltroMovs")?.addEventListener("submit", function(e) {
    e.preventDefault();

    const data_inicio = this.querySelector("[name='data_inicio']").value;
    const data_fim = this.querySelector("[name='data_fim']").value;
    const tipo = this.querySelector("[name='tipo']").value;
    const produto = this.querySelector("[name='produto']").value;

    listarMovimentacoes({
        data_inicio: data_inicio || undefined,
        data_fim: data_fim || undefined,
        tipo: tipo || undefined,
        produto: produto || undefined
    });
});

// Carregar movimentações ao iniciar
listarMovimentacoes();
