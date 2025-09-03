// ==============================
// js/main.js (proteção de login + logout)
// ==============================

async function verificarLogin() {
    try {
        const response = await fetch("api/actions.php?acao=usuario_atual");
        const data = await response.json();

        if (!data.logado) {
            // Não logado → redireciona
            window.location.href = "login.html";
            return null;
        }

        // Usuário logado → guarda no localStorage
        localStorage.setItem("usuario", JSON.stringify(data.usuario));
        return data.usuario;
    } catch (err) {
        console.error("Erro ao verificar login:", err);
        alert("Erro de conexão com o servidor.");
        return null;
    }
}

async function logout() {
    try {
        await fetch("api/actions.php?acao=logout");
    } catch (err) {
        console.error("Erro ao deslogar:", err);
    } finally {
        localStorage.removeItem("usuario");
        window.location.href = "login.html";
    }
}

window.onload = async () => {
    try {
        // Primeiro verifica login
        const usuario = await verificarLogin();
        if (!usuario) return; // Se não logado, já foi redirecionado

        console.log("Usuário logado:", usuario.nome, "-", usuario.nivel);

        // Mostra usuário logado no header
        const usuarioSpan = document.getElementById("usuarioLogado");
        if (usuarioSpan) {
            usuarioSpan.textContent = `${usuario.nome} (${usuario.nivel})`;
        }

        // Configura botão de logout
        const btnLogout = document.getElementById("btnLogout");
        if (btnLogout) {
            btnLogout.addEventListener("click", logout);
        }

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
