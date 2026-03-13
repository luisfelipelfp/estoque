// js/dashboard.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let graficoMovimentacoes = null;

function $(id) {
  return document.getElementById(id);
}

function escapeHtml(valor) {
  return String(valor ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function formatBRL(valor) {
  const n = Number(valor || 0);
  return `R$ ${n.toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })}`;
}

function setTexto(id, valor) {
  const el = $(id);
  if (el) {
    el.textContent = String(valor ?? "");
  }
}

function destruirGrafico() {
  if (graficoMovimentacoes) {
    graficoMovimentacoes.destroy();
    graficoMovimentacoes = null;
  }
}

function renderGrafico(labels = [], entradas = [], saidas = []) {
  const canvas = $("graficoMovimentacoes");
  if (!canvas || !window.Chart) return;

  destruirGrafico();

  const ctx = canvas.getContext("2d");
  if (!ctx) return;

  graficoMovimentacoes = new window.Chart(ctx, {
    type: "bar",
    data: {
      labels,
      datasets: [
        {
          label: "Entradas",
          data: entradas
        },
        {
          label: "Saídas",
          data: saidas
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      },
      scales: {
        y: {
          beginAtZero: true
        }
      }
    }
  });
}

function renderRanking(produtos = []) {
  const box = $("rankingProdutos");
  if (!box) return;

  if (!Array.isArray(produtos) || produtos.length === 0) {
    box.innerHTML = `
      <div class="text-muted small">
        Nenhum dado disponível.
      </div>
    `;
    return;
  }

  box.innerHTML = produtos.map((item, index) => {
    return `
      <div class="dashboard-ranking-item">
        <div class="dashboard-ranking-posicao">${index + 1}</div>

        <div class="dashboard-ranking-conteudo">
          <div class="dashboard-ranking-titulo">
            ${escapeHtml(item?.produto_nome ?? "-")}
          </div>

          <div class="dashboard-ranking-subtexto">
            Quantidade vendida: <strong>${Number(item?.quantidade_total ?? 0)}</strong>
          </div>

          <div class="dashboard-ranking-subtexto">
            Lucro: <strong>${formatBRL(item?.lucro_total ?? 0)}</strong>
          </div>
        </div>
      </div>
    `;
  }).join("");
}

function preencherKPIs(payload) {
  const kpis = payload?.kpis || {};

  setTexto("kpiProdutos", Number(kpis?.produtos_ativos ?? 0));
  setTexto("kpiMovimentacoes", Number(kpis?.movimentacoes_total ?? 0));
  setTexto("kpiFaturamento", formatBRL(kpis?.faturamento_total ?? 0));
  setTexto("kpiLucro", formatBRL(kpis?.lucro_total ?? 0));
}

function preencherGrafico(payload) {
  const grafico = payload?.grafico_movimentacoes_30dias || {};

  const labels = Array.isArray(grafico?.labels) ? grafico.labels : [];
  const entradas = Array.isArray(grafico?.entradas) ? grafico.entradas : [];
  const saidas = Array.isArray(grafico?.saidas) ? grafico.saidas : [];

  renderGrafico(labels, entradas, saidas);
}

function preencherRanking(payload) {
  const ranking = Array.isArray(payload?.ranking_produtos_saida)
    ? payload.ranking_produtos_saida
    : [];

  renderRanking(ranking);
}

async function carregarDashboard() {
  try {
    const resp = await apiRequest("dashboard_resumo", {}, "GET");

    if (!resp?.sucesso) {
      preencherKPIs({});
      renderGrafico([], [], []);
      renderRanking([]);

      logJsError({
        origem: "dashboard.js",
        mensagem: "Falha ao carregar dashboard",
        detalhe: resp?.mensagem || "Resposta inválida da API"
      });
      return;
    }

    const payload = resp?.dados || {};

    preencherKPIs(payload);
    preencherGrafico(payload);
    preencherRanking(payload);

    logJsInfo({
      origem: "dashboard.js",
      mensagem: "Dashboard carregado com sucesso"
    });
  } catch (err) {
    preencherKPIs({});
    renderGrafico([], [], []);
    renderRanking([]);

    logJsError({
      origem: "dashboard.js",
      mensagem: "Erro ao carregar dashboard",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  await carregarDashboard();
});