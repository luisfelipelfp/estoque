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
      const dados = { login, senha };

      // 🔑 Faz a requisição para login.php via api.js
      const resp = await apiRequest("login", dados, "POST");

      if (resp.sucesso) {
        // ✅ Pega usuário retornado e salva no localStorage
        if (resp.dados?.usuario) {
          localStorage.setItem("usuario", JSON.stringify(resp.dados.usuario));
        }

        // Redireciona para a home
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
