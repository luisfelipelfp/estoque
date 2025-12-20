// js/logout.js
import { logJsError } from "./logger.js";

document.addEventListener("DOMContentLoaded", () => {
  const btnLogout = document.getElementById("btnLogout");

  if (!btnLogout) {
    logJsError({
      origem: "logout.js",
      mensagem: "Botão de logout não encontrado"
    });
    return;
  }

  btnLogout.addEventListener("click", async () => {
    try {
      await apiRequest("logout", null, "POST");
    } catch (err) {
      console.error("Erro no logout:", err);

      logJsError({
        origem: "logout.js",
        mensagem: err.message,
        stack: err.stack
      });
    } finally {
      localStorage.removeItem("usuario");
      window.location.href = "login.html";
    }
  });
});
