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
      // 🔑 Usa apiRequest (já inclui credentials: "include")
      const resp = await apiRequest("login", { email, senha }, "POST");

      if (resp.sucesso) {
        // Sessão fica no servidor, não precisa salvar localStorage
        window.location.href = "index.html";
      } else {
        msgErro.textContent = resp.mensagem || "E-mail ou senha inválidos.";
      }
    } catch (err) {
      console.error("Erro no login:", err);
      msgErro.textContent = "Erro de conexão com o servidor.";
    }
  });
});
