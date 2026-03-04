// js/modal_adicionar_produto.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

/**
 * Ajuste importante:
 * - Usar URL absoluta (/estoque/...) evita confusão de base path
 * - credentials: "same-origin" garante envio do PHPSESSID
 */
const API_ACTIONS = "/estoque/api/actions.php";

const DEBOUNCE_MS = 250;
const MIN_CHARS = 2;
const LIMIT_DEFAULT = 10;

let debounceTimer = null;

// ====== helpers ======
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

function formatBRL(valor) {
  const n = Number(valor || 0);
  return n.toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function setStatus(msg, type = "muted") {
  // opcional: se você tiver uma área de status no modal
  const el = $("modalAddStatus");
  if (!el) return;
  el.className = `small text-${type}`;
  el.textContent = msg;
}

// ====== autocomplete ======
async function buscarProdutos(term, limit = LIMIT_DEFAULT) {
  const qs = new URLSearchParams({
    acao: "buscar_produtos",
    q: term,
    limit: String(limit)
  });

  const url = `${API_ACTIONS}?${qs.toString()}`;

  const resp = await fetch(url, {
    method: "GET",
    headers: { Accept: "application/json" },
    cache: "no-store",
    credentials: "same-origin" // ✅ manda PHPSESSID
  });

  if (!resp.ok) {
    throw new Error(`HTTP ${resp.status}`);
  }
  return resp.json();
}

function renderSugestoes(itens) {
  const box = $("listaSugestoesProdutos");
  if (!box) return;

  if (!Array.isArray(itens) || itens.length === 0) {
    box.innerHTML = `<div class="px-2 py-2 text-muted">Nenhum resultado.</div>`;
    box.classList.remove("d-none");
    return;
  }

  box.innerHTML = itens
    .map((p) => {
      const id = p?.id ?? "";
      const nome = escapeHtml(p?.nome ?? "");
      const qtd = Number(p?.quantidade ?? 0);
      const preco = Number(p?.preco_custo ?? 0);

      return `
        <button type="button"
          class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
          data-produto-id="${id}"
          data-produto-nome="${escapeHtml(p?.nome ?? "")}"
          data-produto-qtd="${qtd}"
          data-produto-preco="${preco}">
          <span class="me-2">${nome}</span>
          <small class="text-muted">Qtd: ${qtd}</small>
        </button>
      `;
    })
    .join("");

  box.classList.remove("d-none");
}

function bindClickSugestoes() {
  const box = $("listaSugestoesProdutos");
  if (!box) return;

  box.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-produto-id]");
    if (!btn) return;

    const id = Number(btn.dataset.produtoId || 0);
    const nome = btn.dataset.produtoNome || "";
    const qtd = Number(btn.dataset.produtoQtd || 0);
    const preco = Number(btn.dataset.produtoPreco || 0);

    // campos do modal (ajuste os IDs conforme seu HTML do modal)
    const inputNome = $("modalProdutoNome");
    const inputId = $("modalProdutoId");
    const elEstoqueAtual = $("modalEstoqueAtual");
    const inputPreco = $("modalPrecoCusto");

    if (inputNome) inputNome.value = nome;
    if (inputId) inputId.value = String(id);

    if (elEstoqueAtual) elEstoqueAtual.textContent = String(qtd);

    // se quiser autopreencher preço custo
    if (inputPreco && !inputPreco.value) {
      // deixa como número simples, sua máscara/formatador pode atuar depois
      inputPreco.value = String(preco || "");
    }

    // fecha sugestões
    box.classList.add("d-none");

    setStatus(`Produto selecionado: ${nome}`, "success");
  });
}

// ====== input handling ======
function onInputProdutoNome() {
  const input = $("modalProdutoNome");
  const box = $("listaSugestoesProdutos");
  const hiddenId = $("modalProdutoId");

  if (!input) return;

  const term = input.value.trim();

  // se o usuário alterar texto, "desseleciona" o produto
  if (hiddenId) hiddenId.value = "";

  if (term.length < MIN_CHARS) {
    if (box) {
      box.innerHTML = `<div class="px-2 py-2 text-muted">Digite pelo menos ${MIN_CHARS} letras...</div>`;
      box.classList.remove("d-none");
    }
    return;
  }

  if (debounceTimer) clearTimeout(debounceTimer);

  debounceTimer = setTimeout(async () => {
    try {
      setStatus("Buscando produtos...", "muted");
      const json = await buscarProdutos(term, LIMIT_DEFAULT);

      if (!json?.sucesso) {
        renderSugestoes([]);
        setStatus(json?.mensagem || "Falha na busca.", "danger");
        return;
      }

      const itens = json?.dados?.itens || [];
      renderSugestoes(itens);
      setStatus(`${itens.length} resultado(s)`, "muted");

      logJsInfo({
        origem: "modal_adicionar_produto.js",
        mensagem: "Busca de produtos",
        termo: term,
        resultados: itens.length
      });
    } catch (err) {
      renderSugestoes([]);
      setStatus("Erro ao buscar produtos (sessão/cookie).", "danger");

      logJsError({
        origem: "modal_adicionar_produto.js",
        mensagem: "Erro ao buscar produtos",
        detalhe: err?.message,
        stack: err?.stack
      });
    }
  }, DEBOUNCE_MS);
}

function bindModal() {
  // IDs esperados no modal:
  // - modalProdutoNome (input text)
  // - modalProdutoId (hidden)
  // - listaSugestoesProdutos (div list-group)
  // - modalEstoqueAtual (span)
  // - modalPrecoCusto (input)
  const input = $("modalProdutoNome");
  if (!input) return;

  input.addEventListener("input", onInputProdutoNome);
  input.addEventListener("focus", onInputProdutoNome);

  // fechar sugestões ao clicar fora
  document.addEventListener("click", (ev) => {
    const box = $("listaSugestoesProdutos");
    if (!box || box.classList.contains("d-none")) return;

    const inside = ev.target.closest("#listaSugestoesProdutos, #modalProdutoNome");
    if (!inside) box.classList.add("d-none");
  });

  bindClickSugestoes();
}

// ====== init ======
document.addEventListener("DOMContentLoaded", () => {
  bindModal();
});