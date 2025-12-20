// js/main.js
import { logJsError, logJsInfo } from "./logger.js";

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");

    if (!resp?.sucesso || !resp.usuario) {
      logJsInfo({
        origem: "main.js",
        mensagem: "Usuário não autenticado, redirecionando para login"
      });

      window.location.href = "login.html";
      return null;
    }

    const usuario = resp.usuario;
    localStorage.setItem("usuario", JSON.stringify(usuario));
    return usuario;

  } catch (err) {
    console.error("Erro ao verificar login:", err);

    logJsError({
      origem: "main.js",
      mensagem: "Falha ao verificar sessão do usuário",
      detalhe: err.message,
      stack: err.stack
    });

    alert("Erro de conexão com o servidor.");
    return null;
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  try {
    const usuario = await verificarLogin();
    if (!usuario) return;

    logJsInfo({
      origem: "main.js",
      mensagem: "Usuário autenticado com sucesso",
      usuario: usuario.login || usuario.email || usuario.nome,
      nivel: usuario.nivel
    });

    // Exibe usuário no topo
    const usuarioSpan = document.getElementById("usuarioLogado");
    if (usuarioSpan) {
      usuarioSpan.textContent = `${usuario.nome} (${usuario.nivel})`;
    }

    // Página de produtos
    if (typeof window.listarProdutos === "function") {
      window.listarProdutos();
    }

    // Página de relatórios (placeholder inicial)
    const tabelaMovs = document.querySelector("#tabelaMovimentacoes tbody");
    if (tabelaMovs) {
      tabelaMovs.innerHTML = `
        <tr>
          <td colspan="6" class="text-center text-muted">
            Use os filtros para buscar movimentações
          </td>
        </tr>`;
    }

  } catch (err) {
    console.error("Erro na inicialização:", err);

    logJsError({
      origem: "main.js",
      mensagem: "Erro inesperado na inicialização da página",
      detalhe: err.message,
      stack: err.stack
    });
  }
});
