// js/logout.js
document.addEventListener("DOMContentLoaded", () => {
  const btnLogout = document.getElementById("btnLogout");

  if (btnLogout) {
    btnLogout.addEventListener("click", async () => {
      try {
        // 🔑 chama o logout no backend (logout.php)
        await apiRequest("logout", null, "POST");
      } catch (err) {
        console.error("Erro ao deslogar:", err);
      } finally {
        // 🔒 limpa dados locais
        localStorage.removeItem("usuario");

        // 🔄 redireciona para tela de login
        window.location.href = "login.html";
      }
    });
  }
});
