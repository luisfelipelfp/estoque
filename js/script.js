// Função genérica para chamadas à API
async function apiRequest(acao, params = {}) {
    try {
        const formData = new FormData();
        formData.append("acao", acao);
        for (const k in params) formData.append(k, params[k]);

        const res = await fetch("api/actions.php", {
            method: "POST",
            body: formData
        });

        if (!res.ok) throw new Error("Erro HTTP " + res.status);

        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch {
            console.error("Resposta não é JSON:", text);
            return { sucesso: false, erro: "Resposta inválida" };
        }
    } catch (e) {
        console.error("Erro na API:", e);
        return { sucesso: false, erro: e.message };
    }
}

// ================= PRODUTOS =================
async function listarProdutos() {
    const result = await apiRequest("listarprodutos");
    if (!result.sucesso) {
        console.error("Erro ao listar produtos");
        return;
    }

    const produtos = result.dados || [];
    const tabela = document.querySelector("#tabelaProdutos tbody");
    tabela.innerHTML = "";

    produtos.forEach(p => {
        const tr = document.createElement("tr");
        if (p.quantidade <= 5) tr.classList.add("estoque-baixo");

        tr.innerHTML = `
            <td>${p.id}</td>
            <td>${p.nome}</td>
            <td>${p.quantidade}</td>
        `;
        tabela.appendChild(tr);
    });
}

// ================= MOVIMENTAÇÕES =================
async function listarMovimentacoes(filtros = {}) {
    const result = await apiRequest("listarmovimentacoes", filtros);
    if (!result.sucesso) {
        console.error("Erro ao listar movimentações");
        return;
    }

    const movimentacoes = result.dados || [];
    const tabela = document.querySelector("#tabelaMovimentacoes tbody");
    tabela.innerHTML = "";

    movimentacoes.forEach(m => {
        const tr = document.createElement("tr");
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

// ================= FILTROS =================
function aplicarFiltros() {
    const produto = document.querySelector("#filtroProduto").value.trim();
    const tipo = document.querySelector("#filtroTipo").value;
    const inicio = document.querySelector("#filtroInicio").value;
    const fim = document.querySelector("#filtroFim").value;

    const filtros = {};
    if (produto) filtros.produto = produto;
    if (tipo) filtros.tipo = tipo;
    if (inicio && fim) {
        filtros.inicio = inicio;
        filtros.fim = fim;
    }

    listarMovimentacoes(filtros);
}

// ================= EXPORTAÇÃO =================
function exportar(tipo) {
    const produto = document.querySelector("#filtroProduto").value.trim();
    const filtroTipo = document.querySelector("#filtroTipo").value;
    const inicio = document.querySelector("#filtroInicio").value;
    const fim = document.querySelector("#filtroFim").value;

    const params = new URLSearchParams();
    params.append("acao", tipo === "pdf" ? "exportarpdf" : "exportarexcel");
    if (produto) params.append("produto", produto);
    if (filtroTipo) params.append("tipo", filtroTipo);
    if (inicio && fim) {
        params.append("inicio", inicio);
        params.append("fim", fim);
    }

    window.open("api/actions.php?" + params.toString(), "_blank");
}

// ================= INIT =================
document.addEventListener("DOMContentLoaded", () => {
    listarProdutos();
    listarMovimentacoes();
});
