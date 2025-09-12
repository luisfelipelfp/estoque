// js/main.js (proteção de login + inicialização)

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");
    if (!resp?.sucesso || !resp.usuario) {
      window.location.href = "login.html";
      return null;
    }

    const usuario = resp.usuario;
    localStorage.setItem("usuario", JSON.stringify(usuario));
    return usuario;
  } catch (err) {
    console.error("Erro ao verificar login:", err);
    alert("Erro de conexão com o servidor.");
    return null;
  }
}

window.onload = async () => {
  try {
    const usuario = await verificarLogin();
    if (!usuario) return;

    console.log("Usuário logado:", usuario.nome, "-", usuario.nivel);

    const usuarioSpan = document.getElementById("usuarioLogado");
    if (usuarioSpan) {
      usuarioSpan.textContent = `${usuario.nome} (${usuario.nivel})`;
    }

    if (typeof window.listarProdutos === "function") {
      window.listarProdutos();
    }

    const tabelaMovs = document.querySelector("#tabelaMovimentacoes tbody");
    if (tabelaMovs) {
      tabelaMovs.innerHTML = `
        <tr>
          <td colspan="6" class="text-center text-muted">
            Use os filtros para buscar movimentações
          </td>
        </tr>`;
    }
  } catch (error) {
    console.error("Erro durante inicialização da página:", error);
  }
};
