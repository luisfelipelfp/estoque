// Caminho relativo, pois login.html está em /estoque/ e actions.php em /estoque/api/
const API_URL = "api/actions.php";

document.getElementById("formLogin").addEventListener("submit", async (e) => {
    e.preventDefault();

    const email = document.getElementById("email").value.trim();
    const senha = document.getElementById("senha").value.trim();
    const msgErro = document.getElementById("msgErro");

    msgErro.textContent = "";

    try {
        const formData = new FormData();
        formData.append("email", email);
        formData.append("senha", senha);

        const response = await fetch(`${API_URL}?acao=login`, {
            method: "POST",
            body: formData
        });

        const data = await response.json();

        if (data.sucesso) {
            localStorage.setItem("usuario", JSON.stringify(data.usuario));
            window.location.href = "index.html"; // Redireciona para o sistema
        } else {
            msgErro.textContent = data.mensagem || "Erro ao efetuar login.";
        }
    } catch (err) {
        msgErro.textContent = "Erro de conexão com o servidor.";
    }
});
