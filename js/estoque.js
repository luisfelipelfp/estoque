// js/estoque.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

function $(id) {
  return document.getElementById(id);
}

function escapeHtml(s) {
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

let produtosCache = [];

function renderTabela(produtos) {
  const tbody = $("tabelaProdutos");
  if (!tbody) return;

  if (!Array.isArray(produtos) || produtos.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="4" class="text-center text-muted">Nenhum produto encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = produtos
    .map((p) => {
      const id = Number(p?.id ?? 0);
      const nome = escapeHtml(p?.nome ?? "");
      const qtd = Number(p?.quantidade ?? 0);

      return `
        <tr>
          <td>${id}</td>
          <td>${nome}</td>
          <td><span class="badge bg-${qtd > 0 ? "primary" : "secondary"}">${qtd}</span></td>
          <td class="text-nowrap">
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-sm btn-outline-success" data-acao="entrada" data-id="${id}" data-nome="${escapeHtml(p?.nome ?? "")}" data-qtd="${qtd}">
                Entrada
              </button>
              <button class="btn btn-sm btn-outline-danger" data-acao="saida" data-id="${id}" data-nome="${escapeHtml(p?.nome ?? "")}" data-qtd="${qtd}">
                Saída
              </button>
            </div>
          </td>
        </tr>
      `;
    })
    .join("");
}

function aplicarFiltro() {
  const term = ($("buscaProduto")?.value ?? "").trim().toLowerCase();
  if (!term) {
    renderTabela(produtosCache);
    return;
  }

  const filtrados = produtosCache.filter((p) =>
    String(p?.nome ?? "").toLowerCase().includes(term)
  );

  renderTabela(filtrados);
}

async function carregarProdutos() {
  try {
    const resp = await apiRequest("listar_produtos", {}, "GET");
    if (!resp?.sucesso) {
      renderTabela([]);
      return;
    }

    // seu produtos_listar retorna array direto
    const dados = resp?.dados ?? [];
    produtosCache = Array.isArray(dados) ? dados : [];
    aplicarFiltro();

    logJsInfo({
      origem: "estoque.js",
      mensagem: "Produtos carregados",
      total: produtosCache.length,
    });
  } catch (err) {
    renderTabela([]);
    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao carregar produtos",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function bindTabelaAcoes() {
  $("tabelaProdutos")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-acao][data-id]");
    if (!btn) return;

    const acao = btn.dataset.acao;
    const id = Number(btn.dataset.id || 0);
    const nome = btn.dataset.nome || "";
    const qtd = Number(btn.dataset.qtd || 0);

    if (!id || !window.abrirModalAdicionarProduto) return;

    window.abrirModalAdicionarProduto({
      preselectProduto: { id, nome, quantidade: qtd, preco_custo: 0 },
      preselectTipo: acao === "saida" ? "saida" : "entrada",
    });
  });
}

function bindBusca() {
  $("buscaProduto")?.addEventListener("input", aplicarFiltro);
}

document.addEventListener("DOMContentLoaded", () => {
  bindTabelaAcoes();
  bindBusca();
  carregarProdutos();

  // quando o modal salvar, ele dispara este evento
  window.addEventListener("estoque:atualizar", () => {
    carregarProdutos();
  });
});