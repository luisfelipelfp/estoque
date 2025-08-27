// URL base da API (ajuste conforme seu servidor)
const API_URL = "http://192.168.15.100/estoque/api/actions.php";

// Função genérica para requisições à API
async function apiRequest(acao, dados = {}, metodo = "GET") {
    let url = API_URL;
    let options = { method: metodo };

    if (metodo === "GET") {
        // GET → envia acao + dados via querystring
        const query = new URLSearchParams({ acao, ...dados }).toString();
        url += "?" + query;
    } else if (metodo === "POST") {
        // POST → envia acao + dados no corpo (FormData)
        const formData = new FormData();
        formData.append("acao", acao);
        for (let key in dados) {
            if (dados[key] !== undefined && dados[key] !== null) {
                formData.append(key, dados[key]);
            }
        }
        options.body = formData;
    }

    try {
        const response = await fetch(url, options);
        return await response.json();
    } catch (error) {
        console.error("Erro na requisição:", error);
        return { sucesso: false, mensagem: "Erro na comunicação com o servidor" };
    }
}

// ----------------------------
// Listar produtos
// ----------------------------
async function listarProdutos() {
    const data = await apiRequest("listar");
    const tabela = document.getElementById("produtos-body");
    tabela.innerHTML = "";

    if (data.sucesso && Array.isArray(data.dados)) {
        data.dados.forEach(prod => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${prod.id}</td>
                <td>${prod.nome}</td>
                <td>${prod.quantidade}</td>
                <td>
                    <button onclick="entrada(${prod.id})">Entrada</button>
                    <button onclick="saida(${prod.id})">Saída</button>
                    <button onclick="remover(${prod.id})">Remover</button>
                </td>
            `;
            tabela.appendChild(tr);
        });
    } else {
        tabela.innerHTML = `<tr><td colspan="4">${data.mensagem || "Erro ao carregar produtos"}</td></tr>`;
    }
}

// ----------------------------
// Adicionar produto
// ----------------------------
async function adicionarProduto() {
    const nome = document.getElementById("nome").value.trim();
    const quantidade = document.getElementById("quantidade").value;

    if (!nome || !quantidade) {
        alert("Preencha todos os campos!");
        return;
    }

    const data = await apiRequest("adicionar", { nome, quantidade }, "POST");

    alert(data.mensagem);
    if (data.sucesso) {
        document.getElementById("nome").value = "";
        document.getElementById("quantidade").value = "";
        listarProdutos();
    }
}

// ----------------------------
// Entrada de produto
// ----------------------------
async function entrada(id) {
    const quantidade = prompt("Quantidade de entrada:");
    if (!quantidade) return;

    const data = await apiRequest("entrada", { id, quantidade }, "POST");

    alert(data.mensagem);
    if (data.sucesso) listarProdutos();
}

// ----------------------------
// Saída de produto
// ----------------------------
async function saída(id) {
    const quantidade = prompt("Quantidade de saída:");
    if (!quantidade) return;

    const data = await apiRequest("saida", { id, quantidade }, "POST");

    alert(data.mensagem);
    if (data.sucesso) listarProdutos();
}

// ----------------------------
// Remover produto
// ----------------------------
async function remover(id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;

    const data = await apiRequest("remover", { id }, "POST");

    alert(data.mensagem);
    if (data.sucesso) listarProdutos();
}

// ----------------------------
// Carregar lista ao abrir a página
// ----------------------------
document.addEventListener("DOMContentLoaded", listarProdutos);
