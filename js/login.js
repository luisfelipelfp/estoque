// js/login.js
document.addEventListener("DOMContentLoaded", () => {
  const formLogin = document.getElementById("formLogin");
  const msgErro = document.getElementById("msgErro");

  formLogin.addEventListener("submit", async (e) => {
    e.preventDefault();
    msgErro.textContent = "";

    const email = document.getElementById("email").value.trim();
    const senha = document.getElementById("senha").value.trim();

    if (!email || !senha) {
      msgErro.textContent = "Preencha todos os campos.";
      return;
    }

    try {
      // 🔧 compatibilidade (email ou login)
      const dados = { email, login: email, senha };

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
