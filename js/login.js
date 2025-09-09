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

    // Usa apiRequest já existente em js/api.js
    const resp = await apiRequest("login", { email, senha }, "POST");

    if (resp.sucesso) {
      // Não precisa guardar no localStorage: sessão já está no servidor
      window.location.href = "index.html";
    } else {
      msgErro.textContent = resp.mensagem || "Erro ao efetuar login.";
    }
  });
});
