// js/movimentacoes.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let paginaAtual = 1;
const limitePorPagina = 10;
let ultimoFiltroAplicado = {};

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

function setStatus(texto = "") {
  const topo = $("movimentacoesInfoTopo");
  const rodape = $("movimentacoesStatusRodape");

  if (topo) topo.textContent = texto;
  if (rodape) rodape.textContent = texto;
}

function getFiltrosTela() {
  return {
    produto: ($("filtroProduto")?.value ?? "").trim(),
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

  if (filtros.tipo) payload.tipo = filtros.tipo;
  if (filtros.data_inicio) payload.data_inicio = filtros.data_inicio;
  if (filtros.data_fim) payload.data_fim = filtros.data_fim;

  return payload;
}

function atualizarEstadoPaginacao(qtdRegistros = 0) {
  const btnAnterior = $("btnPaginaAnterior");
  const btnProxima = $("btnPaginaProxima");

  if (btnAnterior) {
    btnAnterior.disabled = paginaAtual <= 1;
  }

  if (btnProxima) {
    btnProxima.disabled = qtdRegistros < limitePorPagina;
  }
}

function renderMensagemTabela(texto, classe = "text-muted") {
  const tbody = document.querySelector("#tabelaMovimentacoes tbody");
  if (!tbody) return;

  tbody.innerHTML = `
    <tr>
      <td colspan="6" class="text-center ${classe}">
        ${escapeHtml(texto)}
      </td>
    </tr>
  `;
}

function renderTabela(movimentacoes) {
  const tbody = document.querySelector("#tabelaMovimentacoes tbody");
  if (!tbody) return;

  if (!Array.isArray(movimentacoes) || movimentacoes.length === 0) {
    renderMensagemTabela("Nenhuma movimentação encontrada para os filtros informados.");
    atualizarEstadoPaginacao(0);
    return;
  }

  tbody.innerHTML = movimentacoes.map((m) => `
    <tr>
      <td>${Number(m?.id ?? 0)}</td>
      <td>${escapeHtml(m?.produto_nome ?? "-")}</td>
      <td>${escapeHtml(m?.tipo ?? "-")}</td>
      <td>${Number(m?.quantidade ?? 0)}</td>
      <td>${escapeHtml(m?.data ?? "-")}</td>
      <td>${escapeHtml(m?.usuario ?? "Sistema")}</td>
    </tr>
  `).join("");

  atualizarEstadoPaginacao(movimentacoes.length);
}

async function listarMovimentacoes(filtros = {}, pagina = 1) {
  const filtrosPossuemValor = Object.values(filtros).some((v) => String(v || "").trim() !== "");

  if (!filtrosPossuemValor) {
    renderMensagemTabela("Use os filtros para buscar movimentações.");
    setStatus("Aguardando filtros para consulta.");
    paginaAtual = 1;
    atualizarEstadoPaginacao(0);
    return;
  }

  try {
    renderMensagemTabela("Carregando movimentações...");
    setStatus("Consultando movimentações...");

    paginaAtual = pagina;
    ultimoFiltroAplicado = { ...filtros };

    const payload = montarPayloadConsulta(filtros, paginaAtual);
    const resp = await apiRequest("listar_movimentacoes", payload, "GET");

    if (!resp?.sucesso) {
      renderMensagemTabela(resp?.mensagem || "Erro ao buscar movimentações.", "text-danger");
      setStatus(resp?.mensagem || "Erro ao buscar movimentações.");
      atualizarEstadoPaginacao(0);
      return;
    }

    const dados = Array.isArray(resp?.dados) ? resp.dados : [];
    renderTabela(dados);

    setStatus(
      dados.length > 0
        ? `Página ${paginaAtual} carregada com ${dados.length} registro(s).`
        : "Nenhum registro encontrado."
    );

    logJsInfo({
      origem: "movimentacoes.js",
      mensagem: "Movimentações carregadas com sucesso",
      pagina: paginaAtual,
      total_registros: dados.length
    });
  } catch (err) {
    renderMensagemTabela("Erro inesperado ao carregar movimentações.", "text-danger");
    setStatus("Erro inesperado ao carregar movimentações.");
    atualizarEstadoPaginacao(0);

    logJsError({
      origem: "movimentacoes.js",
      mensagem: err?.message || "Erro ao listar movimentações",
      stack: err?.stack || null
    });
  }
}

function limparFiltros() {
  if ($("filtroProduto")) $("filtroProduto").value = "";
  if ($("filtroTipo")) $("filtroTipo").value = "";
  if ($("filtroDataInicio")) $("filtroDataInicio").value = "";
  if ($("filtroDataFim")) $("filtroDataFim").value = "";

  ultimoFiltroAplicado = {};
  paginaAtual = 1;
  renderMensagemTabela("Use os filtros para buscar movimentações.");
  setStatus("Filtros limpos.");
  atualizarEstadoPaginacao(0);
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
    listarMovimentacoes(ultimoFiltroAplicado, paginaAtual + 1);
  });
}

document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
  atualizarEstadoPaginacao(0);
});