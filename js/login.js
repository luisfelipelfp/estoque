// js/login.js
import { apiRequest } from "./api.js";
import { logJsError } from "./logger.js";

/**
 * Base do app (para rodar em subpasta /estoque)
 * Se no futuro você mudar para subdomínio (estoque.local), isso continua funcionando.
 */
const APP_BASE = "/estoque";

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

    const loginInput = document.getElementById("login");
    const senhaInput = document.getElementById("senha");

    const login = loginInput?.value.trim();
    const senha = senhaInput?.value;

    if (!login || !senha) {
      msgErro.textContent = "Preencha login e senha.";
      return;
    }

    try {
      console.log("🔐 Enviando login...");

      // Importante: apiRequest precisa apontar para /estoque/api/...
      // Esse ajuste principal será feito no api.js (já já).
      const resp = await apiRequest("login", { login, senha }, "POST");

      console.log("📥 Resposta login:", resp);

      if (resp?.sucesso === true) {
        if (resp.dados?.usuario) {
          localStorage.setItem("usuario", JSON.stringify(resp.dados.usuario));
        }

        // ✅ Redireciona para dentro do /estoque
        window.location.replace(`${APP_BASE}/index.html`);
        return;
      }

      msgErro.textContent = resp?.mensagem || "Usuário ou senha inválidos.";
    } catch (err) {
      console.error("Erro inesperado no login:", err);

      msgErro.textContent = "Erro de comunicação com o servidor.";

      logJsError({
        origem: "login.js",
        mensagem: err?.message || String(err),
        stack: err?.stack,
      });
    }
  });
});