// js/logout.js
document.addEventListener("DOMContentLoaded", () => {
  const btnLogout = document.getElementById("btnLogout");

  if (!btnLogout) {
    console.warn("⚠️ Botão de logout não encontrado na página.");
    return;
  }

  btnLogout.addEventListener("click", async () => {
    try {
      console.log("🔑 Enviando requisição de logout...");
      await apiRequest("logout", null, "POST");
    } catch (err) {
      console.error("❌ Erro ao deslogar:", err);
    } finally {
      // 🔒 sempre limpa os dados locais
      localStorage.removeItem("usuario");

      // 🔄 redireciona para tela de login
      window.location.href = "login.html";
    }
  });
});
