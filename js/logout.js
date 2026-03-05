// js/logout.js
import { apiRequest } from "./api.js";
import { logJsError } from "./logger.js";

const APP_BASE = "/estoque";
const LOGIN_PAGE = `${APP_BASE}/pages/login.html`;

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnLogout");
  if (!btn) return;

  btn.addEventListener("click", async () => {
    try {
      await apiRequest("logout", null, "POST");
    } catch (err) {
      logJsError({
        origem: "logout.js",
        mensagem: err?.message || String(err),
        stack: err?.stack
      });
    } finally {
      localStorage.removeItem("usuario");
      window.location.replace(LOGIN_PAGE);
    }
  });
});