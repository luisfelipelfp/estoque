// js/main.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

const APP_BASE = "/estoque";

function redirectToLogin() {
  const next = window.location.pathname + window.location.search;
  const url = `${APP_BASE}/pages/login.html?next=${encodeURIComponent(next)}`;
  window.location.replace(url);
}

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");

    const usuario = resp?.dados?.usuario || resp?.usuario;
    if (!resp?.sucesso || !usuario) {
      redirectToLogin();
      return null;
    }

    localStorage.setItem("usuario", JSON.stringify(usuario));
    return usuario;
  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: err?.message || String(err),
      stack: err?.stack,
    });
    redirectToLogin();
    return null;
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  const usuario = await verificarLogin();
  if (!usuario) return;

  logJsInfo({
    origem: "main.js",
    mensagem: "Usuário autenticado",
    usuario: usuario.nome,
  });

  const span = document.getElementById("usuarioLogado");
  if (span) span.textContent = `${usuario.nome} (${usuario.nivel})`;
}); 