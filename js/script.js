const API_URL = "http://192.168.15.100/estoque/api/actions.php";

async function apiRequest(acao, dados = null, metodo = "GET") {
    let url = `${API_URL}?acao=${encodeURIComponent(acao)}`;
    let options = { method: metodo };

    if (metodo === "GET" && dados) {
        const query = new URLSearchParams(dados).toString();
        url += "&" + query;
    } else if (metodo === "POST" && dados) {
        options.headers = {
            "Content-Type": "application/json"
        };
        options.body = JSON.stringify(dados);
    }

    const resp = await fetch(url, options);
    return resp.json();
}

// ---------------- Produtos ----------------
async function listarProdutos() {
    try {
        const resp = await apiRequest("listar_produtos");
        let produtos = Array.isArray(resp) ? resp : (resp?.dados || []);
        const tabela = document.querySelector("#tabelaProdutos tbody");
        tabela.innerHTML = "";

        produtos.forEach(prod => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${prod.id}</td>
                <td>${prod.nome}</td>
                <td>${prod.quantidade}</td>
                <td class="d-flex gap-2">
                    <button class="btn btn-success btn-sm" onclick="entrada(${prod.id})">Entrada</button>
                    <button class="btn btn-warning btn-sm" onclick="saida(${prod.id})">Saída</button>
                    <button class="btn btn-danger btn-sm" onclick="remover(${prod.id})">Remover</button>
                </td>
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar produtos:", err);
    }
}

// ---------------- Movimentações ----------------
async function listarMovimentacoes() {
    try {
        const resp = await apiRequest("listar_movimentacoes");
        let movimentacoes = Array.isArray(resp) ? resp : (resp?.dados || []);
        const tabela = document.querySelector("#tabelaMovimentacoes tbody");
        tabela.innerHTML = "";

        movimentacoes.forEach(mov => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${mov.id}</td>
                <td>${mov.produto_nome || "-"}</td>
                <td>${mov.tipo}</td>
                <td>${mov.quantidade}</td>
                <td>${mov.data}</td>
                <td>${mov.usuario || "-"}</td>
                <td>${mov.responsavel || "-"}</td>
            `;
            tabela.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar movimentações:", err);
    }
}

// ---------------- Ações ----------------
async function entrada(id) {
    const quantidade = prompt("Quantidade de entrada:");
    if (!quantidade || isNaN(quantidade) || quantidade <= 0) {
        alert("Quantidade inválida.");
        return;
    }
    const usuario = prompt("Usuário:");
    const responsavel = prompt("Responsável:");

    if (usuario && responsavel) {
        await apiRequest("registrar_movimentacao", {
            produto_id: id,
            tipo: "entrada",
            quantidade,
            usuario,
            responsavel
        }, "POST");

        listarProdutos();
        listarMovimentacoes();
    } else {
        alert("Usuário e responsável são obrigatórios.");
    }
}

async function saida(id) {
    const quantidade = prompt("Quantidade de saída:");
    if (!quantidade || isNaN(quantidade) || quantidade <= 0) {
        alert("Quantidade inválida.");
        return;
    }
    const usuario = prompt("Usuário:");
    const responsavel = prompt("Responsável:");

    if (usuario && responsavel) {
        await apiRequest("registrar_movimentacao", {
            produto_id: id,
            tipo: "saida",
            quantidade,
            usuario,
            responsavel
        }, "POST");

        listarProdutos();
        listarMovimentacoes();
    } else {
        alert("Usuário e responsável são obrigatórios.");
    }
}

async function remover(id) {
    if (confirm("Deseja remover este produto?")) {
        const usuario = prompt("Usuário:");
        const responsavel = prompt("Responsável:");

        if (usuario && responsavel) {
            await apiRequest("remover_produto", { id, usuario, responsavel }, "POST");
            listarProdutos();
            listarMovimentacoes();
        } else {
            alert("Usuário e responsável são obrigatórios para remover.");
        }
    }
}

// ---------------- Adicionar Produto ----------------
document.querySelector("#formAdicionarProduto").addEventListener("submit", async function (e) {
    e.preventDefault();

    const nome = document.querySelector("#nomeProduto").value.trim();
    const quantidade = 0; // Inicialmente zero

    if (!nome) {
        alert("Informe o nome do produto.");
        return;
    }

    const resposta = await apiRequest("adicionar_produto", {
        nome,
        quantidade
    }, "POST");

    if (resposta.sucesso) {
        this.reset();
        listarProdutos();
    } else {
        alert(resposta.mensagem || "Erro ao adicionar produto.");
    }
});

// ---------------- Inicialização ----------------
window.onload = () => {
    listarProdutos();
    listarMovimentacoes();
};
