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
                <td>${mov.responsavel || "-"}</td>
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar movimentações:", err);
    }
}

// Evento de filtros
document.querySelector("#formFiltrosMovimentacoes")?.addEventListener("submit", function(e){
    e.preventDefault();
    const data_inicio = document.querySelector("#filtroDataInicio").value;
    const data_fim = document.querySelector("#filtroDataFim").value;
    const tipo = document.querySelector("#filtroTipo").value;

    listarMovimentacoes({
        data_inicio: data_inicio || undefined,
        data_fim: data_fim || undefined,
        tipo: tipo || undefined
    });
});