// js/relatorios.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let filtrosAtuais = {};
let graficoBarras = null;
let graficoPizza = null;

/**
 * ==========================
 * Carregar usuários
 * ==========================
 */
async function carregarUsuarios() {
  try {
    const resp = await apiRequest("listar_usuarios", null, "GET");
    const select = document.getElementById("usuario");
    if (!select) return;

    select.innerHTML = `<option value="">Todos</option>`;

    if (resp?.sucesso && Array.isArray(resp?.dados)) {
      resp.dados.forEach(u => {
        if (u?.id && u?.nome) {
          select.insertAdjacentHTML(
            "beforeend",
            `<option value="${u.id}">${u.nome}</option>`
          );
        }
      });
    }

    logJsInfo({
      origem: "relatorios.js",
      mensagem: "Usuários carregados",
      total: resp?.dados?.length || 0
    });

  } catch (err) {
    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro ao carregar usuários",
      detalhe: err.message,
      stack: err.stack
    });
  }
}

/**
 * ==========================
 * Carregar produtos
 * ==========================
 */
async function carregarProdutos() {
  try {
    const resp = await apiRequest("listar_produtos", null, "GET");
    const select = document.getElementById("produto");
    if (!select) return;

    select.innerHTML = `<option value="">Todos</option>`;

    if (resp?.sucesso && Array.isArray(resp?.dados)) {
      resp.dados.forEach(p => {
        const id = p?.id ?? "";
        const nome =
          p?.nome?.trim?.() ||
          p?.nome_produto?.trim?.() ||
          p?.produto?.trim?.() ||
          "[Sem nome]";

        select.insertAdjacentHTML(
          "beforeend",
          `<option value="${id}">${nome}</option>`
        );
      });
    }

    logJsInfo({
      origem: "relatorios.js",
      mensagem: "Produtos carregados",
      total: resp?.dados?.length || 0
    });

  } catch (err) {
    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro ao carregar produtos",
      detalhe: err.message,
      stack: err.stack
    });
  }
}

/**
 * ==========================
 * Carregar relatório
 * ==========================
 */
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
  if (!tbody) return;

  tbody.innerHTML = `
    <tr>
      <td colspan="6" class="text-center text-muted">
        Carregando...
      </td>
    </tr>`;

  try {
    const resp = await apiRequest(
      "relatorio_movimentacoes",
      filtrosAtuais,
      "GET"
    );

    tbody.innerHTML = "";

    if (!resp?.sucesso || !Array.isArray(resp?.dados) || !resp.dados.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="text-center text-muted">
            Nenhum registro encontrado com os filtros aplicados.
          </td>
        </tr>`;
      atualizarGraficos({});
      atualizarPaginacao(1, 1);
      return;
    }

    resp.dados.forEach(item => {
      const tipoClass =
        item.tipo === "entrada"
          ? "text-success"
          : item.tipo === "saida"
          ? "text-danger"
          : "text-secondary";

      tbody.insertAdjacentHTML(
        "beforeend",
        `
        <tr>
          <td>${item.id}</td>
          <td>${item.data}</td>
          <td>${item.produto_nome ?? item.produto ?? "-"}</td>
          <td class="${tipoClass} fw-bold">${item.tipo}</td>
          <td>${item.quantidade}</td>
          <td>${item.usuario ?? "Sistema"}</td>
        </tr>`
      );
    });

    atualizarGraficos(resp.grafico || {});
    atualizarPaginacao(resp.pagina || 1, resp.paginas || 1);

    logJsInfo({
      origem: "relatorios.js",
      mensagem: "Relatório carregado",
      total: resp.dados.length,
      filtros: filtrosAtuais
    });

  } catch (err) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-danger">
          Erro ao carregar relatório
        </td>
      </tr>`;

    logJsError({
      origem: "relatorios.js",
      mensagem: "Falha ao carregar relatório",
      detalhe: err.message,
      stack: err.stack,
      filtros: filtrosAtuais
    });
  }
}

/**
 * ==========================
 * Paginação
 * ==========================
 */
function atualizarPaginacao(pagina, paginas) {
  const div = document.getElementById("paginacao");
  if (!div) return;

  div.innerHTML = "";
  if (paginas <= 1) return;

  if (pagina > 1) {
    div.insertAdjacentHTML(
      "beforeend",
      `<button class="btn btn-secondary me-2" onclick="carregarRelatorio(${pagina - 1})">
        Anterior
      </button>`
    );
  }

  div.insertAdjacentHTML(
    "beforeend",
    `<span class="fw-bold">Página ${pagina} de ${paginas}</span>`
  );

  if (pagina < paginas) {
    div.insertAdjacentHTML(
      "beforeend",
      `<button class="btn btn-secondary ms-2" onclick="carregarRelatorio(${pagina + 1})">
        Próxima
      </button>`
    );
  }
}

/**
 * ==========================
 * Gráficos
 * ==========================
 */
function atualizarGraficos(data = {}) {
  const ctxB = document.getElementById("graficoBarras")?.getContext("2d");
  const ctxP = document.getElementById("graficoPizza")?.getContext("2d");
  if (!ctxB || !ctxP) return;

  const tipos = ["entrada", "saida", "remocao"];
  const valores = tipos.map(t => data[t] ?? 0);

  if (graficoBarras) graficoBarras.destroy();
  graficoBarras = new Chart(ctxB, {
    type: "bar",
    data: {
      labels: ["Entrada", "Saída", "Remoção"],
      datasets: [{
        data: valores,
        backgroundColor: ["#0d6efd", "#dc3545", "#6c757d"]
      }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });

  if (graficoPizza) graficoPizza.destroy();
  graficoPizza = new Chart(ctxP, {
    type: "pie",
    data: {
      labels: ["Entrada", "Saída", "Remoção"],
      datasets: [{
        data: valores,
        backgroundColor: ["#0d6efd", "#dc3545", "#6c757d"]
      }]
    },
    options: { plugins: { legend: { position: "bottom" } } }
  });
}

/**
 * ==========================
 * Botões
 * ==========================
 */
document.getElementById("btn-filtrar")
  ?.addEventListener("click", () => carregarRelatorio(1));

document.getElementById("btn-limpar")
  ?.addEventListener("click", () => {
    ["dataInicio", "dataFim", "usuario", "produto", "tipo"].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = "";
    });

    document.querySelector("#tabelaRelatorios tbody").innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted">
          Use os filtros para buscar movimentações
        </td>
      </tr>`;

    atualizarGraficos({});
    atualizarPaginacao(1, 1);
  });

document.getElementById("btn-pdf")
  ?.addEventListener("click", () => {
    const q = new URLSearchParams(filtrosAtuais).toString();
    window.open(`api/exportar.php?tipo=pdf&${q}`, "_blank");
  });

document.getElementById("btn-excel")
  ?.addEventListener("click", () => {
    const q = new URLSearchParams(filtrosAtuais).toString();
    window.open(`api/exportar.php?tipo=excel&${q}`, "_blank");
  });

/**
 * ==========================
 * Inicialização
 * ==========================
 */
document.addEventListener("DOMContentLoaded", () => {
  carregarUsuarios();
  carregarProdutos();
  carregarRelatorio(1);
});

/**
 * 🔑 Exposição mínima (onclick da paginação)
 */
window.carregarRelatorio = carregarRelatorio;
