// js/relatorios.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let graficoTemporal = null;

const DEFAULT_RANGE_DAYS = 30;
const MAX_POINTS_CHART = 120;
const DEFAULT_LIMITE_TABELA = 200;

function formatBRL(valor) {
  const n = Number(valor || 0);
  return n.toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pad2(n) { return String(n).padStart(2, "0"); }
function toYMD(dateObj) {
  return `${dateObj.getFullYear()}-${pad2(dateObj.getMonth() + 1)}-${pad2(dateObj.getDate())}`;
}

function setDefaultDatesIfEmpty() {
  const elIni = document.getElementById("dataInicio");
  const elFim = document.getElementById("dataFim");
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
    data_inicio: document.getElementById("dataInicio")?.value || "",
    data_fim: document.getElementById("dataFim")?.value || "",
    tipo: document.getElementById("tipo")?.value || "",
    pagina: 1,
    limite: DEFAULT_LIMITE_TABELA
  };
}

/* =========================
   EXPORTAÇÃO (CSV/PDF)
   ========================= */

function buildQuery(params) {
  const usp = new URLSearchParams();
  for (const [k, v] of Object.entries(params || {})) {
    if (v !== undefined && v !== null && String(v).trim() !== "") {
      usp.append(k, String(v));
    }
  }
  return usp.toString();
}

function exportarCSV() {
  const filtros = getFiltros();

  // CSV não deve exportar só a página atual
  delete filtros.pagina;
  delete filtros.limite;

  const qs = buildQuery(filtros);
  const url = `api/exportar_csv.php${qs ? `?${qs}` : ""}`;

  // Força download
  window.location.href = url;
}

function exportarPDF() {
  const filtros = getFiltros();

  // PDF não deve exportar só a página atual
  delete filtros.pagina;
  delete filtros.limite;

  const qs = buildQuery(filtros);
  const url = `api/exportar_pdf.php${qs ? `?${qs}` : ""}`;

  // Abre em nova aba pra não interromper a tela do relatório
  window.open(url, "_blank");
}

/* =========================
   RENDER
   ========================= */

function renderTotais(totais) {
  const elQtd = document.getElementById("totalQtd");
  const elValor = document.getElementById("totalValor");
  if (elQtd) elQtd.textContent = String(totais?.total_qtd ?? 0);
  if (elValor) elValor.textContent = formatBRL(totais?.total_valor ?? 0);
}

function renderTabela(dados = []) {
  const tbody = document.getElementById("tabelaMov");
  if (!tbody) return;

  if (!Array.isArray(dados) || dados.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="8" class="text-center text-muted">
        Nenhum registro encontrado com os filtros aplicados.
      </td></tr>`;
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
        : tipo === "remocao"
        ? `<span class="badge bg-warning text-dark">remoção</span>`
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
  const L = [], E = [], S = [], R = [], O = [];

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

    E.push(se); S.push(ss); R.push(sr); O.push(so);
  }

  return { labels: L, entrada: E, saida: S, remocao: R, outros: O };
}

function resetCanvas(canvas) {
  // garante que o canvas “zera” de verdade e recalcula tamanho
  const parent = canvas.parentNode;
  const newCanvas = canvas.cloneNode(true);
  parent.replaceChild(newCanvas, canvas);
  return newCanvas;
}

function sum(arr) {
  return Array.isArray(arr) ? arr.reduce((a, b) => a + (Number(b) || 0), 0) : 0;
}

function renderGraficoTemporal(graf) {
  let canvas = document.getElementById("graficoTemporal");
  if (!canvas) return;

  const Chart = window.Chart;
  if (!Chart) return;

  let labels = Array.isArray(graf?.labels) ? graf.labels : [];
  if (!labels.length) {
    if (graficoTemporal) {
      graficoTemporal.destroy();
      graficoTemporal = null;
    }
    return;
  }

  let entrada = toNumArray(graf?.entrada, labels.length);
  let saida   = toNumArray(graf?.saida, labels.length);
  let remocao = toNumArray(graf?.remocao, labels.length);
  let outros  = toNumArray(graf?.outros, labels.length);

  if (labels.length > MAX_POINTS_CHART) {
    const r = downsample({ labels, entrada, saida, remocao, outros }, MAX_POINTS_CHART);
    labels = r.labels; entrada = r.entrada; saida = r.saida; remocao = r.remocao; outros = r.outros;
  }

  // logs úteis
  const sE = sum(entrada), sS = sum(saida), sR = sum(remocao), sO = sum(outros);
  console.log("[grafico] labels:", labels.length, "sum:", { entrada: sE, saida: sS, remocao: sR, outros: sO });

  // destrói sempre (evita estados ruins/resize)
  if (graficoTemporal) {
    graficoTemporal.destroy();
    graficoTemporal = null;
  }

  // reset do canvas (remove lixo / tamanho bugado)
  canvas = resetCanvas(canvas);

  requestAnimationFrame(() => {
    const ctx = canvas.getContext("2d");

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

    graficoTemporal = new Chart(ctx, { type: "bar", data, options });
  });
}

async function carregarRelatorio() {
  const filtros = getFiltros();

  const tbody = document.getElementById("tabelaMov");
  if (tbody) {
    tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted">Carregando...</td></tr>`;
  }

  try {
    const resp = await apiRequest("relatorio_movimentacoes", filtros, "GET");
    if (!resp?.sucesso) {
      renderTotais({ total_qtd: 0, total_valor: 0 });
      renderTabela([]);
      renderGraficoTemporal({ labels: [], entrada: [], saida: [], remocao: [], outros: [] });
      return;
    }

    const payload = resp?.dados || {};
    renderTotais(payload?.totais);
    renderTabela(payload?.dados);
    renderGraficoTemporal(payload?.grafico_temporal);

    console.log("grafico_temporal.meta:", payload?.grafico_temporal?.meta);

    logJsInfo({
      origem: "relatorios.js",
      mensagem: "Relatório carregado",
      filtros,
      total: payload?.total ?? 0
    });
  } catch (err) {
    renderTotais({ total_qtd: 0, total_valor: 0 });
    renderTabela([]);
    renderGraficoTemporal({ labels: [], entrada: [], saida: [], remocao: [], outros: [] });

    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro inesperado ao carregar relatório",
      detalhe: err?.message,
      stack: err?.stack
    });
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
  // filtros
  document.getElementById("btnAplicarFiltros")?.addEventListener("click", carregarRelatorio);
  const d = debounce(carregarRelatorio, 350);
  document.getElementById("dataInicio")?.addEventListener("change", d);
  document.getElementById("dataFim")?.addEventListener("change", d);
  document.getElementById("tipo")?.addEventListener("change", d);

  // exportação
  document.getElementById("btnExportarCSV")?.addEventListener("click", exportarCSV);
  document.getElementById("btnExportarPDF")?.addEventListener("click", exportarPDF);
}

document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  setDefaultDatesIfEmpty();
  carregarRelatorio();
});