import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnLogout");
  if (!btn) return;

  btn.addEventListener("click", async () => {
    try {
      await apiRequest("logout", null, "POST");
    } catch (err) {
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
