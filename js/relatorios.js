// js/relatorios.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let graficoTemporal = null;

const DEFAULT_RANGE_DAYS = 30;
const MAX_POINTS_CHART = 120;
const DEFAULT_LIMITE_TABELA = 200;

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

function setTexto(id, valor) {
  const el = document.getElementById(id);
  if (el) {
    el.textContent = String(valor ?? "");
  }
}

function setBtnLoading(id, loading, textoOriginal = "") {
  const btn = document.getElementById(id);
  if (!btn) return;

  if (!btn.dataset.originalText) {
    btn.dataset.originalText = textoOriginal || btn.textContent || "";
  }

  btn.disabled = !!loading;
  btn.textContent = loading ? "Processando..." : btn.dataset.originalText;
}

function avisarExportacaoNaoDisponivel(tipo) {
  const msg = `${tipo} ainda não está implementado no backend atual.`;
  window.alert(msg);

  logJsInfo({
    origem: "relatorios.js",
    mensagem: "Exportação indisponível",
    tipo
  });
}

/* =========================
   EXPORTAÇÃO
   ========================= */

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
  const elIni = document.getElementById("dataInicio");
  const elFim = document.getElementById("dataFim");
  const elTipo = document.getElementById("tipo");

  if (elIni) elIni.value = "";
  if (elFim) elFim.value = "";
  if (elTipo) elTipo.value = "";

  setDefaultDatesIfEmpty();
  carregarRelatorio();

  logJsInfo({
    origem: "relatorios.js",
    mensagem: "Filtros limpos"
  });
}

/* =========================
   ESTOQUE ATUAL
   ========================= */

function renderEstoqueAtualLoading() {
  const tbody = document.getElementById("tabelaEstoqueAtual");
  if (!tbody) return;

  tbody.innerHTML = `
    <tr>
      <td colspan="5" class="text-center text-muted">Carregando estoque...</td>
    </tr>
  `;
}

function renderEstoqueAtualErro(msg) {
  const tbody = document.getElementById("tabelaEstoqueAtual");
  if (!tbody) return;

  setTexto("estoqueTotalQtd", "0");
  setTexto("estoqueTotalValor", "0,00");

  tbody.innerHTML = `
    <tr>
      <td colspan="5" class="text-center text-danger">
        ${msg || "Falha ao carregar estoque atual."}
      </td>
    </tr>
  `;
}

function renderEstoqueAtual(payload) {
  const tbody = document.getElementById("tabelaEstoqueAtual");

  const itens = Array.isArray(payload?.itens) ? payload.itens : [];
  const totais = payload?.totais || {};

  const totalQtd = Number(totais?.total_qtd ?? 0);
  const totalValor = Number(totais?.total_valor ?? 0);

  setTexto("estoqueTotalQtd", totalQtd);
  setTexto("estoqueTotalValor", formatBRL(totalValor));

  if (!tbody) return;

  if (itens.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="5" class="text-center text-muted">Nenhum item de estoque encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = itens.map((item) => {
    const id = item?.id ?? "-";
    const nome = item?.nome ?? "-";
    const qtd = Number(item?.quantidade ?? 0);
    const preco = item?.preco_custo == null ? 0 : Number(item.preco_custo);
    const valorEstimado = item?.valor_estimado == null
      ? (qtd * preco)
      : Number(item.valor_estimado);

    return `
      <tr>
        <td>${id}</td>
        <td>${nome}</td>
        <td>${qtd}</td>
        <td>R$ ${formatBRL(preco)}</td>
        <td>R$ ${formatBRL(valorEstimado)}</td>
      </tr>
    `;
  }).join("");
}

async function carregarEstoqueAtual() {
  renderEstoqueAtualLoading();

  try {
    const resp = await apiRequest("estoque_atual", {}, "GET");

    if (!resp?.sucesso) {
      renderEstoqueAtualErro(resp?.mensagem || "Erro ao gerar estoque atual.");
      return;
    }

    renderEstoqueAtual(resp?.dados);

    logJsInfo({
      origem: "relatorios.js",
      mensagem: "Estoque atual carregado",
      total_itens: resp?.dados?.itens?.length ?? 0,
      total_qtd: resp?.dados?.totais?.total_qtd ?? 0
    });
  } catch (err) {
    renderEstoqueAtualErro("Erro inesperado ao carregar estoque atual.");

    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro inesperado ao carregar estoque atual",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

/* =========================
   RELATÓRIO MOVIMENTAÇÕES
   ========================= */

function renderTotais(totais) {
  const totalQtd = Number(totais?.total_qtd ?? 0);
  const totalValor = Number(totais?.total_valor ?? 0);

  setTexto("totalQtd", totalQtd);
  setTexto("totalValor", formatBRL(totalValor));
  setTexto("totalQtdResumo", totalQtd);
  setTexto("totalValorResumo", formatBRL(totalValor));
}

function renderTabelaLoading() {
  const tbody = document.getElementById("tabelaMov");
  if (!tbody) return;

  tbody.innerHTML = `
    <tr>
      <td colspan="8" class="text-center text-muted">Carregando movimentações...</td>
    </tr>
  `;
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
      </tr>
    `;
    return;
  }

  tbody.innerHTML = dados.map((item) => {
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
      item?.valor_unitario == null
        ? "-"
        : `R$ ${formatBRL(item.valor_unitario)}`;

    const valorTot =
      item?.valor_total == null
        ? "-"
        : `R$ ${formatBRL(item.valor_total)}`;

    return `
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
    `;
  }).join("");
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
  const canvas = document.getElementById("graficoTemporal");
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
  let canvas = document.getElementById("graficoTemporal");
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

async function carregarRelatorio() {
  const filtros = getFiltros();

  renderTabelaLoading();
  setBtnLoading("btnAplicarFiltros", true);
  setBtnLoading("btnAplicarFiltrosTopo", true);

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
    renderTabela(payload?.dados || []);
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
  } finally {
    setBtnLoading("btnAplicarFiltros", false);
    setBtnLoading("btnAplicarFiltrosTopo", false);
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
  document.getElementById("btnAplicarFiltros")?.addEventListener("click", carregarRelatorio);
  document.getElementById("btnAplicarFiltrosTopo")?.addEventListener("click", carregarRelatorio);
  document.getElementById("btnLimparFiltros")?.addEventListener("click", limparFiltros);

  const d = debounce(carregarRelatorio, 350);
  document.getElementById("dataInicio")?.addEventListener("change", d);
  document.getElementById("dataFim")?.addEventListener("change", d);
  document.getElementById("tipo")?.addEventListener("change", d);

  document.getElementById("btnExportarCSV")?.addEventListener("click", exportarCSV);
  document.getElementById("btnExportarPDF")?.addEventListener("click", exportarPDF);
}

document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  setDefaultDatesIfEmpty();

  carregarEstoqueAtual();
  carregarRelatorio();
});