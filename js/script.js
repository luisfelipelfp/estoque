// ===== Funções utilitárias =====
function abrirModal(id) {
    document.getElementById(id).style.display = "flex";
}

function fecharModal(id) {
    document.getElementById(id).style.display = "none";
}

// Fechar modal ao clicar fora do conteúdo
window.onclick = function(event) {
    document.querySelectorAll(".modal").forEach(modal => {
        if (event.target === modal) {
            modal.style.display = "none";
        }
    });
};

// ===== Produtos =====
async function carregarProdutos() {
    try {
        const resp = await fetch("api/actions.php?action=listarProdutos");
        const produtos = await resp.json();

        const tbody = document.querySelector("#tabelaProdutos tbody");
        tbody.innerHTML = "";

        if (produtos.length === 0) {
            tbody.innerHTML = `<tr><td colspan="2" style="text-align:center">Nenhum produto cadastrado</td></tr>`;
            return;
        }

        produtos.forEach(p => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${p.nome}</td>
                <td>${p.quantidade}</td>
            `;
            tbody.appendChild(tr);
        });

    } catch (err) {
        console.error("Erro ao carregar produtos:", err);
    }
}

async function cadastrarProduto() {
    const nome = document.getElementById("nomeProduto").value;
    const quantidade = document.getElementById("quantidadeProduto").value;

    if (!nome || !quantidade) {
        alert("Preencha todos os campos!");
        return;
    }

    const formData = new FormData();
    formData.append("action", "cadastrarProduto");
    formData.append("nome", nome);
    formData.append("quantidade", quantidade);

    const resp = await fetch("api/actions.php", { method: "POST", body: formData });
    const resultado = await resp.text();

    alert(resultado);
    fecharModal("modalCadastro");
    carregarProdutos();
}

// ===== Movimentações =====
async function registrarMovimentacao() {
    const produtoId = document.getElementById("produtoMov").value;
    const tipo = document.getElementById("tipoMov").value;
    const quantidade = document.getElementById("qtdMov").value;

    if (!produtoId || !quantidade) {
        alert("Preencha todos os campos!");
        return;
    }

    const formData = new FormData();
    formData.append("action", "registrarMovimentacao");
    formData.append("produto_id", produtoId);
    formData.append("tipo", tipo);
    formData.append("quantidade", quantidade);

    const resp = await fetch("api/actions.php", { method: "POST", body: formData });
    const resultado = await resp.text();

    alert(resultado);
    fecharModal("modalMovimentacao");
    carregarProdutos();
}

// ===== Relatório =====
async function gerarRelatorio() {
    const dataInicio = document.getElementById("dataInicio").value;
    const dataFim = document.getElementById("dataFim").value;

    if (!dataInicio || !dataFim) {
        alert("Selecione o intervalo de datas!");
        return;
    }

    const resp = await fetch(`api/actions.php?action=relatorio&inicio=${dataInicio}&fim=${dataFim}`);
    const relatorio = await resp.json();

    const tbody = document.querySelector("#tabelaRelatorio tbody");
    tbody.innerHTML = "";

    if (relatorio.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center">Nenhuma movimentação encontrada</td></tr>`;
        return;
    }

    relatorio.forEach(r => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${r.produto}</td>
            <td>${r.tipo}</td>
            <td>${r.quantidade}</td>
            <td>${r.data}</td>
        `;
        tbody.appendChild(tr);
    });
}

// ===== Exportações =====
function exportarProdutosPDF() {
    window.open("api/actions.php?action=exportarProdutosPDF", "_blank");
}

function exportarRelatorioPDF() {
    window.open("api/actions.php?action=exportarRelatorioPDF", "_blank");
}

// ===== Inicialização =====
document.addEventListener("DOMContentLoaded", () => {
    carregarProdutos();
});
