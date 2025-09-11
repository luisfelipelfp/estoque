// js/login.js
document.addEventListener("DOMContentLoaded", () => {
  const formLogin = document.getElementById("formLogin");
  const msgErro = document.getElementById("msgErro");

  formLogin.addEventListener("submit", async (e) => {
    e.preventDefault();
    msgErro.textContent = "";

    // 🔧 Corrigido: agora pega o campo "login"
    const login = document.getElementById("login").value.trim();
    const senha = document.getElementById("senha").value.trim();

    if (!login || !senha) {
      msgErro.textContent = "Preencha login e senha.";
      return;
    }

    try {
      const dados = { login, senha }; // 🔧 chave compatível com login.php
      const resp = await apiRequest("login", dados, "POST");

      if (resp.sucesso) {
        window.location.href = "index.html";
      } else {
        msgErro.textContent = resp.mensagem || "Erro ao efetuar login.";
      }
    } catch (err) {
      console.error("Erro inesperado no login:", err);
      msgErro.textContent = "Erro de conexão com o servidor.";
    }
  });
});
