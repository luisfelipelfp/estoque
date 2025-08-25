// URL base da API (ajuste conforme seu servidor)
const API_URL = "http://192.168.15.100/estoque/api/actions.php";

// Função genérica para requisições à API
async function apiRequest(action, data = {}) {
    try {
        const options = {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action, ...data })
        };

        const response = await fetch(API_URL, options);

        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }

        return await response.json();
    } catch (error) {
        console.error("Erro na API:", error);
        return { sucesso: false, erro: "Falha na comunicação com servidor" };
    }
}

// ===================== PRODUTOS =====================

// Listar produtos
async function listarProdutos() {
    const result = await apiRequest("listarProdutos"); // corrigido
    if (!result.sucesso) {
        console.error(result.erro || "Erro ao listar produtos");
        return;
    }

    const produtos = result.dados || [];
    const tabela = document.querySelector("#tabelaProdutos tbody");
    tabela.innerHTML = "";

    produtos.forEach(p => {
        const tr = document.createElement("tr");

        // Aplica a classe na linha toda se quantidade < 11
        if (Number(p.quantidade) < 11) {
            tr.classList.add("estoque-baixo");
        }

        tr.innerHTML = `
            <td>${p.id}</td>
            <td>${p.nome}</td>
            <td>${p.quantidade}</td>
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

    const result = await apiRequest("adicionarProduto", { nome }); // corrigido
    if (result.sucesso) {
        listarProdutos();
        document.querySelector("#nomeProduto").value = "";
    } else {
        alert(result.erro || "Erro ao adicionar produto.");
    }
}

// Entrada de produto
async function entradaProduto(id) {
    const quantidade = prompt("Quantidade de entrada:");
    if (!quantidade || isNaN(quantidade) || Number(quantidade) <= 0) return;

    const result = await apiRequest("entradaProduto", { id, quantidade }); // corrigido
    if (result.sucesso) {
        listarProdutos();
        listarMovimentacoes();
    } else {
        alert(result.erro || "Erro ao registrar entrada.");
    }
}

// Saída de produto
async function saidaProduto(id) {
    const quantidade = prompt("Quantidade de saída:");
    if (!quantidade || isNaN(quantidade) || Number(quantidade) <= 0) return;

    const result = await apiRequest("saidaProduto", { id, quantidade }); // corrigido
    if (result.sucesso) {
        listarProdutos();
        listarMovimentacoes();
    } else {
        alert(result.erro || "Erro ao registrar saída.");
    }
}

// Remover produto
async function removerProduto(id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;

    const result = await apiRequest("removerProduto", { id }); // corrigido
    if (result.sucesso) {
        listarProdutos();
        listarMovimentacoes(); // agora atualiza o relatório também
    } else {
        alert(result.erro || "Erro ao remover produto.");
    }
}

// ===================== MOVIMENTAÇÕES =====================

// Listar movimentações
async function listarMovimentacoes() {
    const result = await apiRequest("listarMovimentacoes"); // corrigido
    if (!result.sucesso) {
        console.error(result.erro || "Erro ao listar movimentações");
        return;
    }

    const movimentacoes = result.dados || [];
    const tabela = document.querySelector("#tabelaMovimentacoes tbody");
    tabela.innerHTML = "";

    movimentacoes.forEach(m => {
        const tr = document.createElement("tr");

        // Destacar entradas e saídas com cores diferentes
        tr.classList.add(m.tipo === "entrada" ? "mov-entrada" : "mov-saida");

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

    document.querySelector("#formAdicionarProduto").addEventListener("submit", (e) => {
        e.preventDefault();
        adicionarProduto();
    });
});
