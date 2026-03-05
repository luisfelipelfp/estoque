// js/main.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

const APP_BASE = "/estoque";
const LOGIN_PAGE = `${APP_BASE}/pages/login.html`;

function getCurrentPath() {
  // salva o caminho atual (com query)
  return window.location.pathname + window.location.search;
}

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");
    const usuario = resp?.dados?.usuario;

    if (!resp?.sucesso || !usuario) {
      window.location.replace(`${LOGIN_PAGE}?next=${encodeURIComponent(getCurrentPath())}`);
      return null;
    }

    localStorage.setItem("usuario", JSON.stringify(usuario));
    return usuario;

  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: err?.message || String(err),
      stack: err?.stack
    });
    window.location.replace(`${LOGIN_PAGE}?next=${encodeURIComponent(getCurrentPath())}`);
    return null;
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  const usuario = await verificarLogin();
  if (!usuario) return;

  logJsInfo({
    origem: "main.js",
    mensagem: "Usuário autenticado",
    usuario: usuario.nome
  });

  const span = document.getElementById("usuarioLogado");
  if (span) span.textContent = `${usuario.nome} (${usuario.nivel})`;
});