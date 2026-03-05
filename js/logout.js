// js/logout.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

const LOGIN_URL = "/estoque/pages/login.html";

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnLogout");
  if (!btn) return;

  btn.addEventListener("click", async () => {
    // evita double click
    btn.disabled = true;

    try {
      const resp = await apiRequest("logout", null, "POST");

      if (!resp?.sucesso) {
        logJsError({
          origem: "logout.js",
          mensagem: "Logout retornou falha",
          detalhe: resp?.mensagem,
        });
      } else {
        logJsInfo({
          origem: "logout.js",
          mensagem: "Logout realizado",
        });
      }
    } catch (err) {
      logJsError({
        origem: "logout.js",
        mensagem: err?.message || String(err),
        stack: err?.stack
      });
    } finally {
      localStorage.removeItem("usuario");
      // ✅ redireciona pro login real do projeto
      window.location.replace(LOGIN_URL);
    }
  });
});