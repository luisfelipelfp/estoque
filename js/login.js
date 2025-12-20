import { apiRequest } from "./api.js";
import { logJsError } from "./logger.js";

document.addEventListener("DOMContentLoaded", () => {
  const formLogin = document.getElementById("formLogin");
  const msgErro = document.getElementById("msgErro");

  if (!formLogin) {
    logJsError({ origem: "login.js", mensagem: "Formulário não encontrado" });
    return;
  }

  formLogin.addEventListener("submit", async e => {
    e.preventDefault();
    msgErro.textContent = "";

    const login = document.getElementById("login")?.value.trim();
    const senha = document.getElementById("senha")?.value.trim();

    if (!login || !senha) {
      msgErro.textContent = "Preencha login e senha.";
      return;
    }

    try {
      const resp = await apiRequest("login", { login, senha }, "POST");

      if (resp?.sucesso) {
        localStorage.setItem("usuario", JSON.stringify(resp.dados?.usuario));
        window.location.href = "index.html";
        return;
      }

      msgErro.textContent = resp?.mensagem || "Login inválido";

    } catch (err) {
      msgErro.textContent = "Erro de conexão com o servidor";
      logJsError({
        origem: "login.js",
        mensagem: err.message,
        stack: err.stack
      });
    }
  });
});
