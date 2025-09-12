// js/login.js
document.addEventListener("DOMContentLoaded", () => {
  const formLogin = document.getElementById("formLogin");
  const msgErro = document.getElementById("msgErro");

  formLogin.addEventListener("submit", async (e) => {
    e.preventDefault();
    msgErro.textContent = "";

    // 📌 Captura os campos do formulário
    const login = document.getElementById("login").value.trim();
    const senha = document.getElementById("senha").value.trim();

    if (!login || !senha) {
      msgErro.textContent = "Preencha login e senha.";
      return;
    }

    try {
      // 🔧 Agora envia os dados no formato aceito pelo login.php
      const dados = { login, senha };

      // ✅ Usa "login" → api.js converte corretamente para login.php
      const resp = await apiRequest("login", dados, "POST");

      if (resp.sucesso) {
        // ✅ Login bem-sucedido → redireciona
        window.location.href = "index.html";
      } else {
        msgErro.textContent = resp.mensagem || "Usuário/e-mail ou senha inválidos.";
      }
    } catch (err) {
      console.error("Erro inesperado no login:", err);
      msgErro.textContent = "Erro de conexão com o servidor.";
    }
  });
});
