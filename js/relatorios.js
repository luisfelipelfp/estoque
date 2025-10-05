// js/relatorios.js — Relatórios com filtros, gráficos e paginação

async function apiFetch(url, options = {}) {
  try {
    const resp = await fetch(url, { ...options, credentials: "include" });
    if (!resp.ok) throw new Error(`Erro HTTP ${resp.status}`);
    return resp;
  } catch (err) {
    console.error("Erro de conexão com API:", err);
    throw err;
  }
}

// 🔹 Carregar selects de usuários e produtos
async function carregarUsuarios() {
  try {
    const resp = await apiFetch("api/actions.php?acao=listar_usuarios");
    const res = await resp.json();
    const select = document.getElementById("usuario");
    select.innerHTML = '<option value="">Todos</option>';
    if (res.sucesso && Array.isArray(res.dados)) {
      res.dados.forEach(u =>
        select.insertAdjacentHTML("beforeend", `<option value="${u.id}">${u.nome}</option>`)
      );
    }
  } catch (err) {
    console.error("Erro ao carregar usuários:", err);
  }
}

async function carregarProdutos() {
  try {
    const resp = await apiFetch("api/actions.php?acao=listar_produtos");
    const res = await resp.json();
    const select = document.getElementById("produto");
    select.innerHTML = '<option value="">Todos</option>';
    if (res.sucesso && Array.isArray(res.dados)) {
      res.dados.forEach(p =>
        select.insertAdjacentHTML("beforeend", `<option value="${p.id}">${p.nome}</option>`)
      );
    }
  } catch (err) {
    console.error("Erro ao carregar produtos:", err);
  }
}

let filtrosAtuais = {};

async function carregarRelatorio(pagina = 1) {
  filtrosAtuais = {
    data_inicio: document.getElementById("dataInicio")?.value || "",
    data_fim: document.getElementById("dataFim")?.value || "",
    usuario_id: document.getElementById("usuario")?.value || "",
    produto_id: document.getElementById("produto")?.value || "",
    tipo: document.getElementById("tipo")?.value || "",
    pagina,
    limite: 50
  };

  const tbody = document.querySelector("#tabelaRelatorios tbody");
  tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted">Carregando...</td></tr>`;

  try {
    const query = new URLSearchParams(filtrosAtuais).toString();
    const resp = await apiFetch("api/relatorios.php?" + query); // ✅ Agora aponta para relatorios.php
    const res = await resp.json();

    tbody.innerHTML = "";

    if (!res.sucesso || !res.dados?.length) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted">Nenhum registro encontrado</td></tr>`;
      atualizarGraficos({});
      atualizarPaginacao(1, 1);
      return;
    }

    res.dados.forEach(item => {
      tbody.insertAdjacentHTML("beforeend", `
        <tr>
          <td>${item.id}</td>
          <td>${item.data}</td>
          <td>${item.produto_nome}</td>
          <td class="${item.tipo === "entrada" ? "text-success" : item.tipo === "saida" ? "text-danger" : "text-secondary"} fw-bold">${item.tipo}</td>
          <td>${item.quantidade}</td>
          <td>${item.usuario}</td>
        </tr>
      `);
    });

    atualizarGraficos(res.grafico || {});
    atualizarPaginacao(res.pagina, res.paginas || 1);
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Erro ao carregar relatório</td></tr>`;
    console.error(err);
  }
}

// 🔹 Paginação
function atualizarPaginacao(pagina, paginas) {
  const div = document.getElementById("paginacao");
  div.innerHTML = "";
  if (paginas <= 1) return;

  if (pagina > 1)
    div.insertAdjacentHTML("beforeend", `<button class="btn btn-secondary me-2" onclick="carregarRelatorio(${pagina - 1})">Anterior</button>`);

  div.insertAdjacentHTML("beforeend", `<span class="fw-bold">Página ${pagina} de ${paginas}</span>`);

  if (pagina < paginas)
    div.insertAdjacentHTML("beforeend", `<button class="btn btn-secondary ms-2" onclick="carregarRelatorio(${pagina + 1})">Próxima</button>`);
}

// 🔹 Gráficos
let graficoBarras, graficoPizza;
function atualizarGraficos(data) {
  const ctxB = document.getElementById("graficoBarras")?.getContext("2d");
  const ctxP = document.getElementById("graficoPizza")?.getContext("2d");
  if (!ctxB || !ctxP) return;

  const tipos = ["entrada", "saida", "remocao"];
  const contagem = tipos.map(t => data[t] ?? 0);

  if (graficoBarras) graficoBarras.destroy();
  graficoBarras = new Chart(ctxB, {
    type: "bar",
    data: {
      labels: ["Entrada", "Saída", "Remoção"],
      datasets: [{ label: "Movimentações", data: contagem, backgroundColor: ["#0d6efd", "#dc3545", "#6c757d"] }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });

  if (graficoPizza) graficoPizza.destroy();
  graficoPizza = new Chart(ctxP, {
    type: "pie",
    data: { labels: ["Entrada", "Saída", "Remoção"], datasets: [{ data: contagem, backgroundColor: ["#0d6efd", "#dc3545", "#6c757d"] }] },
    options: { plugins: { legend: { position: "bottom" } } }
  });
}

// 🔹 Botões
document.getElementById("btn-filtrar")?.addEventListener("click", () => carregarRelatorio(1));

document.getElementById("btn-limpar")?.addEventListener("click", () => {
  ["dataInicio", "dataFim", "usuario", "produto", "tipo"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = "";
  });
  document.querySelector("#tabelaRelatorios tbody").innerHTML =
    `<tr><td colspan="6" class="text-center text-muted">Use os filtros para buscar movimentações</td></tr>`;
  atualizarGraficos({});
  atualizarPaginacao(1, 1);
});

document.getElementById("btn-pdf")?.addEventListener("click", () => {
  const q = new URLSearchParams(filtrosAtuais).toString();
  window.open("api/exportar.php?tipo=pdf&" + q, "_blank");
});

document.getElementById("btn-excel")?.addEventListener("click", () => {
  const q = new URLSearchParams(filtrosAtuais).toString();
  window.open("api/exportar.php?tipo=excel&" + q, "_blank");
});

// 🔹 Inicialização
carregarUsuarios();
carregarProdutos();
carregarRelatorio(1);
