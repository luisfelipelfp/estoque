// js/login.js
import { apiRequest } from "./api.js";
import { logJsError } from "./logger.js";

const APP_BASE = "/estoque";

function getNextUrl() {
  const params = new URLSearchParams(window.location.search);
  const next = params.get("next");

  // segurança básica: só aceita next interno (começando com /)
  if (next && next.startsWith("/")) return next;

  return `${APP_BASE}/pages/estoque.html`;
}

document.addEventListener("DOMContentLoaded", () => {
  const formLogin = document.getElementById("formLogin");
  const msgErro = document.getElementById("msgErro");

  if (!formLogin) {
    logJsError({
      origem: "login.js",
      mensagem: "Formulário #formLogin não encontrado",
    });
    return;
  }

  formLogin.addEventListener("submit", async (e) => {
    e.preventDefault();
    msgErro.textContent = "";

    const login = (document.getElementById("login")?.value || "").trim();
    const senha = document.getElementById("senha")?.value || "";

    if (!login || !senha) {
      msgErro.textContent = "Preencha login e senha.";
      return;
    }

    try {
      const resp = await apiRequest("login", { login, senha }, "POST");

      if (resp?.sucesso === true) {
        const usuario = resp?.dados?.usuario || resp?.usuario;
        if (usuario) localStorage.setItem("usuario", JSON.stringify(usuario));

        // ✅ volta para a página pedida
        window.location.replace(getNextUrl());
        return;
      }

      msgErro.textContent = resp?.mensagem || "Usuário ou senha inválidos.";
    } catch (err) {
      msgErro.textContent = "Erro de comunicação com o servidor.";

      logJsError({
        origem: "login.js",
        mensagem: err?.message || String(err),
        stack: err?.stack,
      });
    }
  });
});