// script.js

async function requisicao(dados) {
    const resposta = await fetch("actions.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(dados)
    });
    return await resposta.json();
}

// ===================== PRODUTOS =====================

// Listar produtos
async function listar() {
    const produtos = await requisicao({ acao: "listar" });
    const corpo = document.querySelector("#tabelaProdutos tbody");
    corpo.innerHTML = "";
    produtos.forEach(p => {
        corpo.innerHTML += `
            <tr>
                <td>${p.id}</td>
                <td>${p.nome}</td>
                <td>${p.quantidade}</td>
            </tr>
        `;
    });
}

// Cadastrar produto
async function cadastrar() {
    const nome = document.getElementById("nome").value;
    const qtd = document.getElementById("quantidade").value;
    await requisicao({ acao: "cadastrar", nome, qtd });
    listar();
    fecharModal("modalCadastro");
}

// Entrada
async function entrada() {
    const nome = document.getElementById("nomeEntrada").value;
    const qtd = document.getElementById("qtdEntrada").value;
    await requisicao({ acao: "entrada", nome, qtd });
    listar();
    fecharModal("modalEntrada");
}

// Saída
async function saida() {
    const nome = document.getElementById("nomeSaida").value;
    const qtd = document.getElementById("qtdSaida").value;
    await requisicao({ acao: "saida", nome, qtd });
    listar();
    fecharModal("modalSaida");
}

// Remover
async function remover() {
    const nome = document.getElementById("nomeRemover").value;
    await requisicao({ acao: "remover", nome });
    listar();
    fecharModal("modalRemover");
}

// ===================== RELATÓRIO =====================

// Gerar relatório
async function relatorio() {
    const inicio = document.getElementById("inicio").value;
    const fim = document.getElementById("fim").value;

    const dados = await requisicao({ acao: "relatorio", inicio, fim });

    const relatorioDiv = document.getElementById("reportContent");
    relatorioDiv.innerHTML = "";

    if (dados.length === 0) {
        relatorioDiv.innerHTML = "<p>Nenhum registro encontrado.</p>";
    } else {
        let tabela = `
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Produto</th>
                        <th>Quantidade</th>
                        <th>Tipo</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
        `;
        dados.forEach(m => {
            tabela += `
                <tr>
                    <td>${m.id}</td>
                    <td>${m.produto_id ?? m.nome ?? "-"}</td>
                    <td>${m.quantidade}</td>
                    <td>${m.tipo}</td>
                    <td>${m.data}</td>
                </tr>
            `;
        });
        tabela += "</tbody></table>";
        relatorioDiv.innerHTML = tabela;
    }
}

// ===================== MODAIS =====================

function abrirModal(id) {
    document.getElementById(id).style.display = "flex";
}

function fecharModal(id) {
    document.getElementById(id).style.display = "none";
}

// ===================== INICIALIZAÇÃO =====================

document.addEventListener("DOMContentLoaded", listar);
