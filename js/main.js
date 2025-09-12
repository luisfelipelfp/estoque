// js/main.js (proteção de login + logout + inicialização)

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");
    if (!resp?.sucesso || !resp.usuario) {
      window.location.href = "login.html";
      return null;
    }

    const usuario = resp.usuario; // ✅ corrigido: não existe resp.dados.usuario
    localStorage.setItem("usuario", JSON.stringify(usuario));
    return usuario;
  } catch (err) {
    console.error("Erro ao verificar login:", err);
    alert("Erro de conexão com o servidor.");
    return null;
  }
}

async function logout() {
  try {
    await apiRequest("logout", null, "POST");
  } catch (err) {
    console.error("Erro ao deslogar:", err);
  } finally {
    localStorage.removeItem("usuario");
    window.location.href = "login.html";
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

    const btnLogout = document.getElementById("btnLogout");
    if (btnLogout) {
      btnLogout.addEventListener("click", logout);
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
