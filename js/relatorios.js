// js/relatorios.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let graficoTemporal = null;

// ======= AJUSTES PRINCIPAIS (você pode alterar) =======
const DEFAULT_RANGE_DAYS = 30;     // ao abrir sem datas, usa últimos 30 dias
const MAX_POINTS_CHART = 120;      // máximo de labels no gráfico (acima disso, agrega)
const DEFAULT_LIMITE_TABELA = 200; // mantém seu padrão
// ======================================================

function formatBRL(valor) {
  const n = Number(valor || 0);
  return n.toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function pad2(n) {
  return String(n).padStart(2, "0");
}

function toYMD(dateObj) {
  const y = dateObj.getFullYear();
  const m = pad2(dateObj.getMonth() + 1);
  const d = pad2(dateObj.getDate());
  return `${y}-${m}-${d}`;
}

function setDefaultDatesIfEmpty() {
  const elIni = document.getElementById("dataInicio");
  const elFim = document.getElementById("dataFim");
  if (!elIni || !elFim) return;

  const hasIni = !!elIni.value;
  const hasFim = !!elFim.value;

  // Se usuário já preencheu algo, não mexe
  if (hasIni || hasFim) return;

  const hoje = new Date();
  const ini = new Date();
  ini.setDate(hoje.getDate() - DEFAULT_RANGE_DAYS);

  elFim.value = toYMD(hoje);
  elIni.value = toYMD(ini);
}

function getFiltros() {
  return {
    data_inicio: document.getElementById("dataInicio")?.value || "",
    data_fim: document.getElementById("dataFim")?.value || "",
    tipo: document.getElementById("tipo")?.value || "",
    pagina: 1,
    limite: DEFAULT_LIMITE_TABELA
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

/**
 * Agrega arrays por blocos quando há pontos demais.
 * Mantém o "shape" do gráfico e reduz drasticamente o custo de render.
 * Ex: 800 dias -> 120 pontos (cada ponto = soma de ~6-7 dias).
 */
function downsampleGrafico({ labels, entrada, saida, remocao }, maxPoints) {
  const n = labels.length;
  if (n <= maxPoints) return { labels, entrada, saida, remocao };

  const bucketSize = Math.ceil(n / maxPoints);

  const newLabels = [];
  const newEntrada = [];
  const newSaida = [];
  const newRemocao = [];

  for (let i = 0; i < n; i += bucketSize) {
    const jEnd = Math.min(i + bucketSize, n);

    let sumE = 0, sumS = 0, sumR = 0;
    for (let j = i; j < jEnd; j++) {
      sumE += Number(entrada[j] || 0);
      sumS += Number(saida[j] || 0);
      sumR += Number(remocao[j] || 0);
    }

    // label representativa: "2026-01-01..2026-01-07"
    const first = labels[i];
    const last = labels[jEnd - 1];
    newLabels.push(i === jEnd - 1 ? String(first) : `${first}..${last}`);

    newEntrada.push(sumE);
    newSaida.push(sumS);
    newRemocao.push(sumR);
  }

  return { labels: newLabels, entrada: newEntrada, saida: newSaida, remocao: newRemocao };
}

function renderGraficoTemporal(graf) {
  const canvas = document.getElementById("graficoTemporal");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const Chart = window.Chart;

  let labels = Array.isArray(graf?.labels) ? graf.labels : [];
  let entrada = Array.isArray(graf?.entrada) ? graf.entrada : [];
  let saida = Array.isArray(graf?.saida) ? graf.saida : [];
  let remocao = Array.isArray(graf?.remocao) ? graf.remocao : [];

  // Se não tiver nada, destrói gráfico antigo e sai
  if (!labels.length) {
    if (graficoTemporal) {
      graficoTemporal.destroy();
      graficoTemporal = null;
    }
    return;
  }

  // Se tiver MUITOS pontos, agrega para não travar o navegador
  if (labels.length > MAX_POINTS_CHART) {
    const reduced = downsampleGrafico({ labels, entrada, saida, remocao }, MAX_POINTS_CHART);
    labels = reduced.labels;
    entrada = reduced.entrada;
    saida = reduced.saida;
    remocao = reduced.remocao;
  }

  const data = {
    labels,
    datasets: [
      { label: "Entrada (Qtd)", data: entrada },
      { label: "Saída (Qtd)", data: saida },
      { label: "Remoção (Qtd)", data: remocao }
    ]
  };

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    // PERFORMANCE
    animation: false,
    parsing: false,
    normalized: true,
    plugins: {
      legend: { position: "bottom" },
      tooltip: { mode: "index", intersect: false }
    },
    interaction: { mode: "index", intersect: false },
    scales: {
      y: { beginAtZero: true }
    }
  };

  // Em vez de destruir e recriar sempre, atualiza se possível (mais leve)
  if (graficoTemporal) {
    graficoTemporal.data = data;
    graficoTemporal.options = options;
    graficoTemporal.update("none"); // update sem animação
    return;
  }

  graficoTemporal = new Chart(ctx, {
    type: "bar",
    data,
    options
  });
}

async function carregarRelatorio() {
  const filtros = getFiltros();

  // loading na tabela
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
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

// Debounce para evitar várias chamadas seguidas em change/input
function debounce(fn, delay = 350) {
  let t = null;
  return (...args) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => fn(...args), delay);
  };
}

function bindEventos() {
  const btn = document.getElementById("btnAplicarFiltros");
  if (btn) btn.addEventListener("click", () => carregarRelatorio());

  const carregarDebounced = debounce(carregarRelatorio, 350);

  // aplicar automaticamente ao mudar filtros (com debounce)
  document.getElementById("dataInicio")?.addEventListener("change", carregarDebounced);
  document.getElementById("dataFim")?.addEventListener("change", carregarDebounced);
  document.getElementById("tipo")?.addEventListener("change", carregarDebounced);
}

document.addEventListener("DOMContentLoaded", () => {
  bindEventos();

  // ✅ Aqui está o principal: não carrega "tudo" por padrão
  setDefaultDatesIfEmpty();

  carregarRelatorio();
});