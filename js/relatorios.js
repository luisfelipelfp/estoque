// js/relatorios.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let graficoTemporal = null;

function formatBRL(valor) {
  const n = Number(valor || 0);
  return n.toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function getFiltros() {
  return {
    data_inicio: document.getElementById("dataInicio")?.value || "",
    data_fim: document.getElementById("dataFim")?.value || "",
    tipo: document.getElementById("tipo")?.value || "",
    pagina: 1,
    limite: 200
  };
}

function renderTotais(totais) {
  const elQtd = document.getElementById("totalQtd");
  const elValor = document.getElementById("totalValor");

  const totalQtd = totais?.total_qtd ?? 0;
  const totalValor = totais?.total_valor ?? 0;

  if (elQtd) elQtd.textContent = String(totalQtd);
  if (elValor) elValor.textContent = formatBRL(totalValor);
}

function renderTabela(dados = []) {
  const tbody = document.getElementById("tabelaMov");
  if (!tbody) return;

  if (!Array.isArray(dados) || dados.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" class="text-center text-muted">
          Nenhum registro encontrado com os filtros aplicados.
        </td>
      </tr>`;
    return;
  }

  tbody.innerHTML = "";

  for (const item of dados) {
    const tipo = item?.tipo || "-";
    const tipoBadge =
      tipo === "entrada"
        ? `<span class="badge bg-success">entrada</span>`
        : tipo === "saida"
        ? `<span class="badge bg-danger">saída</span>`
        : `<span class="badge bg-secondary">${tipo}</span>`;

    const valorUnit =
      item?.valor_unitario === null || item?.valor_unitario === undefined
        ? "-"
        : `R$ ${formatBRL(item.valor_unitario)}`;

    const valorTot =
      item?.valor_total === null || item?.valor_total === undefined
        ? "-"
        : `R$ ${formatBRL(item.valor_total)}`;

    tbody.insertAdjacentHTML(
      "beforeend",
      `
      <tr>
        <td>${item?.id ?? "-"}</td>
        <td>${item?.produto_nome ?? "-"}</td>
        <td>${tipoBadge}</td>
        <td>${item?.quantidade ?? 0}</td>
        <td>${valorUnit}</td>
        <td>${valorTot}</td>
        <td>${item?.data ?? "-"}</td>
        <td>${item?.usuario ?? "Sistema"}</td>
      </tr>
      `
    );
  }
}

function renderGraficoTemporal(graf) {
  const canvas = document.getElementById("graficoTemporal");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const Chart = window.Chart;

  const labels = Array.isArray(graf?.labels) ? graf.labels : [];
  const entrada = Array.isArray(graf?.entrada) ? graf.entrada : [];
  const saida = Array.isArray(graf?.saida) ? graf.saida : [];
  const remocao = Array.isArray(graf?.remocao) ? graf.remocao : [];

  // Se não tiver nada, destrói gráfico antigo e mostra vazio
  if (!labels.length) {
    if (graficoTemporal) {
      graficoTemporal.destroy();
      graficoTemporal = null;
    }
    return;
  }

  if (graficoTemporal) {
    graficoTemporal.destroy();
  }

  graficoTemporal = new Chart(ctx, {
    type: "bar",
    data: {
      labels,
      datasets: [
        {
          label: "Entrada (Qtd)",
          data: entrada
        },
        {
          label: "Saída (Qtd)",
          data: saida
        },
        {
          label: "Remoção (Qtd)",
          data: remocao
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: "bottom" }
      },
      scales: {
        y: { beginAtZero: true }
      }
    }
  });
}

async function carregarRelatorio() {
  const filtros = getFiltros();

  // carrega estado "loading" na tabela
  const tbody = document.getElementById("tabelaMov");
  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" class="text-center text-muted">
          Carregando...
        </td>
      </tr>`;
  }

  try {
    const resp = await apiRequest("relatorio_movimentacoes", filtros, "GET");

    if (!resp?.sucesso) {
      renderTotais({ total_qtd: 0, total_valor: 0 });
      renderTabela([]);
      renderGraficoTemporal({ labels: [], entrada: [], saida: [], remocao: [] });

      logJsError({
        origem: "relatorios.js",
        mensagem: resp?.mensagem || "Falha ao carregar relatório",
        filtros
      });
      return;
    }

    // ✅ Importante: sua API retorna { sucesso, mensagem, dados: {...} }
    const payload = resp?.dados || {};

    renderTotais(payload?.totais);
    renderTabela(payload?.dados);
    renderGraficoTemporal(payload?.grafico_temporal);

    logJsInfo({
      origem: "relatorios.js",
      mensagem: "Relatório carregado",
      filtros,
      total: payload?.total ?? 0
    });
  } catch (err) {
    renderTotais({ total_qtd: 0, total_valor: 0 });
    renderTabela([]);
    renderGraficoTemporal({ labels: [], entrada: [], saida: [], remocao: [] });

    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro inesperado ao carregar relatório",
      detalhe: err.message,
      stack: err.stack
    });
  }
}

function bindEventos() {
  document
    .getElementById("btnAplicarFiltros")
    ?.addEventListener("click", () => carregarRelatorio());

  // opcional: aplicar automaticamente ao mudar filtros
  document.getElementById("dataInicio")?.addEventListener("change", carregarRelatorio);
  document.getElementById("dataFim")?.addEventListener("change", carregarRelatorio);
  document.getElementById("tipo")?.addEventListener("change", carregarRelatorio);
}

document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  carregarRelatorio();
});