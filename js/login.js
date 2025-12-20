// js/login.js
import { logJsError } from "./logger.js";

document.addEventListener("DOMContentLoaded", () => {
  const formLogin = document.getElementById("formLogin");
  const msgErro = document.getElementById("msgErro");

  if (!formLogin) {
    logJsError({
      origem: "login.js",
      mensagem: "Formulário de login não encontrado no DOM"
    });
    return;
  }

  formLogin.addEventListener("submit", async (e) => {
    e.preventDefault();
    msgErro.textContent = "";

    const login = document.getElementById("login")?.value.trim();
    const senha = document.getElementById("senha")?.value.trim();

    if (!login || !senha) {
      msgErro.textContent = "Preencha login e senha.";

      logJsError({
        origem: "login.js",
        mensagem: "Tentativa de login com campos vazios"
      });

      return;
    }

    try {
      const dados = { login, senha };

      // 🔑 Chamada via api.js
      const resp = await apiRequest("login", dados, "POST");

      if (resp?.sucesso) {
        // ✅ Salva usuário no localStorage
        if (resp.dados?.usuario) {
          localStorage.setItem(
            "usuario",
            JSON.stringify(resp.dados.usuario)
          );
        }

        window.location.href = "index.html";
        return;
      }

      // ❌ Login inválido
      msgErro.textContent =
        resp?.mensagem || "Usuário/e-mail ou senha inválidos.";

      logJsError({
        origem: "login.js",
        mensagem: "Falha de autenticação",
        stack: JSON.stringify({
          login,
          retorno: resp
        })
      });

    } catch (err) {
      console.error("Erro inesperado no login:", err);

      msgErro.textContent = "Erro de conexão com o servidor.";

      logJsError({
        origem: "login.js",
        mensagem: err.message,
        stack: err.stack
      });
    }
  });
});
