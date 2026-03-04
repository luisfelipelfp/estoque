// js/modal_adicionar_produto.js
import { logJsInfo, logJsError } from "./logger.js";

/**
 * ✅ Importante:
 * - URL absoluta evita confusão de base path
 * - credentials: "same-origin" garante envio do PHPSESSID
 */
const API_ACTIONS = "/estoque/api/actions.php";

const DEBOUNCE_MS = 250;
const MIN_CHARS = 2;
const LIMIT_DEFAULT = 10;

let debounceTimer = null;

// ===== helpers =====
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
  const el = $("apStatus");
  if (!el) return;
  el.className = `me-auto small text-${type}`;
  el.textContent = msg || "";
}

function setDataAgora() {
  const el = $("apDataAgora");
  if (!el) return;
  const agora = new Date();
  el.textContent = agora.toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" });
}

// ===== Modal open/close =====
function abrirModal() {
  const modalEl = $("modalAdicionarProduto");
  if (!modalEl) return;

  // abre modal
  const modal = window.bootstrap?.Modal?.getOrCreateInstance(modalEl);
  modal?.show();

  // prepara UI
  setDataAgora();
  limparProdutoSelecionado({ keepSearchText: false });
  $("apQuantidade") && ($("apQuantidade").value = "1");
  $("apObs") && ($("apObs").value = "");
  $("apPrecoCusto") && ($("apPrecoCusto").value = "");
  setStatus("", "muted");

  // foco no input
  setTimeout(() => $("apProdutoBusca")?.focus(), 150);
}

function bindAbrirModal() {
  $("btnAbrirModalAdicionar")?.addEventListener("click", abrirModal);
}

// ===== Autocomplete (buscar produtos) =====
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
    credentials: "same-origin"
  });

  if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
  return resp.json();
}

function showSugestoes() {
  const box = $("apSugestoes");
  if (!box) return;
  box.style.display = "block";
}

function hideSugestoes() {
  const box = $("apSugestoes");
  if (!box) return;
  box.style.display = "none";
}

function renderSugestoes(itens) {
  const box = $("apSugestoes");
  if (!box) return;

  if (!Array.isArray(itens) || itens.length === 0) {
    box.innerHTML = `<div class="dropdown-item text-muted">Nenhum resultado.</div>`;
    showSugestoes();
    return;
  }

  box.innerHTML = itens
    .map((p) => {
      const id = Number(p?.id ?? 0);
      const nome = escapeHtml(p?.nome ?? "");
      const qtd = Number(p?.quantidade ?? 0);
      const preco = Number(p?.preco_custo ?? 0);

      return `
        <button type="button"
          class="dropdown-item d-flex justify-content-between align-items-center"
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

  showSugestoes();
}

function limparProdutoSelecionado({ keepSearchText = false } = {}) {
  const input = $("apProdutoBusca");
  const idHidden = $("apProdutoId");
  const estoqueEl = $("apEstoqueAtual");
  const infoEl = $("apProdutoInfo");
  const alertaEl = $("apAlertaEstoque");

  if (!keepSearchText && input) input.value = "";
  if (idHidden) idHidden.value = "";

  if (estoqueEl) estoqueEl.textContent = "-";
  if (infoEl) infoEl.textContent = "";
  if (alertaEl) alertaEl.textContent = "";

  hideSugestoes();
}

function setProdutoSelecionado({ id, nome, qtd, preco }) {
  const input = $("apProdutoBusca");
  const idHidden = $("apProdutoId");
  const estoqueEl = $("apEstoqueAtual");
  const precoEl = $("apPrecoCusto");
  const infoEl = $("apProdutoInfo");
  const alertaEl = $("apAlertaEstoque");

  if (input) input.value = nome;
  if (idHidden) idHidden.value = String(id);

  if (estoqueEl) estoqueEl.textContent = String(qtd ?? 0);
  if (infoEl) infoEl.textContent = `Selecionado: ${nome} (ID: ${id})`;

  // se quiser autopreencher custo quando estiver vazio
  if (precoEl && (precoEl.value === "" || Number(precoEl.value) === 0)) {
    if (Number(preco) > 0) precoEl.value = String(preco);
  }

  // alerta simples se tentar saída com estoque zerado (só visual)
  const tipoSaida = $("apTipoSaida")?.checked;
  if (alertaEl) {
    if (tipoSaida && Number(qtd) <= 0) alertaEl.textContent = "⚠ Estoque zerado!";
    else alertaEl.textContent = "";
  }

  hideSugestoes();
  setStatus(`Produto selecionado: ${nome}`, "success");
}

function bindClickSugestoes() {
  const box = $("apSugestoes");
  if (!box) return;

  box.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-produto-id]");
    if (!btn) return;

    const id = Number(btn.dataset.produtoId || 0);
    const nome = btn.dataset.produtoNome || "";
    const qtd = Number(btn.dataset.produtoQtd || 0);
    const preco = Number(btn.dataset.produtoPreco || 0);

    setProdutoSelecionado({ id, nome, qtd, preco });

    logJsInfo({
      origem: "modal_adicionar_produto.js",
      mensagem: "Produto selecionado no autocomplete",
      id,
      nome
    });
  });
}

function onInputProduto() {
  const input = $("apProdutoBusca");
  if (!input) return;

  const term = input.value.trim();

  // ao digitar, desmarca seleção
  $("apProdutoId") && ($("apProdutoId").value = "");
  $("apProdutoInfo") && ($("apProdutoInfo").textContent = "");
  $("apEstoqueAtual") && ($("apEstoqueAtual").textContent = "-");
  $("apAlertaEstoque") && ($("apAlertaEstoque").textContent = "");

  if (term.length < MIN_CHARS) {
    const box = $("apSugestoes");
    if (box) {
      box.innerHTML = `<div class="dropdown-item text-muted">Digite pelo menos ${MIN_CHARS} letras…</div>`;
      showSugestoes();
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

function bindAutocomplete() {
  const input = $("apProdutoBusca");
  if (!input) return;

  input.addEventListener("input", onInputProduto);
  input.addEventListener("focus", () => {
    if (input.value.trim().length >= MIN_CHARS) onInputProduto();
  });

  $("apLimparProduto")?.addEventListener("click", () => {
    limparProdutoSelecionado({ keepSearchText: false });
    $("apProdutoBusca")?.focus();
  });

  // fecha sugestões ao clicar fora
  document.addEventListener("click", (ev) => {
    const box = $("apSugestoes");
    if (!box || box.style.display === "none") return;

    const inside = ev.target.closest("#apSugestoes, #apProdutoBusca");
    if (!inside) hideSugestoes();
  });

  bindClickSugestoes();
}

// ===== extras do modal =====
function bindStepper() {
  $("apQtdMenos")?.addEventListener("click", () => {
    const el = $("apQuantidade");
    if (!el) return;
    const n = Math.max(1, Number(el.value || 1) - 1);
    el.value = String(n);
  });

  $("apQtdMais")?.addEventListener("click", () => {
    const el = $("apQuantidade");
    if (!el) return;
    const n = Math.max(1, Number(el.value || 1) + 1);
    el.value = String(n);
  });
}

function bindTipoAlerta() {
  const update = () => {
    const qtdAtual = Number($("apEstoqueAtual")?.textContent || 0);
    const alertaEl = $("apAlertaEstoque");
    if (!alertaEl) return;

    if ($("apTipoSaida")?.checked && qtdAtual <= 0) alertaEl.textContent = "⚠ Estoque zerado!";
    else alertaEl.textContent = "";
  };

  $("apTipoEntrada")?.addEventListener("change", update);
  $("apTipoSaida")?.addEventListener("change", update);
}

function bindBotoesFuturos() {
  // por enquanto só informativo (você pode ligar isso depois)
  $("apCriarProduto")?.addEventListener("click", () => {
    setStatus("Em breve: criar produto direto no modal 😉", "muted");
  });

  $("apSalvar")?.addEventListener("click", () => {
    // Ainda vamos implementar o POST (criar produto / registrar movimentação)
    // aqui vamos só validar rapidamente para evitar “salvar vazio”.
    const nome = $("apProdutoBusca")?.value?.trim() || "";
    const id = Number($("apProdutoId")?.value || 0);

    if (!nome) {
      setStatus("Informe o nome do produto.", "danger");
      $("apProdutoBusca")?.focus();
      return;
    }

    if (id <= 0) {
      setStatus("Selecione um produto existente na lista (autocomplete) ou use 'Criar produto'.", "danger");
      return;
    }

    setStatus("Próximo passo: salvar (API) — vamos ligar isso agora.", "muted");
  });
}

// ===== init =====
document.addEventListener("DOMContentLoaded", () => {
  bindAbrirModal();
  bindAutocomplete();
  bindStepper();
  bindTipoAlerta();
  bindBotoesFuturos();
});