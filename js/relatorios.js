// js/relatorios.js

async function apiFetch(url, options = {}) {
  return fetch(url, { ...options, credentials: "include" });
}

// -------------------------
// Carregar selects
// -------------------------
async function carregarUsuarios() {
  try {
    const resp = await apiFetch("api/actions.php?acao=listar_usuarios");
    const res = await resp.json();
    const select = document.getElementById("usuario");

    if (res.sucesso && Array.isArray(res.dados)) {
      res.dados.forEach(u => {
        const opt = document.createElement("option");
        opt.value = u.id;
        opt.textContent = u.nome;
        select.appendChild(opt);
      });
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

    if (res.sucesso && Array.isArray(res.dados)) {
      res.dados.forEach(p => {
        const opt = document.createElement("option");
        opt.value = p.id;
        opt.textContent = p.nome;
        select.appendChild(opt);
      });
    }
  } catch (err) {
    console.error("Erro ao carregar produtos:", err);
  }
}

// -------------------------
// Carregar relatório
// -------------------------
async function carregarRelatorio(pagina = 1) {
  const filtros = {
    data_inicio: document.getElementById("dataInicio").value,
    data_fim: document.getElementById("dataFim").value,
    usuario_id: document.getElementById("usuario").value,
    produto_id: document.getElementById("produto").value,
    tipo: document.getElementById("tipo").value,
    pagina: pagina,
    limite: 50
  };

  const query = new URLSearchParams(filtros).toString();
  const resp = await apiFetch("api/actions.php?acao=relatorio_movimentacoes&" + query);
  const res = await resp.json();

  const tbody = document.querySelector("#tabelaRelatorios tbody");
  tbody.innerHTML = "";

  // Agora o backend retorna dados + metadados
  const dados = (res.sucesso && res.dados && Array.isArray(res.dados)) ? res.dados : [];

  if (dados.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted">Nenhum registro encontrado</td></tr>`;
    atualizarGraficos([]);
    return;
  }

  dados.forEach(item => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${item.id}</td>
      <td>${item.data}</td>
      <td>${item.produto_nome}</td>
      <td>${item.tipo}</td>
      <td>${item.quantidade}</td>
      <td>${item.usuario}</td>
    `;
    tbody.appendChild(tr);
  });

  atualizarGraficos(dados);

  // Se quiser implementar paginação:
  atualizarPaginacao(res.pagina, res.paginas);
}

// -------------------------
// Paginação (opcional)
// -------------------------
function atualizarPaginacao(pagina, paginas) {
  const div = document.getElementById("paginacao");
  if (!div) return;

  div.innerHTML = "";

  if (paginas <= 1) return;

  if (pagina > 1) {
    const btnPrev = document.createElement("button");
    btnPrev.textContent = "Anterior";
    btnPrev.className = "btn btn-secondary me-2";
    btnPrev.onclick = () => carregarRelatorio(pagina - 1);
    div.appendChild(btnPrev);
  }

  const span = document.createElement("span");
  span.textContent = `Página ${pagina} de ${paginas}`;
  div.appendChild(span);

  if (pagina < paginas) {
    const btnNext = document.createElement("button");
    btnNext.textContent = "Próxima";
    btnNext.className = "btn btn-secondary ms-2";
    btnNext.onclick = () => carregarRelatorio(pagina + 1);
    div.appendChild(btnNext);
  }
}

// -------------------------
// Gráficos
// -------------------------
let graficoBarras, graficoPizza;

function atualizarGraficos(dados) {
  const ctxBarras = document.getElementById("graficoBarras").getContext("2d");
  const ctxPizza = document.getElementById("graficoPizza").getContext("2d");

  const tipos = ["entrada", "saida", "remocao"];
  const contagem = { entrada: 0, saida: 0, remocao: 0 };
  dados.forEach(d => {
    if (contagem[d.tipo] !== undefined) contagem[d.tipo]++;
  });

  if (graficoBarras) graficoBarras.destroy();
  graficoBarras = new Chart(ctxBarras, {
    type: "bar",
    data: {
      labels: tipos,
      datasets: [{
        label: "Movimentações",
        data: tipos.map(t => contagem[t]),
        backgroundColor: ["#0d6efd", "#dc3545", "#6c757d"]
      }]
    }
  });

  if (graficoPizza) graficoPizza.destroy();
  graficoPizza = new Chart(ctxPizza, {
    type: "pie",
    data: {
      labels: tipos,
      datasets: [{
        data: tipos.map(t => contagem[t]),
        backgroundColor: ["#0d6efd", "#dc3545", "#6c757d"]
      }]
    }
  });
}

// -------------------------
// Eventos
// -------------------------
document.getElementById("btn-filtrar").addEventListener("click", () => carregarRelatorio(1));

document.getElementById("btn-limpar").addEventListener("click", () => {
  document.querySelector("#tabelaRelatorios tbody").innerHTML =
    `<tr><td colspan="6" class="text-center text-muted">Use os filtros para buscar movimentações</td></tr>`;
  atualizarGraficos([]);
});

// -------------------------
// Inicialização
// -------------------------
carregarUsuarios();
carregarProdutos();
carregarRelatorio(1);
