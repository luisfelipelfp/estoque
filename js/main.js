// ==============================
// js/main.js (proteção de login + logout + inicialização)
// ==============================

async function verificarLogin() {
    try {
        const response = await fetch("api/actions.php?acao=usuario_atual", {
            credentials: "include" // garante que cookies/sessão PHP sejam enviados
        });
        const data = await response.json();

        if (!data.logado) {
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
        await fetch("api/actions.php?acao=logout", {
            credentials: "include"
        });
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

        // Carregar lista de produtos
        if (typeof window.listarProdutos === "function") {
            window.listarProdutos();
        } else if (typeof window.carregarProdutos === "function") {
            window.carregarProdutos();
        } else {
            console.warn("Nenhuma função para listar produtos encontrada (listarProdutos ou carregarProdutos).");
        }

        // Renderiza placeholder inicial das movimentações (sem carregar nada ainda)
        const tabelaMovs = document.querySelector("#tabelaMovimentacoes tbody");
        if (tabelaMovs) {
            tabelaMovs.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted">
                        Use os filtros para buscar movimentações
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        console.error("Erro durante inicialização da página:", error);
    }
};
