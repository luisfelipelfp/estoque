// js/relatorios.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let graficoTemporal = null;
let modalFiltrosInstance = null;
let ultimoGraficoPayload = null;
let fornecedoresCache = [];
let produtosCache = [];

const DEFAULT_RANGE_DAYS = 30;
const MAX_POINTS_CHART = 120;
const DEFAULT_LIMITE_TABELA = 5000;

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

function formatBRLComPrefixo(valor) {
  return `R$ ${formatBRL(valor)}`;
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

function getTipoGraficoSelecionado() {
  return $("tipoGrafico")?.value || "bar";
}

function getMetricaGraficoSelecionada() {
  return $("metricaGrafico")?.value || "quantidade";
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
    produto: ($("produto")?.value || "").trim(),
    fornecedor_id: $("fornecedor")?.value || "",
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

  if (filtros.produto) {
    partes.push(`produto contendo "${filtros.produto}"`);
    badgePartes.push("produto");
  }

  if (filtros.fornecedor_id) {
    const fornecedor = fornecedoresCache.find((f) => String(f.id) === String(filtros.fornecedor_id));
    if (fornecedor) {
      partes.push(`fornecedor ${fornecedor.nome}`);
      badgePartes.push(fornecedor.nome);
    }
  }

  setTexto("resumoFiltrosTexto", `${partes.join(", ")}.`);
  setTexto("badgePeriodoRelatorio", badgePartes.join(" • "));
}

function limparFiltros() {
  if ($("dataInicio")) $("dataInicio").value = "";
  if ($("dataFim")) $("dataFim").value = "";
  if ($("tipo")) $("tipo").value = "";
  if ($("produto")) $("produto").value = "";
  if ($("fornecedor")) $("fornecedor").value = "";

  limparSugestoesProduto();
  setDefaultDatesIfEmpty();
  atualizarResumoFiltros();
  carregarRelatorio();

  logJsInfo({
    origem: "relatorios.js",
    mensagem: "Filtros limpos"
  });
}

function toNumArray(arr, len) {
  const out = new Array(len);
  for (let i = 0; i < len; i++) {
    const n = Number(arr?.[i]);
    out[i] = Number.isFinite(n) ? n : 0;
  }
  return out;
}

function downsample(obj, maxPoints) {
  const labels = Array.isArray(obj?.labels) ? obj.labels : [];
  const metric = Array.isArray(obj?.metric) ? obj.metric : [];
  const n = labels.length;

  if (n <= maxPoints) {
    return { labels, metric };
  }

  const bucket = Math.ceil(n / maxPoints);
  const L = [];
  const V = [];

  for (let i = 0; i < n; i += bucket) {
    const end = Math.min(i + bucket, n);
    let soma = 0;

    for (let j = i; j < end; j++) {
      soma += Number(metric[j] || 0);
    }

    const first = labels[i];
    const last = labels[end - 1];
    L.push(i === end - 1 ? String(first) : `${first}..${last}`);
    V.push(soma);
  }

  return { labels: L, metric: V };
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

function getMetricaLabel(metrica) {
  if (metrica === "custo_total") return "Custo total";
  if (metrica === "valor_total") return "Valor total";
  if (metrica === "lucro") return "Lucro";
  return "Quantidade";
}

function montarOpcoesGrafico(tipoGrafico) {
  const isCircular = tipoGrafico === "pie" || tipoGrafico === "doughnut";

  if (isCircular) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      plugins: {
        legend: { position: "bottom" }
      }
    };
  }

  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
      legend: { position: "bottom" }
    },
    interaction: { mode: "index", intersect: false },
    scales: {
      y: {
        beginAtZero: true
      }
    }
  };
}

function renderGraficoTemporal(graf) {
  let canvas = $("graficoTemporal");
  if (!canvas) return;

  const Chart = window.Chart;
  if (!Chart) return;

  const labelsOriginais = Array.isArray(graf?.labels) ? graf.labels : [];
  const metrica = getMetricaGraficoSelecionada();
  const tipoGrafico = getTipoGraficoSelecionado();
  const graficoCircular = tipoGrafico === "pie" || tipoGrafico === "doughnut";

  if (!labelsOriginais.length) {
    renderGraficoTemporalVazio();
    return;
  }

  const metricArray = toNumArray(graf?.[metrica], labelsOriginais.length);
  let labels = labelsOriginais.slice();
  let metric = metricArray.slice();

  if (!graficoCircular && labels.length > MAX_POINTS_CHART) {
    const r = downsample({ labels, metric }, MAX_POINTS_CHART);
    labels = r.labels;
    metric = r.metric;
  }

  if (graficoTemporal) {
    graficoTemporal.destroy();
    graficoTemporal = null;
  }

  canvas = resetCanvas(canvas);

  requestAnimationFrame(() => {
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    let data;

    if (graficoCircular) {
      const total = sum(metric);
      data = {
        labels: total > 0 ? labels : ["Sem dados"],
        datasets: [
          {
            label: getMetricaLabel(metrica),
            data: total > 0 ? metric : [1]
          }
        ]
      };
    } else {
      data = {
        labels,
        datasets: [
          {
            label: getMetricaLabel(metrica),
            data: metric,
            fill: tipoGrafico === "line" ? false : undefined,
            tension: tipoGrafico === "line" ? 0.25 : undefined
          }
        ]
      };
    }

    graficoTemporal = new Chart(ctx, {
      type: tipoGrafico,
      data,
      options: montarOpcoesGrafico(tipoGrafico)
    });
  });
}

function renderTotais(totais) {
  const totalQtd = Number(totais?.total_qtd ?? 0);
  const totalCusto = Number(totais?.total_custo ?? 0);
  const totalValor = Number(totais?.total_valor ?? 0);
  const totalLucro = Number(totais?.total_lucro ?? 0);

  setTexto("totalQtd", totalQtd);
  setTexto("totalCusto", formatBRL(totalCusto));
  setTexto("totalValor", formatBRL(totalValor));
  setTexto("totalLucro", formatBRL(totalLucro));
}

function agruparPorData(registros, metrica) {
  const mapa = new Map();

  for (const item of registros) {
    const dataBruta = String(item?.data ?? "");
    const dia = dataBruta.slice(0, 10);
    if (!dia) continue;

    const valor = Number(item?.[metrica] ?? 0);

    if (!mapa.has(dia)) {
      mapa.set(dia, 0);
    }

    mapa.set(dia, mapa.get(dia) + valor);
  }

  const labels = Array.from(mapa.keys()).sort();
  const metric = labels.map((label) => Number(mapa.get(label) ?? 0));

  return { labels, metric };
}

function montarPayloadGraficoPorMetricas(registros) {
  const quantidade = agruparPorData(
    registros.map((item) => ({ ...item, quantidade: Number(item?.quantidade ?? 0) })),
    "quantidade"
  );

  const custo = agruparPorData(registros, "custo_total");
  const valor = agruparPorData(registros, "valor_total");
  const lucro = agruparPorData(registros, "lucro");

  const labelsBase = quantidade.labels;

  function normalizarSerie(serie) {
    const mapa = new Map();
    serie.labels.forEach((label, index) => {
      mapa.set(label, Number(serie.metric?.[index] ?? 0));
    });

    return labelsBase.map((label) => Number(mapa.get(label) ?? 0));
  }

  return {
    labels: labelsBase,
    quantidade: quantidade.metric,
    custo_total: normalizarSerie(custo),
    valor_total: normalizarSerie(valor),
    lucro: normalizarSerie(lucro)
  };
}

async function carregarFornecedores() {
  try {
    const resp = await apiRequest("listar_fornecedores", {}, "GET");

    if (!resp?.sucesso) {
      fornecedoresCache = [];
      popularSelectFornecedores();
      return;
    }

    fornecedoresCache = (Array.isArray(resp?.dados) ? resp.dados : [])
      .filter((f) => Number(f?.ativo ?? 1) === 1)
      .sort((a, b) => String(a?.nome ?? "").localeCompare(String(b?.nome ?? ""), "pt-BR"));

    popularSelectFornecedores();
  } catch (err) {
    fornecedoresCache = [];
    popularSelectFornecedores();

    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro ao carregar fornecedores",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

function popularSelectFornecedores() {
  const select = $("fornecedor");
  if (!select) return;

  const valorAtual = select.value || "";

  select.innerHTML = `
    <option value="">Todos</option>
    ${fornecedoresCache.map((f) => `
      <option value="${Number(f.id)}">${String(f.nome ?? "")}</option>
    `).join("")}
  `;

  if (valorAtual) {
    select.value = valorAtual;
  }
}

async function carregarProdutos() {
  try {
    const resp = await apiRequest("listar_produtos", {}, "GET");

    if (!resp?.sucesso) {
      produtosCache = [];
      return;
    }

    produtosCache = Array.isArray(resp?.dados) ? resp.dados : [];
  } catch (err) {
    produtosCache = [];

    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro ao carregar produtos",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

function limparSugestoesProduto() {
  const box = $("produtoSugestoes");
  if (!box) return;
  box.innerHTML = "";
  box.style.display = "none";
}

function renderSugestoesProduto(produtos) {
  const box = $("produtoSugestoes");
  if (!box) return;

  if (!Array.isArray(produtos) || produtos.length === 0) {
    box.innerHTML = `
      <button type="button" class="list-group-item list-group-item-action disabled">
        Nenhum produto encontrado
      </button>
    `;
    box.style.display = "block";
    return;
  }

  box.innerHTML = produtos.map((p) => `
    <button
      type="button"
      class="list-group-item list-group-item-action"
      data-produto-nome="${String(p?.nome ?? "").replace(/"/g, "&quot;")}"
    >
      ${String(p?.nome ?? "")}
    </button>
  `).join("");

  box.style.display = "block";
}

function buscarProdutosAutocomplete(termo) {
  const texto = String(termo || "").trim().toLowerCase();

  if (!texto) {
    limparSugestoesProduto();
    return;
  }

  const encontrados = produtosCache
    .filter((p) => String(p?.nome ?? "").toLowerCase().includes(texto))
    .slice(0, 8);

  renderSugestoesProduto(encontrados);
}

function debounce(fn, delay = 350) {
  let t = null;
  return (...args) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => fn(...args), delay);
  };
}

async function exportarXlsx() {
  const filtros = getFiltros();

  setStatusRelatorio("Preparando exportação XLSX...", "processando");

  try {
    const resp = await apiRequest("listar_movimentacoes", filtros, "GET");

    if (!resp?.sucesso) {
      setStatusRelatorio(resp?.mensagem || "Não foi possível exportar XLSX.", "erro");
      return;
    }

    const registros = Array.isArray(resp?.dados?.dados) ? resp.dados.dados : [];

    if (!registros.length) {
      setStatusRelatorio("Nenhum registro encontrado para exportação.", "erro");
      return;
    }

    const linhas = registros.map((item) => ({
      Data: item?.data ?? "",
      Produto: item?.produto_nome ?? "",
      Fornecedor: item?.fornecedor_nome ?? "",
      Tipo: item?.tipo ?? "",
      Quantidade: Number(item?.quantidade ?? 0),
      "Custo Total": Number(item?.custo_total ?? 0),
      "Valor Total": Number(item?.valor_total ?? 0),
      Lucro: Number(item?.lucro ?? 0),
      Usuário: item?.usuario ?? "",
      Observação: item?.observacao ?? ""
    }));

    const worksheet = window.XLSX.utils.json_to_sheet(linhas);
    const workbook = window.XLSX.utils.book_new();
    window.XLSX.utils.book_append_sheet(workbook, worksheet, "Relatorio");
    window.XLSX.writeFile(workbook, "relatorio_movimentacoes.xlsx");

    setStatusRelatorio("Exportação XLSX concluída com sucesso.", "sucesso");
  } catch (err) {
    setStatusRelatorio("Erro ao exportar XLSX.", "erro");

    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro ao exportar XLSX",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

async function exportarPDF() {
  const filtros = getFiltros();

  setStatusRelatorio("Preparando exportação PDF...", "processando");

  try {
    const resp = await apiRequest("listar_movimentacoes", filtros, "GET");

    if (!resp?.sucesso) {
      setStatusRelatorio(resp?.mensagem || "Não foi possível exportar PDF.", "erro");
      return;
    }

    const registros = Array.isArray(resp?.dados?.dados) ? resp.dados.dados : [];

    if (!registros.length) {
      setStatusRelatorio("Nenhum registro encontrado para exportação.", "erro");
      return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: "landscape" });

    doc.setFontSize(14);
    doc.text("Relatório de Movimentações", 14, 14);

    const body = registros.map((item) => [
      item?.data ?? "",
      item?.produto_nome ?? "",
      item?.fornecedor_nome ?? "",
      item?.tipo ?? "",
      Number(item?.quantidade ?? 0),
      formatBRLComPrefixo(item?.custo_total ?? 0),
      formatBRLComPrefixo(item?.valor_total ?? 0),
      formatBRLComPrefixo(item?.lucro ?? 0),
      item?.usuario ?? ""
    ]);

    doc.autoTable({
      startY: 20,
      head: [[
        "Data",
        "Produto",
        "Fornecedor",
        "Tipo",
        "Qtd",
        "Custo Total",
        "Valor Total",
        "Lucro",
        "Usuário"
      ]],
      body
    });

    doc.save("relatorio_movimentacoes.pdf");

    setStatusRelatorio("Exportação PDF concluída com sucesso.", "sucesso");
  } catch (err) {
    setStatusRelatorio("Erro ao exportar PDF.", "erro");

    logJsError({
      origem: "relatorios.js",
      mensagem: "Erro ao exportar PDF",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

async function carregarRelatorio() {
  const filtros = getFiltros();

  setStatusRelatorio("Carregando relatório...", "processando");
  setBtnLoading("btnAplicarFiltros", true);
  setBtnLoading("btnAbrirFiltros", true);
  setBtnLoading("btnAtualizarRelatorio", true);
  setBtnLoading("btnExportarXlsx", true);
  setBtnLoading("btnExportarPdf", true);

  try {
    const resp = await apiRequest("listar_movimentacoes", filtros, "GET");

    if (!resp?.sucesso) {
      renderTotais({
        total_qtd: 0,
        total_custo: 0,
        total_valor: 0,
        total_lucro: 0
      });
      ultimoGraficoPayload = {
        labels: [],
        quantidade: [],
        custo_total: [],
        valor_total: [],
        lucro: []
      };
      renderGraficoTemporal(ultimoGraficoPayload);
      setStatusRelatorio(resp?.mensagem || "Não foi possível carregar o relatório.", "erro");
      return;
    }

    const payload = resp?.dados || {};
    const registros = Array.isArray(payload?.dados) ? payload.dados : [];

    const totais = {
      total_qtd: registros.reduce((acc, item) => acc + Number(item?.quantidade ?? 0), 0),
      total_custo: registros.reduce((acc, item) => acc + Number(item?.custo_total ?? 0), 0),
      total_valor: registros.reduce((acc, item) => acc + Number(item?.valor_total ?? 0), 0),
      total_lucro: registros.reduce((acc, item) => acc + Number(item?.lucro ?? 0), 0)
    };

    renderTotais(totais);

    ultimoGraficoPayload = montarPayloadGraficoPorMetricas(registros);
    renderGraficoTemporal(ultimoGraficoPayload);
    atualizarResumoFiltros();
    setStatusRelatorio(`Relatório carregado com sucesso. ${registros.length} registro(s) encontrados.`, "sucesso");

    logJsInfo({
      origem: "relatorios.js",
      mensagem: "Relatório carregado",
      filtros,
      total_registros: registros.length,
      tipo_grafico: getTipoGraficoSelecionado(),
      metrica: getMetricaGraficoSelecionada()
    });
  } catch (err) {
    renderTotais({
      total_qtd: 0,
      total_custo: 0,
      total_valor: 0,
      total_lucro: 0
    });
    ultimoGraficoPayload = {
      labels: [],
      quantidade: [],
      custo_total: [],
      valor_total: [],
      lucro: []
    };
    renderGraficoTemporal(ultimoGraficoPayload);
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
    setBtnLoading("btnExportarXlsx", false);
    setBtnLoading("btnExportarPdf", false);
  }
}

function bindAutocompleteProduto() {
  const input = $("produto");
  const box = $("produtoSugestoes");
  if (!input || !box) return;

  const buscarDebounced = debounce((termo) => {
    buscarProdutosAutocomplete(termo);
  }, 200);

  input.addEventListener("input", () => {
    const termo = input.value.trim();

    if (!termo) {
      limparSugestoesProduto();
      return;
    }

    buscarDebounced(termo);
  });

  input.addEventListener("keydown", (ev) => {
    if (ev.key !== "Enter") return;

    const termo = input.value.trim().toLowerCase();
    if (!termo) return;

    const encontrados = produtosCache.filter((p) =>
      String(p?.nome ?? "").toLowerCase().includes(termo)
    );

    if (encontrados.length > 0) {
      ev.preventDefault();
      input.value = encontrados[0].nome || "";
      limparSugestoesProduto();
    }
  });

  box.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-produto-nome]");
    if (!btn) return;

    input.value = btn.dataset.produtoNome || "";
    limparSugestoesProduto();
    input.focus();
  });

  document.addEventListener("click", (ev) => {
    const clicouNoInput = ev.target.closest("#produto");
    const clicouNaLista = ev.target.closest("#produtoSugestoes");

    if (!clicouNoInput && !clicouNaLista) {
      limparSugestoesProduto();
    }
  });
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

  $("btnExportarXlsx")?.addEventListener("click", () => {
    exportarXlsx();
  });

  $("btnExportarPdf")?.addEventListener("click", () => {
    exportarPDF();
  });

  $("tipoGrafico")?.addEventListener("change", () => {
    if (ultimoGraficoPayload) {
      renderGraficoTemporal(ultimoGraficoPayload);
      setStatusRelatorio("Tipo de gráfico atualizado.", "sucesso");
    }
  });

  $("metricaGrafico")?.addEventListener("change", () => {
    if (ultimoGraficoPayload) {
      renderGraficoTemporal(ultimoGraficoPayload);
      setStatusRelatorio("Métrica do gráfico atualizada.", "sucesso");
    }
  });

  const d = debounce(() => {
    atualizarResumoFiltros();
  }, 200);

  $("dataInicio")?.addEventListener("change", d);
  $("dataFim")?.addEventListener("change", d);
  $("tipo")?.addEventListener("change", d);
  $("fornecedor")?.addEventListener("change", d);
  $("produto")?.addEventListener("input", d);
}

document.addEventListener("DOMContentLoaded", async () => {
  bindEventos();
  bindAutocompleteProduto();
  setDefaultDatesIfEmpty();
  await carregarFornecedores();
  await carregarProdutos();
  atualizarResumoFiltros();
  carregarRelatorio();
});