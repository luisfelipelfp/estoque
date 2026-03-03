// js/login.js
import { apiRequest } from "./api.js";
import { logJsError } from "./logger.js";

document.addEventListener("DOMContentLoaded", () => {
  const formLogin = document.getElementById("formLogin");
  const msgErro = document.getElementById("msgErro");

  if (!formLogin) {
    logJsError({
      origem: "login.js",
      mensagem: "Formulário #formLogin não encontrado"
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

      const resp = await apiRequest("login", { login, senha }, "POST");

      console.log("📥 Resposta login:", resp);

      if (resp && resp.sucesso === true) {
        // 🔑 sessão já está criada no backend
        // não dependemos de dados.usuario para redirecionar

        if (resp.dados?.usuario) {
          localStorage.setItem(
            "usuario",
            JSON.stringify(resp.dados.usuario)
          );
        }

        // ✅ REDIRECIONA
        window.location.replace("/index.html");
        return;
      }

      msgErro.textContent =
        resp?.mensagem || "Usuário ou senha inválidos.";

    } catch (err) {
      console.error("Erro inesperado no login:", err);

      msgErro.textContent = "Erro de comunicação com o servidor.";

      logJsError({
        origem: "login.js",
        mensagem: err.message,
        stack: err.stack
      });
    }
  });
});
