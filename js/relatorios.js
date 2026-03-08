// js/relatorios.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let graficoTemporal = null;
let modalFiltrosInstance = null;

const DEFAULT_RANGE_DAYS = 30;
const MAX_POINTS_CHART = 120;
const DEFAULT_LIMITE_TABELA = 200;

function $(id) {
  return document.getElementById(id);
}

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
  return `${dateObj.getFullYear()}-${pad2(dateObj.getMonth() + 1)}-${pad2(dateObj.getDate())}`;
}

function setTexto(id, valor) {
  const el = $(id);
  if (el) {
    el.textContent = String(valor ?? "");
  }
}

function setStatusRelatorio(texto = "", tipo = "muted") {
  const el = $("statusRelatorio");
  if (!el) return;

  el.className = "small";
  if (!texto) {
    el.textContent = "";
    return;
  }

  if (tipo === "erro") {
    el.classList.add("text-danger");
  } else if (tipo === "sucesso") {
    el.classList.add("text-success");
  } else if (tipo === "processando") {
    el.classList.add("text-primary");
  } else {
    el.classList.add("text-muted");
  }

  el.textContent = texto;
}

function setBtnLoading(id, loading, textoOriginal = "") {
  const btn = $(id);
  if (!btn) return;

  if (!btn.dataset.originalText) {
    btn.dataset.originalText = textoOriginal || btn.textContent || "";
  }

  btn.disabled = !!loading;
  btn.textContent = loading ? "Processando..." : btn.dataset.originalText;
}

function getModalFiltros() {
  const el = $("modalFiltrosRelatorio");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalFiltrosInstance) {
    modalFiltrosInstance = new window.bootstrap.Modal(el);
  }

  return modalFiltrosInstance;
}

function setDefaultDatesIfEmpty() {
  const elIni = $("dataInicio");
  const elFim = $("dataFim");
  if (!elIni || !elFim) return;
  if (elIni.value || elFim.value) return;

  const hoje = new Date();
  const ini = new Date();
  ini.setDate(hoje.getDate() - DEFAULT_RANGE_DAYS);

  elFim.value = toYMD(hoje);
  elIni.value = toYMD(ini);
}

function getFiltros() {
  return {
    data_inicio: $("dataInicio")?.value || "",
    data_fim: $("dataFim")?.value || "",
    tipo: $("tipo")?.value || "",
    pagina: 1,
    limite: DEFAULT_LIMITE_TABELA
  };
}

function atualizarResumoFiltros() {
  const filtros = getFiltros();

  const partes = [];
  const badgePartes = [];

  if (filtros.data_inicio && filtros.data_fim) {
    partes.push(`período de ${filtros.data_inicio} até ${filtros.data_fim}`);
    badgePartes.push(`${filtros.data_inicio} → ${filtros.data_fim}`);
  } else if (filtros.data_inicio) {
    partes.push(`a partir de ${filtros.data_inicio}`);
    badgePartes.push(`desde ${filtros.data_inicio}`);
  } else if (filtros.data_fim) {
    partes.push(`até ${filtros.data_fim}`);
    badgePartes.push(`até ${filtros.data_fim}`);
  } else {
    partes.push("período padrão dos últimos 30 dias");
    badgePartes.push("Últimos 30 dias");
  }

  if (filtros.tipo) {
    partes.push(`tipo ${filtros.tipo}`);
    badgePartes.push(filtros.tipo);
  } else {
    partes.push("todos os tipos");
  }

  setTexto("resumoFiltrosTexto", `${partes.join(", ")}.`);
  setTexto("badgePeriodoRelatorio", badgePartes.join(" • "));
}

/* =========================
   EXPORTAÇÃO
   ========================= */

function avisarExportacaoNaoDisponivel(tipo) {
  const msg = `${tipo} ainda não está implementado no backend atual.`;
  window.alert(msg);

  logJsInfo({
    origem: "relatorios.js",
    mensagem: "Exportação indisponível",
    tipo
  });
}

function exportarCSV() {
  avisarExportacaoNaoDisponivel("Exportação CSV");
}

function exportarPDF() {
  avisarExportacaoNaoDisponivel("Exportação PDF");
}

/* =========================
   FILTROS
   ========================= */

function limparFiltros() {
  if ($("dataInicio")) $("dataInicio").value = "";
  if ($("dataFim")) $("dataFim").value = "";
  if ($("tipo")) $("tipo").value = "";

  setDefaultDatesIfEmpty();
  atualizarResumoFiltros();
  carregarRelatorio();

  logJsInfo({
    origem: "relatorios.js",
    mensagem: "Filtros limpos"
  });
}

/* =========================
   GRÁFICO
   ========================= */

function toNumArray(arr, len) {
  const out = new Array(len);
  for (let i = 0; i < len; i++) {
    const n = Number(arr?.[i]);
    out[i] = Number.isFinite(n) ? n : 0;
  }
  return out;
}

function downsample({ labels, entrada, saida, remocao, outros }, maxPoints) {
  const n = labels.length;
  if (n <= maxPoints) return { labels, entrada, saida, remocao, outros };

  const bucket = Math.ceil(n / maxPoints);
  const L = [];
  const E = [];
  const S = [];
  const R = [];
  const O = [];

  for (let i = 0; i < n; i += bucket) {
    const end = Math.min(i + bucket, n);
    let se = 0, ss = 0, sr = 0, so = 0;

    for (let j = i; j < end; j++) {
      se += entrada[j] || 0;
      ss += saida[j] || 0;
      sr += remocao[j] || 0;
      so += outros[j] || 0;
    }

    const first = labels[i];
    const last = labels[end - 1];
    L.push(i === end - 1 ? String(first) : `${first}..${last}`);

    E.push(se);
    S.push(ss);
    R.push(sr);
    O.push(so);
  }

  return { labels: L, entrada: E, saida: S, remocao: R, outros: O };
}

function resetCanvas(canvas) {
  const parent = canvas.parentNode;
  const newCanvas = canvas.cloneNode(true);
  parent.replaceChild(newCanvas, canvas);
  return newCanvas;
}

function sum(arr) {
  return Array.isArray(arr)
    ? arr.reduce((a, b) => a + (Number(b) || 0), 0)
    : 0;
}

function renderGraficoTemporalVazio() {
  const canvas = $("graficoTemporal");
  if (!canvas) return;

  if (graficoTemporal) {
    graficoTemporal.destroy();
    graficoTemporal = null;
  }

  const ctx = canvas.getContext("2d");
  if (!ctx) return;

  ctx.clearRect(0, 0, canvas.width, canvas.height);
}

function renderGraficoTemporal(graf) {
  let canvas = $("graficoTemporal");
  if (!canvas) return;

  const Chart = window.Chart;
  if (!Chart) return;

  let labels = Array.isArray(graf?.labels) ? graf.labels : [];
  if (!labels.length) {
    renderGraficoTemporalVazio();
    return;
  }

  let entrada = toNumArray(graf?.entrada, labels.length);
  let saida = toNumArray(graf?.saida, labels.length);
  let remocao = toNumArray(graf?.remocao, labels.length);
  let outros = toNumArray(graf?.outros, labels.length);

  if (labels.length > MAX_POINTS_CHART) {
    const r = downsample({ labels, entrada, saida, remocao, outros }, MAX_POINTS_CHART);
    labels = r.labels;
    entrada = r.entrada;
    saida = r.saida;
    remocao = r.remocao;
    outros = r.outros;
  }

  const sE = sum(entrada);
  const sS = sum(saida);
  const sR = sum(remocao);
  const sO = sum(outros);

  console.log("[grafico] labels:", labels.length, "sum:", {
    entrada: sE,
    saida: sS,
    remocao: sR,
    outros: sO
  });

  if (graficoTemporal) {
    graficoTemporal.destroy();
    graficoTemporal = null;
  }

  canvas = resetCanvas(canvas);

  requestAnimationFrame(() => {
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const data = {
      labels,
      datasets: [
        { label: "Entrada (Qtd)", data: entrada },
        { label: "Saída (Qtd)", data: saida },
        { label: "Remoção (Qtd)", data: remocao },
        { label: "Outros (Qtd)", data: outros }
      ]
    };

    const options = {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      plugins: {
        legend: { position: "bottom" },
        tooltip: { mode: "index", intersect: false }
      },
      interaction: { mode: "index", intersect: false },
      scales: {
        y: { beginAtZero: true }
      }
    };

    graficoTemporal = new Chart(ctx, {
      type: "bar",
      data,
      options
    });
  });
}

/* =========================
   RELATÓRIO
   ========================= */

function renderTotais(totais) {
  const totalQtd = Number(totais?.total_qtd ?? 0);
  const totalValor = Number(totais?.total_valor ?? 0);

  setTexto("totalQtd", totalQtd);
  setTexto("totalValor", formatBRL(totalValor));
}

async function carregarRelatorio() {
  const filtros = getFiltros();

  setStatusRelatorio("Carregando relatório...", "processando");
  setBtnLoading("btnAplicarFiltros", true);
  setBtnLoading("btnAbrirFiltros", true);
  setBtnLoading("btnAtualizarRelatorio", true);

  try {
    const resp = await apiRequest("relatorio_movimentacoes", filtros, "GET");

    if (!resp?.sucesso) {
      renderTotais({ total_qtd: 0, total_valor: 0 });
      renderGraficoTemporal({ labels: [], entrada: [], saida: [], remocao: [], outros: [] });
      setStatusRelatorio(resp?.mensagem || "Não foi possível carregar o relatório.", "erro");
      return;
    }

    const payload = resp?.dados || {};

    renderTotais(payload?.totais);
    renderGraficoTemporal(payload?.grafico_temporal);
    atualizarResumoFiltros();
    setStatusRelatorio("Relatório carregado com sucesso.", "sucesso");

    logJsInfo({
      origem: "relatorios.js",
      mensagem: "Relatório carregado",
      filtros,
      total: payload?.total ?? 0
    });
  } catch (err) {
    renderTotais({ total_qtd: 0, total_valor: 0 });
    renderGraficoTemporal({ labels: [], entrada: [], saida: [], remocao: [], outros: [] });
    setStatusRelatorio("Erro inesperado ao carregar relatório.", "erro");

    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro inesperado ao carregar relatório",
      detalhe: err?.message,
      stack: err?.stack
    });
  } finally {
    setBtnLoading("btnAplicarFiltros", false);
    setBtnLoading("btnAbrirFiltros", false);
    setBtnLoading("btnAtualizarRelatorio", false);
  }
}

function debounce(fn, delay = 350) {
  let t = null;
  return (...args) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => fn(...args), delay);
  };
}

function bindEventos() {
  $("btnAbrirFiltros")?.addEventListener("click", () => {
    getModalFiltros()?.show();
  });

  $("btnAplicarFiltros")?.addEventListener("click", async () => {
    atualizarResumoFiltros();
    await carregarRelatorio();
    getModalFiltros()?.hide();
  });

  $("btnLimparFiltros")?.addEventListener("click", () => {
    limparFiltros();
  });

  $("btnLimparFiltrosRapido")?.addEventListener("click", () => {
    limparFiltros();
  });

  $("btnAtualizarRelatorio")?.addEventListener("click", () => {
    carregarRelatorio();
  });

  const d = debounce(() => {
    atualizarResumoFiltros();
  }, 200);

  $("dataInicio")?.addEventListener("change", d);
  $("dataFim")?.addEventListener("change", d);
  $("tipo")?.addEventListener("change", d);

  $("btnExportarCSV")?.addEventListener("click", exportarCSV);
  $("btnExportarPDF")?.addEventListener("click", exportarPDF);
}

document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  setDefaultDatesIfEmpty();
  atualizarResumoFiltros();
  carregarRelatorio();
});