import { apiRequest } from "./api.js";
import { logJsError, logJsInfo } from "./logger.js";

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual");

    if (!resp?.sucesso || !resp.usuario) {
      window.location.href = "login.html";
      return null;
    }

    localStorage.setItem("usuario", JSON.stringify(resp.usuario));
    return resp.usuario;

  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: err.message,
      stack: err.stack
    });
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
