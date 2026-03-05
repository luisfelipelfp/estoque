// js/main.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

const APP_BASE = "/estoque";
const LOGIN_PAGE = `${APP_BASE}/pages/login.html`;

function redirectToLogin() {
  // preserva para onde o usuário tentou ir
  const next = encodeURIComponent(window.location.pathname + window.location.search);
  window.location.replace(`${LOGIN_PAGE}?next=${next}`);
}

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");

    // aceita formatos:
    // 1) {sucesso:true, usuario:{...}}
    // 2) {sucesso:true, dados:{usuario:{...}}}
    const usuario = resp?.usuario || resp?.dados?.usuario || null;

    if (!resp?.sucesso || !usuario?.id) {
      redirectToLogin();
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
    usuario: usuario.nome
  });

  const span = document.getElementById("usuarioLogado");
  if (span) span.textContent = `${usuario.nome} (${usuario.nivel || "usuario"})`;
});