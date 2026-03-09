// js/movimentacoes.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let paginaAtual = 1;
const limitePorPagina = 10;
let ultimoFiltroAplicado = {};
let totalPaginasAtual = 0;
let modalDetalhesInstance = null;

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

function getFiltrosTela() {
  return {
    produto: ($("filtroProduto")?.value ?? "").trim(),
    fornecedor_id: ($("filtroFornecedor")?.value ?? "").trim(),
    tipo: ($("filtroTipo")?.value ?? "").trim(),
    data_inicio: ($("filtroDataInicio")?.value ?? "").trim(),
    data_fim: ($("filtroDataFim")?.value ?? "").trim()
  };
}

function montarPayloadConsulta(filtros, pagina) {
  const payload = {
    pagina,
    limite: limitePorPagina
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

  if (t === "entrada") {
    return `<span class="badge bg-success">Entrada</span>`;
  }

  if (t === "saida") {
    return `<span class="badge bg-danger">Saída</span>`;
  }

  if (t === "remocao") {
    return `<span class="badge bg-secondary">Remoção</span>`;
  }

  return `<span class="badge bg-dark">${escapeHtml(tipo || "-")}</span>`;
}

function renderResumo(payload) {
  const dados = Array.isArray(payload?.dados) ? payload.dados : [];
  const totalRegistros = Number(payload?.total ?? 0);

  let quantidadeTotal = 0;
  let custoTotal = 0;
  let lucroTotal = 0;

  for (const item of dados) {
    quantidadeTotal += Number(item?.quantidade ?? 0);
    custoTotal += Number(item?.custo_total ?? 0);
    lucroTotal += Number(item?.lucro ?? 0);
  }

  if ($("resumoTotalRegistros")) $("resumoTotalRegistros").textContent = String(totalRegistros);
  if ($("resumoQuantidadeTotal")) $("resumoQuantidadeTotal").textContent = String(quantidadeTotal);
  if ($("resumoCustoTotal")) $("resumoCustoTotal").textContent = formatBRL(custoTotal);
  if ($("resumoLucroTotal")) $("resumoLucroTotal").textContent = formatBRL(lucroTotal);
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

async function listarMovimentacoes(filtros = {}, pagina = 1) {
  const filtrosPossuemValor = Object.values(filtros).some((v) => String(v || "").trim() !== "");

  if (!filtrosPossuemValor) {
    renderMensagemTabela("Use os filtros para buscar movimentações.");
    setStatus("Aguardando filtros para consulta.");
    setResumoPagina("Nenhuma consulta realizada.");
    paginaAtual = 1;
    totalPaginasAtual = 0;
    renderResumo({ total: 0, dados: [] });
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
      renderResumo({ total: 0, dados: [] });
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
      total_geral: total
    });
  } catch (err) {
    renderMensagemTabela("Erro inesperado ao carregar movimentações.", "text-danger");
    setStatus("Erro inesperado ao carregar movimentações.");
    setResumoPagina("Erro na consulta.");
    totalPaginasAtual = 0;
    renderResumo({ total: 0, dados: [] });
    atualizarEstadoPaginacao();

    logJsError({
      origem: "movimentacoes.js",
      mensagem: err?.message || "Erro ao listar movimentações",
      stack: err?.stack || null
    });
  }
}

function limparFiltros() {
  if ($("filtroProduto")) $("filtroProduto").value = "";
  if ($("filtroFornecedor")) $("filtroFornecedor").value = "";
  if ($("filtroTipo")) $("filtroTipo").value = "";
  if ($("filtroDataInicio")) $("filtroDataInicio").value = "";
  if ($("filtroDataFim")) $("filtroDataFim").value = "";

  ultimoFiltroAplicado = {};
  paginaAtual = 1;
  totalPaginasAtual = 0;

  renderMensagemTabela("Use os filtros para buscar movimentações.");
  setStatus("Filtros limpos.");
  setResumoPagina("Nenhuma consulta realizada.");
  renderResumo({ total: 0, dados: [] });
  atualizarEstadoPaginacao();
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

function setTextoDetalhe(id, valor) {
  const el = $(id);
  if (el) el.textContent = String(valor ?? "");
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
            <th style="width: 100px;">Lote</th>
            <th style="width: 140px;">Qtd consumida</th>
            <th style="width: 140px;">Custo unitário</th>
            <th style="width: 140px;">Custo total</th>
          </tr>
        </thead>
        <tbody>
          ${lotes.map((l) => `
            <tr>
              <td>${Number(l?.lote_id ?? 0)}</td>
              <td>${Number(l?.quantidade_consumida ?? 0)}</td>
              <td>${l?.custo_unitario != null ? formatBRL(l.custo_unitario) : "—"}</td>
              <td>${l?.custo_total != null ? formatBRL(l.custo_total) : "—"}</td>
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

function bindEventos() {
  $("btnBuscarMovimentacoes")?.addEventListener("click", () => {
    const filtros = getFiltrosTela();
    listarMovimentacoes(filtros, 1);
  });

  $("btnLimparMovimentacoes")?.addEventListener("click", limparFiltros);

  $("btnPaginaAnterior")?.addEventListener("click", () => {
    if (paginaAtual <= 1) return;
    listarMovimentacoes(ultimoFiltroAplicado, paginaAtual - 1);
  });

  $("btnPaginaProxima")?.addEventListener("click", () => {
    if (paginaAtual >= totalPaginasAtual) return;
    listarMovimentacoes(ultimoFiltroAplicado, paginaAtual + 1);
  });

  document.querySelector("#tabelaMovimentacoes tbody")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-acao='detalhes'][data-id]");
    if (!btn) return;

    abrirDetalhesMovimentacao(Number(btn.dataset.id || 0));
  });

  $("filtroProduto")?.addEventListener("keydown", (ev) => {
    if (ev.key === "Enter") {
      ev.preventDefault();
      const filtros = getFiltrosTela();
      listarMovimentacoes(filtros, 1);
    }
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  bindEventos();
  await carregarFornecedoresFiltro();
  atualizarEstadoPaginacao();
});