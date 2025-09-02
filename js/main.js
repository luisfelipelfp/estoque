// ==============================
// js/main.js (ajustado)
// ==============================

window.onload = () => {
    try {
        // Continua carregando os produtos logo ao abrir
        if (typeof listarProdutos === "function") {
            listarProdutos();
        } else {
            console.warn("Função listarProdutos não encontrada.");
        }

        // Não chama listarMovimentacoes() na inicialização
        // Agora as movimentações só aparecem após o usuário aplicar os filtros
        if (typeof renderPlaceholderInicial === "function") {
            renderPlaceholderInicial(); // mostra mensagem inicial na tabela
        }
    } catch (error) {
        console.error("Erro durante inicialização da página:", error);
    }
};
