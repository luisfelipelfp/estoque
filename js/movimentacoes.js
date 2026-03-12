// js/movimentacoes.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let paginaAtual = 1;
const limitePorPagina = 10;
let ultimoFiltroAplicado = {};
let totalPaginasAtual = 0;
let modalDetalhesInstance = null;
let produtosCache = [];
let autocompleteAbortController = null;

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

function getModalDetalhes() {
  const el = $("modalDetalhesMovimentacao");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalDetalhesInstance) {
    modalDetalhesInstance = new window.bootstrap.Modal(el);
  }

  return modalDetalhesInstance;
}

function setStatus(texto = "") {
  const topo = $("movimentacoesInfoTopo");
  const rodape = $("movimentacoesStatusRodape");

  if (topo) topo.textContent = texto;
  if (rodape) rodape.textContent = texto;
}

function setResumoPagina(texto = "") {
  const el = $("movimentacoesResumoPagina");
  if (el) el.textContent = texto;
}

function setTextoDetalhe(id, valor) {
  const el = $(id);
  if (el) el.textContent = String(valor ?? "");
}

function limparResumo() {
  if ($("resumoTotalRegistros")) $("resumoTotalRegistros").textContent = "0";
  if ($("resumoQuantidadeTotal")) $("resumoQuantidadeTotal").textContent = "0";
  if ($("resumoCustoTotal")) $("resumoCustoTotal").textContent = "R$ 0,00";
  if ($("resumoLucroTotal")) $("resumoLucroTotal").textContent = "R$ 0,00";
}

function getFiltrosTela() {
  return {
    produto: ($("filtroProduto")?.value ?? "").trim(),
    fornecedor_id: ($("filtroFornecedor")?.value ?? "").trim(),
    tipo: ($("filtroTipo")?.value ?? "").trim(),
    data_inicio: ($("filtroDataInicio")?.value ?? "").trim(),
    data_fim: ($("filtroDataFim")?.value ?? "").trim()
  };
}

function montarPayloadConsulta(filtros, pagina, limite = limitePorPagina) {
  const payload = {
    pagina,
    limite
  };

  if (filtros.produto) payload.produto = filtros.produto;
  if (filtros.fornecedor_id) payload.fornecedor_id = filtros.fornecedor_id;
  if (filtros.tipo) payload.tipo = filtros.tipo;
  if (filtros.data_inicio) payload.data_inicio = filtros.data_inicio;
  if (filtros.data_fim) payload.data_fim = filtros.data_fim;

  return payload;
}

function atualizarEstadoPaginacao() {
  const btnAnterior = $("btnPaginaAnterior");
  const btnProxima = $("btnPaginaProxima");
  const txt = $("movimentacoesPaginacaoTexto");

  if (btnAnterior) {
    btnAnterior.disabled = paginaAtual <= 1;
  }

  if (btnProxima) {
    btnProxima.disabled = paginaAtual >= totalPaginasAtual || totalPaginasAtual === 0;
  }

  if (txt) {
    txt.textContent = `Página ${totalPaginasAtual > 0 ? paginaAtual : 0} de ${totalPaginasAtual}`;
  }
}

function renderMensagemTabela(texto, classe = "text-muted") {
  const tbody = document.querySelector("#tabelaMovimentacoes tbody");
  if (!tbody) return;

  tbody.innerHTML = `
    <tr>
      <td colspan="11" class="text-center ${classe}">
        ${escapeHtml(texto)}
      </td>
    </tr>
  `;
}

function renderTipoBadge(tipo) {
  const t = String(tipo || "").toLowerCase();

  if (t === "entrada") return `<span class="badge bg-success">Entrada</span>`;
  if (t === "saida") return `<span class="badge bg-danger">Saída</span>`;
  if (t === "remocao") return `<span class="badge bg-secondary">Remoção</span>`;

  return `<span class="badge bg-dark">${escapeHtml(tipo || "-")}</span>`;
}

function renderResumo(payload) {
  const resumo = payload?.resumo || {};

  const totalRegistros = Number(
    resumo?.total_registros ?? payload?.total ?? 0
  );

  const quantidadeTotal = Number(resumo?.quantidade_total ?? 0);
  const custoTotal = Number(resumo?.custo_total ?? 0);
  const lucroTotal = Number(resumo?.lucro_total ?? 0);

  if ($("resumoTotalRegistros")) {
    $("resumoTotalRegistros").textContent = String(totalRegistros);
  }

  if ($("resumoQuantidadeTotal")) {
    $("resumoQuantidadeTotal").textContent = String(quantidadeTotal);
  }

  if ($("resumoCustoTotal")) {
    $("resumoCustoTotal").textContent = formatBRL(custoTotal);
  }

  if ($("resumoLucroTotal")) {
    $("resumoLucroTotal").textContent = formatBRL(lucroTotal);
  }
}

function renderTabela(movimentacoes) {
  const tbody = document.querySelector("#tabelaMovimentacoes tbody");
  if (!tbody) return;

  if (!Array.isArray(movimentacoes) || movimentacoes.length === 0) {
    renderMensagemTabela("Nenhuma movimentação encontrada para os filtros informados.");
    return;
  }

  tbody.innerHTML = movimentacoes.map((m) => {
    const fornecedor = m?.fornecedor_nome ? escapeHtml(m.fornecedor_nome) : "—";
    const custoTotal = m?.custo_total !== null && m?.custo_total !== undefined ? formatBRL(m.custo_total) : "—";
    const valorTotal = m?.valor_total !== null && m?.valor_total !== undefined ? formatBRL(m.valor_total) : "—";
    const lucro = m?.lucro !== null && m?.lucro !== undefined ? formatBRL(m.lucro) : "—";

    return `
      <tr>
        <td>${Number(m?.id ?? 0)}</td>
        <td>${escapeHtml(m?.data ?? "-")}</td>
        <td>${escapeHtml(m?.produto_nome ?? "-")}</td>
        <td>${fornecedor}</td>
        <td>${renderTipoBadge(m?.tipo)}</td>
        <td>${Number(m?.quantidade ?? 0)}</td>
        <td>${custoTotal}</td>
        <td>${valorTotal}</td>
        <td>${lucro}</td>
        <td>${escapeHtml(m?.usuario ?? "Sistema")}</td>
        <td>
          <button
            class="btn btn-sm btn-outline-primary"
            type="button"
            data-acao="detalhes"
            data-id="${Number(m?.id ?? 0)}"
          >
            Detalhes
          </button>
        </td>
      </tr>
    `;
  }).join("");
}

async function carregarProdutosAutocomplete() {
  try {
    const resp = await apiRequest("listar_produtos", {}, "GET");

    if (!resp?.sucesso) {
      produtosCache = [];
      return;
    }

    produtosCache = Array.isArray(resp?.dados) ? resp.dados : [];

    logJsInfo({
      origem: "movimentacoes.js",
      mensagem: "Produtos carregados para autocomplete",
      total: produtosCache.length
    });
  } catch (err) {
    produtosCache = [];

    logJsError({
      origem: "movimentacoes.js",
      mensagem: "Erro ao carregar produtos para autocomplete",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

async function carregarFornecedoresFiltro() {
  const select = $("filtroFornecedor");
  if (!select) return;

  try {
    const resp = await apiRequest("listar_fornecedores", {}, "GET");

    if (!resp?.sucesso) {
      return;
    }

    const fornecedores = Array.isArray(resp?.dados) ? resp.dados : [];
    const ativos = fornecedores
      .filter((f) => Number(f?.ativo ?? 1) === 1)
      .sort((a, b) => String(a?.nome ?? "").localeCompare(String(b?.nome ?? ""), "pt-BR"));

    select.innerHTML = `
      <option value="">Todos</option>
      ${ativos.map((f) => `
        <option value="${Number(f?.id ?? 0)}">${escapeHtml(f?.nome ?? "")}</option>
      `).join("")}
    `;
  } catch (err) {
    logJsError({
      origem: "movimentacoes.js",
      mensagem: "Erro ao carregar fornecedores para filtro",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

function limparSugestoesProduto() {
  const box = $("filtroProdutoSugestoes");
  if (!box) return;
  box.innerHTML = "";
  box.style.display = "none";
}

function renderSugestoesProduto(produtos) {
  const box = $("filtroProdutoSugestoes");
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
      data-produto-id="${Number(p?.id ?? 0)}"
      data-produto-nome="${escapeHtml(p?.nome ?? "")}"
    >
      ${escapeHtml(p?.nome ?? "")}
    </button>
  `).join("");

  box.style.display = "block";
}

function selecionarProdutoSugestao(id, nome) {
  if ($("filtroProduto")) $("filtroProduto").value = String(nome || "");
  if ($("filtroProdutoId")) $("filtroProdutoId").value = String(id || "");
  limparSugestoesProduto();
}

async function buscarProdutosAutocomplete(termo) {
  const texto = String(termo || "").trim();

  if (!texto) {
    limparSugestoesProduto();
    return;
  }

  const encontradosCache = produtosCache
    .filter((p) => String(p?.nome ?? "").toLowerCase().includes(texto.toLowerCase()))
    .slice(0, 8);

  if (encontradosCache.length > 0) {
    renderSugestoesProduto(encontradosCache);
    return;
  }

  try {
    if (autocompleteAbortController) {
      autocompleteAbortController.abort();
    }

    autocompleteAbortController = new AbortController();

    const resp = await apiRequest("buscar_produtos", { q: texto, limit: 8 }, "GET");

    if (!resp?.sucesso) {
      renderSugestoesProduto([]);
      return;
    }

    const dadosBrutos = resp?.dados;
    const dados = Array.isArray(dadosBrutos?.itens)
      ? dadosBrutos.itens
      : (Array.isArray(dadosBrutos) ? dadosBrutos : []);

    renderSugestoesProduto(dados);
  } catch (err) {
    renderSugestoesProduto([]);

    logJsError({
      origem: "movimentacoes.js",
      mensagem: "Erro no autocomplete de produtos",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

function debounce(fn, delay = 250) {
  let t = null;
  return (...args) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => fn(...args), delay);
  };
}

const buscarProdutosAutocompleteDebounced = debounce(buscarProdutosAutocomplete, 200);

async function listarMovimentacoes(filtros = {}, pagina = 1) {
  const filtrosPossuemValor = Object.values(filtros).some((v) => String(v || "").trim() !== "");

  if (!filtrosPossuemValor) {
    renderMensagemTabela("Use os filtros para buscar movimentações.");
    setStatus("Aguardando filtros para consulta.");
    setResumoPagina("Nenhuma consulta realizada.");
    paginaAtual = 1;
    totalPaginasAtual = 0;
    limparResumo();
    atualizarEstadoPaginacao();
    return;
  }

  try {
    renderMensagemTabela("Carregando movimentações...");
    setStatus("Consultando movimentações...");

    paginaAtual = pagina;
    ultimoFiltroAplicado = { ...filtros };

    const payloadConsulta = montarPayloadConsulta(filtros, paginaAtual);
    const resp = await apiRequest("listar_movimentacoes", payloadConsulta, "GET");

    if (!resp?.sucesso) {
      renderMensagemTabela(resp?.mensagem || "Erro ao buscar movimentações.", "text-danger");
      setStatus(resp?.mensagem || "Erro ao buscar movimentações.");
      setResumoPagina("Falha ao consultar movimentações.");
      totalPaginasAtual = 0;
      limparResumo();
      atualizarEstadoPaginacao();
      return;
    }

    const payload = resp?.dados || {};
    const dados = Array.isArray(payload?.dados) ? payload.dados : [];

    totalPaginasAtual = Number(payload?.paginas ?? 0);

    renderTabela(dados);
    renderResumo(payload);
    atualizarEstadoPaginacao();

    const total = Number(payload?.total ?? 0);
    setResumoPagina(`Exibindo ${dados.length} registro(s) nesta página. Total encontrado: ${total}.`);
    setStatus(
      total > 0
        ? `Consulta concluída. Página ${paginaAtual} carregada com sucesso.`
        : "Nenhum registro encontrado."
    );

    logJsInfo({
      origem: "movimentacoes.js",
      mensagem: "Movimentações carregadas com sucesso",
      pagina: paginaAtual,
      total_registros_pagina: dados.length,
      total_geral: total,
      resumo: payload?.resumo ?? null
    });
  } catch (err) {
    renderMensagemTabela("Erro inesperado ao carregar movimentações.", "text-danger");
    setStatus("Erro inesperado ao carregar movimentações.");
    setResumoPagina("Erro na consulta.");
    totalPaginasAtual = 0;
    limparResumo();
    atualizarEstadoPaginacao();

    logJsError({
      origem: "movimentacoes.js",
      mensagem: err?.message || "Erro ao listar movimentações",
      stack: err?.stack || null
    });
  }
}

async function buscarTodosRegistrosParaExportacao(filtros) {
  const payload = montarPayloadConsulta(filtros, 1, 5000);
  const resp = await apiRequest("listar_movimentacoes", payload, "GET");

  if (!resp?.sucesso) {
    throw new Error(resp?.mensagem || "Não foi possível buscar os dados para exportação.");
  }

  const dados = resp?.dados?.dados;
  return Array.isArray(dados) ? dados : [];
}

function montarLinhasExportacao(registros) {
  return registros.map((m) => ({
    ID: Number(m?.id ?? 0),
    Data: String(m?.data ?? ""),
    Produto: String(m?.produto_nome ?? ""),
    Fornecedor: String(m?.fornecedor_nome ?? ""),
    Tipo: String(m?.tipo ?? ""),
    Quantidade: Number(m?.quantidade ?? 0),
    "Valor Unitário": m?.valor_unitario != null ? Number(m.valor_unitario) : "",
    "Custo Unitário": m?.custo_unitario != null ? Number(m.custo_unitario) : "",
    "Custo Total": m?.custo_total != null ? Number(m.custo_total) : "",
    "Valor Total": m?.valor_total != null ? Number(m.valor_total) : "",
    Lucro: m?.lucro != null ? Number(m.lucro) : "",
    Usuário: String(m?.usuario ?? ""),
    Observação: String(m?.observacao ?? "")
  }));
}

async function exportarXlsx() {
  const filtros = getFiltrosTela();
  const filtrosPossuemValor = Object.values(filtros).some((v) => String(v || "").trim() !== "");

  if (!filtrosPossuemValor) {
    window.alert("Aplique pelo menos um filtro antes de exportar.");
    return;
  }

  try {
    setStatus("Preparando exportação XLSX...");

    const registros = await buscarTodosRegistrosParaExportacao(filtros);

    if (!registros.length) {
      window.alert("Nenhum registro encontrado para exportar.");
      setStatus("Nenhum registro encontrado para exportação.");
      return;
    }

    const linhas = montarLinhasExportacao(registros);
    const worksheet = window.XLSX.utils.json_to_sheet(linhas);
    const workbook = window.XLSX.utils.book_new();

    window.XLSX.utils.book_append_sheet(workbook, worksheet, "Movimentacoes");
    window.XLSX.writeFile(workbook, "movimentacoes.xlsx");

    setStatus(`Exportação XLSX concluída com ${registros.length} registro(s).`);
  } catch (err) {
    setStatus("Erro ao exportar XLSX.");

    logJsError({
      origem: "movimentacoes.js",
      mensagem: "Erro ao exportar XLSX",
      detalhe: err?.message,
      stack: err?.stack
    });

    window.alert("Erro ao exportar XLSX.");
  }
}

async function exportarPdf() {
  const filtros = getFiltrosTela();
  const filtrosPossuemValor = Object.values(filtros).some((v) => String(v || "").trim() !== "");

  if (!filtrosPossuemValor) {
    window.alert("Aplique pelo menos um filtro antes de exportar.");
    return;
  }

  try {
    setStatus("Preparando exportação PDF...");

    const registros = await buscarTodosRegistrosParaExportacao(filtros);

    if (!registros.length) {
      window.alert("Nenhum registro encontrado para exportar.");
      setStatus("Nenhum registro encontrado para exportação.");
      return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: "landscape", unit: "mm", format: "a4" });

    const head = [[
      "ID",
      "Data",
      "Produto",
      "Fornecedor",
      "Tipo",
      "Qtd",
      "Custo Total",
      "Valor Total",
      "Lucro",
      "Usuário"
    ]];

    const body = registros.map((m) => [
      Number(m?.id ?? 0),
      String(m?.data ?? ""),
      String(m?.produto_nome ?? ""),
      String(m?.fornecedor_nome ?? ""),
      String(m?.tipo ?? ""),
      Number(m?.quantidade ?? 0),
      m?.custo_total != null ? formatBRL(m.custo_total) : "—",
      m?.valor_total != null ? formatBRL(m.valor_total) : "—",
      m?.lucro != null ? formatBRL(m.lucro) : "—",
      String(m?.usuario ?? "")
    ]);

    doc.setFontSize(14);
    doc.text("Relatório de Movimentações", 14, 15);

    doc.setFontSize(9);
    doc.text(`Gerado em: ${new Date().toLocaleString("pt-BR")}`, 14, 21);

    doc.autoTable({
      startY: 26,
      head,
      body,
      styles: {
        fontSize: 8,
        cellPadding: 2
      },
      headStyles: {
        fillColor: [52, 73, 94]
      }
    });

    doc.save("movimentacoes.pdf");

    setStatus(`Exportação PDF concluída com ${registros.length} registro(s).`);
  } catch (err) {
    setStatus("Erro ao exportar PDF.");

    logJsError({
      origem: "movimentacoes.js",
      mensagem: "Erro ao exportar PDF",
      detalhe: err?.message,
      stack: err?.stack
    });

    window.alert("Erro ao exportar PDF.");
  }
}

function preencherDetalhesMovimentacao(mov) {
  setTextoDetalhe("detalheMovTituloAux", `Movimentação #${Number(mov?.id ?? 0)} • ${String(mov?.data ?? "-")}`);
  setTextoDetalhe("detalheProduto", mov?.produto_nome || "-");
  setTextoDetalhe("detalheFornecedor", mov?.fornecedor_nome || "—");
  setTextoDetalhe("detalheTipo", mov?.tipo || "-");
  setTextoDetalhe("detalheQuantidade", String(Number(mov?.quantidade ?? 0)));
  setTextoDetalhe("detalheUsuario", mov?.usuario || "Sistema");
  setTextoDetalhe("detalheValorUnitario", mov?.valor_unitario != null ? formatBRL(mov.valor_unitario) : "—");
  setTextoDetalhe("detalheCustoUnitario", mov?.custo_unitario != null ? formatBRL(mov.custo_unitario) : "—");
  setTextoDetalhe("detalheCustoTotal", mov?.custo_total != null ? formatBRL(mov.custo_total) : "—");
  setTextoDetalhe("detalheValorTotal", mov?.valor_total != null ? formatBRL(mov.valor_total) : "—");
  setTextoDetalhe("detalheLucro", mov?.lucro != null ? formatBRL(mov.lucro) : "—");
  setTextoDetalhe("detalheObservacao", mov?.observacao || "—");

  renderDetalhesLotes(Array.isArray(mov?.lotes) ? mov.lotes : []);
}

function renderDetalhesLotes(lotes) {
  const box = $("detalheLotesBox");
  if (!box) return;

  if (!Array.isArray(lotes) || lotes.length === 0) {
    box.innerHTML = `<div class="text-muted small">Nenhum lote vinculado.</div>`;
    return;
  }

  box.innerHTML = `
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle mb-0">
        <thead>
          <tr>
            <th style="width: 90px;">Lote</th>
            <th style="width: 170px;">Fornecedor do lote</th>
            <th style="width: 120px;">Qtd consumida</th>
            <th style="width: 130px;">Custo unitário</th>
            <th style="width: 130px;">Custo total</th>
            <th style="width: 170px;">Criado em</th>
          </tr>
        </thead>
        <tbody>
          ${lotes.map((l) => `
            <tr>
              <td>${Number(l?.lote_id ?? 0)}</td>
              <td>${escapeHtml(l?.fornecedor_nome || "—")}</td>
              <td>${Number(l?.quantidade_consumida ?? 0)}</td>
              <td>${l?.custo_unitario != null ? formatBRL(l.custo_unitario) : "—"}</td>
              <td>${l?.custo_total != null ? formatBRL(l.custo_total) : "—"}</td>
              <td>${escapeHtml(l?.lote_criado_em || "—")}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>
  `;
}

async function abrirDetalhesMovimentacao(movimentacaoId) {
  const modal = getModalDetalhes();
  if (!modal) return;

  setTextoDetalhe("detalheMovTituloAux", "Carregando...");
  setTextoDetalhe("detalheProduto", "-");
  setTextoDetalhe("detalheFornecedor", "-");
  setTextoDetalhe("detalheTipo", "-");
  setTextoDetalhe("detalheQuantidade", "-");
  setTextoDetalhe("detalheUsuario", "-");
  setTextoDetalhe("detalheValorUnitario", "-");
  setTextoDetalhe("detalheCustoUnitario", "-");
  setTextoDetalhe("detalheCustoTotal", "-");
  setTextoDetalhe("detalheValorTotal", "-");
  setTextoDetalhe("detalheLucro", "-");
  setTextoDetalhe("detalheObservacao", "-");
  renderDetalhesLotes([]);

  modal.show();

  try {
    const resp = await apiRequest("obter_movimentacao", { movimentacao_id: movimentacaoId }, "GET");

    if (!resp?.sucesso || !resp?.dados) {
      setTextoDetalhe("detalheMovTituloAux", "Não foi possível carregar os detalhes.");
      return;
    }

    preencherDetalhesMovimentacao(resp.dados);
  } catch (err) {
    setTextoDetalhe("detalheMovTituloAux", "Erro ao carregar detalhes.");

    logJsError({
      origem: "movimentacoes.js",
      mensagem: "Erro ao abrir detalhes da movimentação",
      detalhe: err?.message,
      stack: err?.stack,
      movimentacao_id: movimentacaoId
    });
  }
}

function limparFiltros() {
  if ($("filtroProduto")) $("filtroProduto").value = "";
  if ($("filtroProdutoId")) $("filtroProdutoId").value = "";
  if ($("filtroFornecedor")) $("filtroFornecedor").value = "";
  if ($("filtroTipo")) $("filtroTipo").value = "";
  if ($("filtroDataInicio")) $("filtroDataInicio").value = "";
  if ($("filtroDataFim")) $("filtroDataFim").value = "";

  ultimoFiltroAplicado = {};
  paginaAtual = 1;
  totalPaginasAtual = 0;

  limparSugestoesProduto();
  renderMensagemTabela("Use os filtros para buscar movimentações.");
  setStatus("Filtros limpos.");
  setResumoPagina("Nenhuma consulta realizada.");
  limparResumo();
  atualizarEstadoPaginacao();
}

function bindAutocompleteProduto() {
  const input = $("filtroProduto");
  const box = $("filtroProdutoSugestoes");

  if (!input || !box) return;

  input.addEventListener("input", () => {
    const termo = input.value.trim();

    if ($("filtroProdutoId")) $("filtroProdutoId").value = "";

    if (!termo) {
      limparSugestoesProduto();
      return;
    }

    buscarProdutosAutocompleteDebounced(termo);
  });

  input.addEventListener("keydown", (ev) => {
    if (ev.key === "Enter") {
      const termo = input.value.trim();
      if (!termo) return;

      const encontrados = produtosCache.filter((p) =>
        String(p?.nome ?? "").toLowerCase().includes(termo.toLowerCase())
      );

      if (encontrados.length > 0) {
        ev.preventDefault();
        selecionarProdutoSugestao(encontrados[0].id, encontrados[0].nome || termo);
      }

      limparSugestoesProduto();
      listarMovimentacoes(getFiltrosTela(), 1);
    }

    if (ev.key === "Escape") {
      limparSugestoesProduto();
    }
  });

  box.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-produto-id][data-produto-nome]");
    if (!btn) return;

    selecionarProdutoSugestao(
      Number(btn.dataset.produtoId || 0),
      btn.dataset.produtoNome || ""
    );
    input.focus();
  });

  document.addEventListener("click", (ev) => {
    const clicouNoInput = ev.target.closest("#filtroProduto");
    const clicouNaLista = ev.target.closest("#filtroProdutoSugestoes");

    if (!clicouNoInput && !clicouNaLista) {
      limparSugestoesProduto();
    }
  });
}

function bindEventos() {
  $("btnBuscarMovimentacoes")?.addEventListener("click", () => {
    const filtros = getFiltrosTela();
    listarMovimentacoes(filtros, 1);
  });

  $("btnLimparMovimentacoes")?.addEventListener("click", limparFiltros);
  $("btnExportarXlsx")?.addEventListener("click", exportarXlsx);
  $("btnExportarPdf")?.addEventListener("click", exportarPdf);

  $("btnPaginaAnterior")?.addEventListener("click", () => {
    if (paginaAtual <= 1) return;
    listarMovimentacoes(ultimoFiltroAplicado, paginaAtual - 1);
  });

  $("btnPaginaProxima")?.addEventListener("click", () => {
    if (paginaAtual >= totalPaginasAtual) return;
    listarMovimentacoes(ultimoFiltroAplicado, paginaAtual + 1);
  });

  $("filtroTipo")?.addEventListener("change", () => {
    if (Object.values(getFiltrosTela()).some((v) => String(v || "").trim() !== "")) {
      listarMovimentacoes(getFiltrosTela(), 1);
    }
  });

  $("filtroFornecedor")?.addEventListener("change", () => {
    if (Object.values(getFiltrosTela()).some((v) => String(v || "").trim() !== "")) {
      listarMovimentacoes(getFiltrosTela(), 1);
    }
  });

  $("filtroDataInicio")?.addEventListener("change", () => {
    if (Object.values(getFiltrosTela()).some((v) => String(v || "").trim() !== "")) {
      listarMovimentacoes(getFiltrosTela(), 1);
    }
  });

  $("filtroDataFim")?.addEventListener("change", () => {
    if (Object.values(getFiltrosTela()).some((v) => String(v || "").trim() !== "")) {
      listarMovimentacoes(getFiltrosTela(), 1);
    }
  });

  document.querySelector("#tabelaMovimentacoes tbody")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-acao='detalhes'][data-id]");
    if (!btn) return;

    abrirDetalhesMovimentacao(Number(btn.dataset.id || 0));
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  bindEventos();
  bindAutocompleteProduto();
  await carregarProdutosAutocomplete();
  await carregarFornecedoresFiltro();
  atualizarEstadoPaginacao();
});