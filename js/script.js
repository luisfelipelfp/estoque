const url = "actions.php";

// Função genérica para enviar requisições
async function enviarAcao(acao, dados) {
    dados.acao = acao;
    const res = await fetch(url, {
        method: "POST",
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(dados)
    });
    return await res.json();
}

// Cadastro
async function cadastrarProduto() {
    const nome = document.getElementById("nome").value;
    const qtd = parseInt(document.getElementById("qtd").value);
    const resp = await enviarAcao('cadastrar', {nome, qtd});
    alert(resp.erro || "Produto cadastrado com sucesso!");
    listarProdutos();
}

// Entrada
async function entrada() {
    const nome = document.getElementById("nomeMov").value;
    const qtd = parseInt(document.getElementById("qtdMov").value);
    const resp = await enviarAcao('entrada', {nome, qtd});
    alert(resp.erro || "Entrada registrada!");
    listarProdutos();
}

// Saída
async function saida() {
    const nome = document.getElementById("nomeMov").value;
    const qtd = parseInt(document.getElementById("qtdMov").value);
    const resp = await enviarAcao('saida', {nome, qtd});
    alert(resp.erro || "Saída registrada!");
    listarProdutos();
}

// Listar produtos
async function listarProdutos() {
    const produtos = await enviarAcao('listar', {});
    const tbody = document.querySelector("#tabelaProdutos tbody");
    tbody.innerHTML = "";
    produtos.forEach(p => {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${p.id}</td><td>${p.nome}</td><td>${p.quantidade}</td>`;
        tbody.appendChild(tr);
    });
}

// Gerar relatório
async function gerarRelatorio() {
    const inicio = document.getElementById("inicio").value;
    const fim = document.getElementById("fim").value;
    const rel = await enviarAcao('relatorio', {inicio, fim});
    const tbody = document.querySelector("#tabelaRelatorio tbody");
    tbody.innerHTML = "";
    rel.forEach(m => {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${m.id}</td><td>${m.nome}</td><td>${m.quantidade}</td><td>${m.tipo}</td><td>${m.data}</td>`;
        tbody.appendChild(tr);
    });
}

// Inicializa
listarProdutos();
