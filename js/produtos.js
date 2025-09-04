// js/produtos.js

function getUsuarioId() {
    try {
        const user = JSON.parse(localStorage.getItem("usuarioLogado"));
        return user?.id || null;
    } catch {
        return null;
    }
}

// Lista de produtos
async function listarProdutos() {
    try {
        const resp = await apiRequest("listar_produtos", null, "GET");
        const produtos = Array.isArray(resp) ? resp : (resp?.dados || resp || []);
        const tbody = document.querySelector("#tabelaProdutos tbody");
        if (!tbody) return;

        tbody.innerHTML = "";

        if (!produtos.length) {
            const tr = document.createElement("tr");
            tr.innerHTML = `<td colspan="4" class="text-center">Nenhum produto encontrado</td>`;
            tbody.appendChild(tr);
            return;
        }

        produtos.forEach(p => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${p.id}</td>
                <td>${p.nome}</td>
                <td>${p.quantidade}</td>
                <td class="d-flex gap-2">
                    <button class="btn btn-success btn-sm btn-entrada" data-id="${p.id}">Entrada</button>
                    <button class="btn btn-warning btn-sm btn-saida" data-id="${p.id}">Saída</button>
                    <button class="btn btn-danger btn-sm btn-remover" data-id="${p.id}">Remover</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    } catch (err) {
        console.error("Erro ao listar produtos:", err);
    }
}

window.entrada = async function (id) {
    const qtd = prompt("Quantidade de entrada:");
    if (qtd === null) return;
    const quantidade = parseInt(qtd, 10);
    if (!Number.isFinite(quantidade) || quantidade <= 0) {
        alert("Quantidade inválida.");
        return;
    }
    try {
        const resp = await apiRequest("entrada", { id, quantidade, usuario_id: getUsuarioId() }, "GET");
        if (resp.sucesso) {
            alert(resp.mensagem || "Entrada registrada.");
            await listarProdutos();
            if (typeof listarMovimentacoes === "function") await listarMovimentacoes();
        } else {
            alert(resp.mensagem || "Erro ao registrar entrada.");
        }
    } catch (err) {
        console.error("Erro na entrada:", err);
        alert("Erro de comunicação.");
    }
};

window.saida = async function (id) {
    const qtd = prompt("Quantidade de saída:");
    if (qtd === null) return;
    const quantidade = parseInt(qtd, 10);
    if (!Number.isFinite(quantidade) || quantidade <= 0) {
        alert("Quantidade inválida.");
        return;
    }
    try {
        const resp = await apiRequest("saida", { id, quantidade, usuario_id: getUsuarioId() }, "GET");
        if (resp.sucesso) {
            alert(resp.mensagem || "Saída registrada.");
            await listarProdutos();
            if (typeof listarMovimentacoes === "function") await listarMovimentacoes();
        } else {
            alert(resp.mensagem || "Erro ao registrar saída.");
        }
    } catch (err) {
        console.error("Erro na saída:", err);
        alert("Erro de comunicação.");
    }
};

window.remover = async function (id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;
    try {
        const resp = await apiRequest("remover", { id, usuario_id: getUsuarioId() }, "GET");
        if (resp.sucesso) {
            alert(resp.mensagem || "Produto removido.");
            await listarProdutos();
            if (typeof listarMovimentacoes === "function") await listarMovimentacoes();
        } else {
            alert(resp.mensagem || "Erro ao remover produto.");
        }
    } catch (err) {
        console.error("Erro ao remover produto:", err);
        alert("Erro de comunicação.");
    }
};

// Event delegation
document.addEventListener("click", function (e) {
    const btn = e.target.closest("button");
    if (!btn) return;
    const id = btn.dataset.id;
    if (!id) return;

    if (btn.classList.contains("btn-entrada")) {
        window.entrada(parseInt(id, 10));
    } else if (btn.classList.contains("btn-saida")) {
        window.saida(parseInt(id, 10));
    } else if (btn.classList.contains("btn-remover")) {
        window.remover(parseInt(id, 10));
    }
});

// Formulário adicionar produto
document.querySelector("#formAdicionarProduto")?.addEventListener("submit", async function (e) {
    e.preventDefault();
    const nome = (document.querySelector("#nomeProduto")?.value || "").trim();
    if (!nome) {
        alert("Informe o nome do produto.");
        return;
    }
    try {
        const resp = await apiRequest("adicionar", { nome, quantidade: 0, usuario_id: getUsuarioId() }, "POST");
        if (resp.sucesso) {
            this.reset();
            await listarProdutos();
            if (typeof preencherFiltroProdutos === "function") await preencherFiltroProdutos();
        } else {
            alert(resp.mensagem || "Erro ao adicionar produto.");
        }
    } catch (err) {
        console.error("Erro ao adicionar produto:", err);
        alert("Erro de comunicação.");
    }
});

window.addEventListener("DOMContentLoaded", () => {
    listarProdutos();
});
