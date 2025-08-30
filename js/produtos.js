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

        // 🔄 Atualiza o select de filtro de produtos
        preencherFiltroProdutos(produtos);

    } catch (err) {
        console.error("Erro ao listar produtos:", err);
    }
}

async function entrada(id) {
    const quantidade = prompt("Quantidade de entrada:");
    if (!quantidade || isNaN(quantidade) || quantidade <= 0) {
        alert("Quantidade inválida.");
        return;
    }

    await apiRequest("registrar_movimentacao", {
        produto_id: id,
        tipo: "entrada",
        quantidade,
        usuario: "",
        responsavel: ""
    }, "POST");

    listarProdutos();
    listarMovimentacoes();
}

async function saida(id) {
    const quantidade = prompt("Quantidade de saída:");
    if (!quantidade || isNaN(quantidade) || quantidade <= 0) {
        alert("Quantidade inválida.");
        return;
    }

    await apiRequest("registrar_movimentacao", {
        produto_id: id,
        tipo: "saida",
        quantidade,
        usuario: "",
        responsavel: ""
    }, "POST");

    listarProdutos();
    listarMovimentacoes();
}

async function remover(id) {
    if (confirm("Deseja remover este produto?")) {
        await apiRequest("remover_produto", { id }, "GET");
        listarProdutos();
        listarMovimentacoes();
    }
}

// Evento de adicionar produto
document.querySelector("#formAdicionarProduto")?.addEventListener("submit", async function (e) {
    e.preventDefault();

    const nome = document.querySelector("#nomeProduto").value.trim();
    const quantidade = 0;

    if (!nome) {
        alert("Informe o nome do produto.");
        return;
    }

    const resposta = await apiRequest("adicionar_produto", { nome, quantidade }, "POST");

    if (resposta.sucesso) {
        this.reset();
        listarProdutos();
    } else {
        alert(resposta.mensagem || "Erro ao adicionar produto.");
    }
});


// 🔽 Função para preencher automaticamente o filtro de produtos
function preencherFiltroProdutos(produtos) {
    const select = document.querySelector("#filtroProduto");
    if (!select) return;

    select.innerHTML = `<option value="">Todos os Produtos</option>`;
    produtos.forEach(prod => {
        const opt = document.createElement("option");
        opt.value = prod.id;
        opt.textContent = prod.nome;
        select.appendChild(opt);
    });
}
