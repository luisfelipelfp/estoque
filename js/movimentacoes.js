// js/movimentacoes.js
import { logJsError, logJsInfo } from "./logger.js";

let paginaAtual = 1;
const limitePorPagina = 10;

/**
 * Lista movimentações conforme filtros e paginação
 */
async function listarMovimentacoes(filtros = {}, pagina = 1) {
  try {
    const tbody = document.querySelector("#tabelaMovimentacoes tbody");
    const paginacao = document.querySelector("#paginacaoMovs");

    if (!tbody) return;

    // Nenhum filtro aplicado
    if (!Object.keys(filtros).length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="text-center text-muted">
            Use os filtros para buscar movimentações
          </td>
        </tr>`;
      if (paginacao) paginacao.innerHTML = "";
      return;
    }

    filtros.pagina = pagina;
    filtros.limite = limitePorPagina;

    logJsInfo({
      origem: "movimentacoes.js",
      mensagem: "Buscando movimentações",
      filtros,
      pagina
    });

    const resp = await apiRequest("listar_movimentacoes", filtros, "GET");

    const movs = Array.isArray(resp?.dados) ? resp.dados : [];
    const total = Number(resp?.total ?? movs.length);

    tbody.innerHTML = "";

    if (!movs.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="text-center">
            Nenhuma movimentação encontrada
          </td>
        </tr>`;
      if (paginacao) paginacao.innerHTML = "";
      return;
    }

    movs.forEach(m => {
      const tr = document.createElement("tr");

      const tipoClass =
        m.tipo === "entrada"
          ? "text-success fw-bold"
          : m.tipo === "saida"
          ? "text-danger fw-bold"
          : "text-muted fw-bold";

      tr.innerHTML = `
        <td>${m.id}</td>
        <td>${m.produto_nome || m.produto || m.produto_id}</td>
        <td class="${tipoClass}">${m.tipo}</td>
        <td>${m.quantidade}</td>
        <td>${m.data}</td>
        <td>${m.usuario_nome || m.usuario || "Sistema"}</td>
      `;

      tbody.appendChild(tr);
    });

    renderizarPaginacao(total, pagina);

  } catch (err) {
    console.error("Erro ao listar movimentações:", err);

    logJsError({
      origem: "movimentacoes.js",
      mensagem: "Falha ao listar movimentações",
      detalhe: err.message,
      stack: err.stack
    });
  }
}

/**
 * Renderiza paginação
 */
function renderizarPaginacao(total, pagina) {
  const divPag = document.querySelector("#paginacaoMovs");
  if (!divPag) return;

  const totalPaginas = Math.ceil(total / limitePorPagina);
  if (totalPaginas <= 1) {
    divPag.innerHTML = "";
    return;
  }

  let html = `<nav><ul class="pagination justify-content-center">`;

  html += `
    <li class="page-item ${pagina <= 1 ? "disabled" : ""}">
      <button class="page-link" data-pagina="${pagina - 1}">Anterior</button>
    </li>`;

  for (let p = 1; p <= totalPaginas; p++) {
    html += `
      <li class="page-item ${p === pagina ? "active" : ""}">
        <button class="page-link" data-pagina="${p}">${p}</button>
      </li>`;
  }

  html += `
    <li class="page-item ${pagina >= totalPaginas ? "disabled" : ""}">
      <button class="page-link" data-pagina="${pagina + 1}">Próximo</button>
    </li>`;

  html += `</ul></nav>`;

  divPag.innerHTML = html;

  divPag.querySelectorAll("button[data-pagina]").forEach(btn => {
    btn.addEventListener("click", async () => {
      const novaPagina = Number(btn.dataset.pagina);
      if (novaPagina > 0 && novaPagina <= totalPaginas && novaPagina !== pagina) {
        paginaAtual = novaPagina;
        await listarMovimentacoes(getFiltrosAtuais(), paginaAtual);
      }
    });
  });
}

/**
 * Lê filtros atuais da tela
 */
function getFiltrosAtuais() {
  const filtros = {};

  const produto_id = document.querySelector("#filtroProduto")?.value;
  const tipo = document.querySelector("#filtroTipo")?.value;
  const data_inicio = document.querySelector("#filtroDataInicio")?.value;
  const data_fim = document.querySelector("#filtroDataFim")?.value;

  if (produto_id) filtros.produto_id = produto_id;
  if (tipo) filtros.tipo = tipo;
  if (data_inicio) filtros.data_inicio = data_inicio;
  if (data_fim) filtros.data_fim = data_fim;

  return filtros;
}

/**
 * Preenche filtro de produtos
 */
async function preencherFiltroProdutos() {
  try {
    const resp = await apiRequest("listar_produtos", null, "GET");
    const produtos = Array.isArray(resp?.dados) ? resp.dados : [];
    const select = document.querySelector("#filtroProduto");

    if (!select) return;

    select.innerHTML = `<option value="">Todos os Produtos</option>`;

    produtos.forEach(p => {
      const opt = document.createElement("option");
      opt.value = p.id;
      opt.textContent = p.nome;
      select.appendChild(opt);
    });

    logJsInfo({
      origem: "movimentacoes.js",
      mensagem: "Filtro de produtos carregado",
      total: produtos.length
    });

  } catch (err) {
    console.error("Erro ao preencher filtro de produtos:", err);

    logJsError({
      origem: "movimentacoes.js",
      mensagem: "Falha ao carregar produtos para filtro",
      detalhe: err.message,
      stack: err.stack
    });
  }
}

/**
 * Eventos iniciais
 */
document.addEventListener("DOMContentLoaded", async () => {
  const formFiltros = document.querySelector("#formFiltrosMovimentacoes");

  if (formFiltros) {
    formFiltros.addEventListener("submit", async e => {
      e.preventDefault();
      paginaAtual = 1;
      await listarMovimentacoes(getFiltrosAtuais(), paginaAtual);
    });
  }

  await preencherFiltroProdutos();
});

/**
 * 🔑 Exposição mínima necessária
 */
window.listarMovimentacoes = listarMovimentacoes;
