const API_URL = "../api/actions.php";

// Função para cadastrar produto
document.getElementById("form-cadastro").addEventListener("submit", async (e) => {
  e.preventDefault();
  const nome = document.getElementById("cad-nome").value;
  const quantidade = document.getElementById("cad-qtd").value;

  await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "cadastrar", nome, quantidade })
  });

  listarProdutos();
  e.target.reset();
});

// Função para movimentar produto
document.getElementById("form-movimentar").addEventListener("submit", async (e) => {
  e.preventDefault();
  const nome = document.getElementById("mov-nome").value;
  const quantidade = document.getElementById("mov-qtd").value;
  const tipo = document.querySelector("input[name='tipo']:checked").value;

  await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "movimentar", nome, quantidade, tipo })
  });

  listarProdutos();
  listarMovimentacoes();
  e.target.reset();
});

// Função para remover produto
document.getElementById("form-remover").addEventListener("submit", async (e) => {
  e.preventDefault();
  const nome = document.getElementById("rem-nome").value;

  await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "remover", nome })
  });

  listarProdutos();
  listarMovimentacoes();
  e.target.reset();
});

// Função para gerar relatório
document.getElementById("form-relatorio").addEventListener("submit", async (e) => {
  e.preventDefault();
  const inicio = document.getElementById("rel-inicio").value;
  const fim = document.getElementById("rel-fim").value;

  const res = await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "relatorio", inicio, fim })
  });

  const dados = await res.json();
  const tbody = document.querySelector("#tabela-relatorio tbody");
  tbody.innerHTML = "";

  dados.forEach((m) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${m.id}</td>
                    <td>${m.nome}</td>
                    <td>${m.quantidade}</td>
                    <td>${m.tipo}</td>
                    <td>${m.data}</td>
                    <td>${m.status}</td>`;
    tbody.appendChild(tr);
  });
});

// Listar produtos
async function listarProdutos() {
  const res = await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "listarProdutos" })
  });

  const produtos = await res.json();
  const tbody = document.querySelector("#tabela-produtos tbody");
  tbody.innerHTML = "";

  produtos.forEach((p) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${p.id}</td><td>${p.nome}</td><td>${p.quantidade}</td>`;
    tbody.appendChild(tr);
  });
}

// Listar movimentações
async function listarMovimentacoes() {
  const res = await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "listarMovimentacoes" })
  });

  const movs = await res.json();
  const tbody = document.querySelector("#tabela-relatorio tbody");
  tbody.innerHTML = "";

  movs.forEach((m) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${m.id}</td>
                    <td>${m.nome}</td>
                    <td>${m.quantidade}</td>
                    <td>${m.tipo}</td>
                    <td>${m.data}</td>
                    <td>${m.status}</td>`;
    tbody.appendChild(tr);
  });
}

// Inicializa
listarProdutos();
listarMovimentacoes();
