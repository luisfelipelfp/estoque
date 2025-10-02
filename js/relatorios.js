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
async function carregarRelatorio() {
  const filtros = {
    data_inicio: document.getElementById("dataInicio").value,
    data_fim: document.getElementById("dataFim").value,
    usuario_id: document.getElementById("usuario").value,
    produto_id: document.getElementById("produto").value,
    tipo: document.getElementById("tipo").value
  };

  const query = new URLSearchParams(filtros).toString();
  const resp = await apiFetch("api/actions.php?acao=relatorio_movimentacoes&" + query);
  const res = await resp.json();

  const tbody = document.querySelector("#tabelaRelatorios tbody");
  tbody.innerHTML = "";

  // ✅ Agora acessa res.dados direto, pois actions.php -> relatorio() já retorna no formato correto
  const dados = (res.sucesso && Array.isArray(res.dados)) ? res.dados : [];

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
      <td>${item.produto}</td>
      <td>${item.tipo}</td>
      <td>${item.quantidade}</td>
      <td>${item.usuario}</td>
    `;
    tbody.appendChild(tr);
  });

  atualizarGraficos(dados);
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
document.getElementById("btn-filtrar").addEventListener("click", carregarRelatorio);

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
