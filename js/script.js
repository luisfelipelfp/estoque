// URL base da API (ajuste conforme seu servidor)
const API_URL = "http://192.168.15.100/estoque/api/actions.php";

// Função genérica para requisições à API
async function apiRequest(action, data = {}) {
    try {
        const options = {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action, ...data }) // action agora vai junto no body
        };

        const response = await fetch(API_URL, options);

        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }

        return await response.json();
    } catch (error) {
        console.error("Erro na API:", error);
        return null;
    }
}

// ===================== PRODUTOS =====================

// Listar produtos
async function listarProdutos() {
    const produtos = await apiRequest("listarProdutos");
    if (!produtos) {
        console.error("Erro ao listar produtos");
        return;
    }

    const tabela = document.querySelector("#tabelaProdutos tbody");
    tabela.innerHTML = "";

    produtos.forEach(p => {
  const tr = document.createElement("tr");
  const qtdClass = (Number(p.quantidade) < 11) ? "low-stock-cell" : "";

  tr.innerHTML = `
    <td>${p.id}</td>
    <td>${p.nome}</td>
    <td class="${qtdClass}">${p.quantidade}</td>
    <td>
      <button class="btn btn-sm btn-success" onclick="entradaProduto(${p.id})">Entrada</button>
      <button class="btn btn-sm btn-warning" onclick="saidaProduto(${p.id})">Saída</button>
      <button class="btn btn-sm btn-danger" onclick="removerProduto(${p.id})">Remover</button>
    </td>
  `;
  tabela.appendChild(tr);
});

}

// Adicionar produto
async function adicionarProduto() {
    const nome = document.querySelector("#nomeProduto").value.trim();
    if (!nome) {
        alert("Digite um nome para o produto.");
        return;
    }

    const result = await apiRequest("adicionarProduto", { nome });
    if (result && result.sucesso) {
        listarProdutos();
        document.querySelector("#nomeProduto").value = "";
    } else {
        alert(result.erro || "Erro ao adicionar produto.");
    }
}

// Entrada de produto
async function entradaProduto(id) {
    const quantidade = prompt("Quantidade de entrada:");
    if (!quantidade || isNaN(quantidade)) return;

    const result = await apiRequest("entradaProduto", { id, quantidade });
    if (result && result.sucesso) {
        listarProdutos();
    } else {
        alert(result.erro || "Erro ao registrar entrada.");
    }
}

// Saída de produto
async function saidaProduto(id) {
    const quantidade = prompt("Quantidade de saída:");
    if (!quantidade || isNaN(quantidade)) return;

    const result = await apiRequest("saidaProduto", { id, quantidade });
    if (result && result.sucesso) {
        listarProdutos();
    } else {
        alert(result.erro || "Erro ao registrar saída.");
    }
}

// Remover produto
async function removerProduto(id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;

    const result = await apiRequest("removerProduto", { id });
    if (result && result.sucesso) {
        listarProdutos();
    } else {
        alert(result.erro || "Erro ao remover produto.");
    }
}

// ===================== MOVIMENTAÇÕES =====================

// Listar movimentações
async function listarMovimentacoes() {
    const movimentacoes = await apiRequest("listarMovimentacoes");
    if (!movimentacoes) {
        console.error("Erro ao listar movimentações");
        return;
    }

    const tabela = document.querySelector("#tabelaMovimentacoes tbody");
    tabela.innerHTML = "";

    movimentacoes.forEach(m => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${m.id}</td>
            <td>${m.produto_nome}</td>
            <td>${m.tipo}</td>
            <td>${m.quantidade}</td>
            <td>${m.data}</td>
        `;
        tabela.appendChild(tr);
    });
}

// ===================== INICIALIZAÇÃO =====================

document.addEventListener("DOMContentLoaded", () => {
    listarProdutos();
    listarMovimentacoes();

    document
        .querySelector("#formAdicionarProduto")
        .addEventListener("submit", (e) => {
            e.preventDefault();
            adicionarProduto();
        });
});
