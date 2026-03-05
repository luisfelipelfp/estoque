// js/modal_adicionar_produto.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

const DEBOUNCE_MS = 250;
const MIN_CHARS = 2;
const LIMIT_DEFAULT = 10;

let debounceTimer = null;
let modalInstance = null;

// produto selecionado no modal
let selectedProduto = null; // {id, nome, quantidade, preco_custo}

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

function nowBR() {
  const d = new Date();
  const pad2 = (n) => String(n).padStart(2, "0");
  return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function setStatus(msg, type = "muted") {
  const el = $("apStatus");
  if (!el) return;
  el.className = `me-auto small text-${type}`;
  el.textContent = msg || "";
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

function limparSelecaoProduto({ limparCampo = false } = {}) {
  selectedProduto = null;
  $("apProdutoInfo") && ($("apProdutoInfo").textContent = "");
  $("apUltimasMov") && ($("apUltimasMov").textContent = "Selecione um produto…");
  $("apVerHistorico") && ($("apVerHistorico").disabled = true);
  $("apEstoqueAtual") && ($("apEstoqueAtual").textContent = "-");
  $("apAlertaEstoque") && ($("apAlertaEstoque").textContent = "");

  if (limparCampo && $("apProdutoBusca")) $("apProdutoBusca").value = "";
}

function renderSugestoes(itens) {
  const box = $("apSugestoes");
  if (!box) return;

  if (!Array.isArray(itens) || itens.length === 0) {
    box.innerHTML = `<div class="px-3 py-2 text-muted">Nenhum resultado.</div>`;
    showSugestoes();
    return;
  }

  box.innerHTML = itens
    .map((p) => {
      const id = Number(p?.id ?? 0);
      const nomeRaw = String(p?.nome ?? "");
      const nome = escapeHtml(nomeRaw);
      const qtd = Number(p?.quantidade ?? 0);
      const preco = Number(p?.preco_custo ?? 0);

      return `
        <button type="button"
          class="dropdown-item d-flex justify-content-between align-items-center"
          data-id="${id}"
          data-nome="${escapeHtml(nomeRaw)}"
          data-qtd="${qtd}"
          data-preco="${preco}">
          <span class="me-2">${nome}</span>
          <small class="text-muted">Qtd: ${qtd}</small>
        </button>
      `;
    })
    .join("");

  showSugestoes();
}

async function buscarProdutos(term, limit = LIMIT_DEFAULT) {
  return apiRequest("buscar_produtos", { q: term, limit }, "GET");
}

async function carregarResumoProduto(produto_id) {
  return apiRequest("produto_resumo", { produto_id }, "GET");
}

function renderResumoNoModal(resumo) {
  const prod = resumo?.produto;
  const movs = resumo?.ultimas_movimentacoes ?? [];

  if (!prod) return;

  $("apEstoqueAtual") && ($("apEstoqueAtual").textContent = String(prod.quantidade ?? 0));

  // alerta simples (se quiser, depois a gente coloca estoque mínimo)
  const qtd = Number(prod.quantidade ?? 0);
  if ($("apAlertaEstoque")) {
    $("apAlertaEstoque").textContent = qtd <= 0 ? "⚠ Sem estoque" : "";
  }

  if ($("apProdutoInfo")) {
    const preco = Number(prod.preco_custo ?? 0);
    $("apProdutoInfo").innerHTML = `
      <span class="text-muted">Selecionado:</span>
      <strong>${escapeHtml(prod.nome)}</strong>
      <span class="text-muted"> | Preço custo:</span> <strong>R$ ${formatBRL(preco)}</strong>
    `;
  }

  const elMov = $("apUltimasMov");
  if (elMov) {
    if (!movs.length) {
      elMov.innerHTML = `<span class="text-muted">Sem movimentações ainda.</span>`;
    } else {
      elMov.innerHTML = movs
        .map((m) => {
          const tipo = String(m?.tipo ?? "");
          const badge =
            tipo === "entrada" ? "success" :
            tipo === "saida" ? "danger" :
            tipo === "remocao" ? "warning text-dark" : "secondary";

          return `
            <div class="d-flex justify-content-between align-items-center mb-1">
              <div>
                <span class="badge bg-${badge}">${escapeHtml(tipo)}</span>
                <span class="ms-2">${escapeHtml(m?.usuario ?? "Sistema")}</span>
              </div>
              <div class="text-muted">
                <strong>${Number(m?.quantidade ?? 0)}</strong>
                <span class="ms-2">${escapeHtml(m?.data ?? "")}</span>
              </div>
            </div>
          `;
        })
        .join("");
    }
  }

  $("apVerHistorico") && ($("apVerHistorico").disabled = false);
}

function selecionarProduto(p) {
  selectedProduto = {
    id: Number(p.id),
    nome: String(p.nome),
    quantidade: Number(p.quantidade ?? 0),
    preco_custo: Number(p.preco_custo ?? 0),
  };

  // preenche input com nome selecionado
  const input = $("apProdutoBusca");
  if (input) input.value = selectedProduto.nome;

  // se preço custo estiver vazio, autopreenche com o cadastrado
  const inputPreco = $("apPrecoCusto");
  if (inputPreco && inputPreco.value === "") {
    inputPreco.value = selectedProduto.preco_custo ? String(selectedProduto.preco_custo) : "";
  }

  hideSugestoes();
  setStatus(`Produto selecionado: ${selectedProduto.nome}`, "success");

  // carrega resumo
  carregarResumoProduto(selectedProduto.id)
    .then((resp) => {
      if (!resp?.sucesso) {
        setStatus(resp?.mensagem || "Falha ao carregar resumo do produto.", "danger");
        return;
      }
      renderResumoNoModal(resp?.dados);
    })
    .catch((err) => {
      setStatus("Erro ao carregar resumo do produto.", "danger");
      logJsError({
        origem: "modal_adicionar_produto.js",
        mensagem: "Erro ao carregar resumo",
        detalhe: err?.message,
        stack: err?.stack,
      });
    });
}

function onInputProdutoBusca() {
  const input = $("apProdutoBusca");
  if (!input) return;

  const term = input.value.trim();

  // se usuário mexer no texto, desmarca seleção atual
  selectedProduto = null;
  $("apProdutoInfo") && ($("apProdutoInfo").textContent = "");
  $("apUltimasMov") && ($("apUltimasMov").textContent = "Selecione um produto…");
  $("apVerHistorico") && ($("apVerHistorico").disabled = true);
  $("apEstoqueAtual") && ($("apEstoqueAtual").textContent = "-");
  $("apAlertaEstoque") && ($("apAlertaEstoque").textContent = "");

  if (term.length < MIN_CHARS) {
    renderSugestoes([]);
    return;
  }

  if (debounceTimer) clearTimeout(debounceTimer);

  debounceTimer = setTimeout(async () => {
    try {
      setStatus("Buscando produtos...", "muted");
      const resp = await buscarProdutos(term, LIMIT_DEFAULT);

      if (!resp?.sucesso) {
        renderSugestoes([]);
        setStatus(resp?.mensagem || "Falha na busca.", "danger");
        return;
      }

      const itens = resp?.dados?.itens || [];
      renderSugestoes(itens);
      setStatus(`${itens.length} resultado(s)`, "muted");

      logJsInfo({
        origem: "modal_adicionar_produto.js",
        mensagem: "Busca de produtos",
        termo: term,
        resultados: itens.length,
      });
    } catch (err) {
      renderSugestoes([]);
      setStatus("Erro ao buscar produtos.", "danger");
      logJsError({
        origem: "modal_adicionar_produto.js",
        mensagem: "Erro ao buscar produtos",
        detalhe: err?.message,
        stack: err?.stack,
      });
    }
  }, DEBOUNCE_MS);
}

async function criarProdutoNoModal() {
  const nome = $("apProdutoBusca")?.value?.trim() || "";
  if (!nome) {
    setStatus("Digite um nome para criar o produto.", "danger");
    return;
  }

  try {
    setStatus("Criando produto...", "muted");

    const resp = await apiRequest("criar_produto", { nome, quantidade: 0 }, "POST");
    if (!resp?.sucesso) {
      setStatus(resp?.mensagem || "Falha ao criar produto.", "danger");
      return;
    }

    const novoId = Number(resp?.dados?.id ?? 0);
    if (!novoId) {
      setStatus("Produto criado, mas não retornou ID.", "danger");
      return;
    }

    // seleciona e carrega resumo
    setStatus("Produto criado! Carregando...", "success");
    selecionarProduto({ id: novoId, nome, quantidade: 0, preco_custo: 0 });

    // atualiza lista externa
    window.dispatchEvent(new CustomEvent("estoque:atualizar"));

  } catch (err) {
    setStatus("Erro ao criar produto.", "danger");
    logJsError({
      origem: "modal_adicionar_produto.js",
      mensagem: "Erro ao criar produto",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function getTipoSelecionado() {
  const entrada = $("apTipoEntrada")?.checked;
  const saida = $("apTipoSaida")?.checked;
  if (entrada) return "entrada";
  if (saida) return "saida";
  return "entrada";
}

async function salvarMovimentacao() {
  if (!selectedProduto?.id) {
    setStatus("Selecione um produto primeiro.", "danger");
    return;
  }

  const tipo = getTipoSelecionado();
  const qtd = Number($("apQuantidade")?.value ?? 0);
  const precoCusto = $("apPrecoCusto")?.value !== "" ? Number($("apPrecoCusto")?.value) : null;
  const obs = $("apObs")?.value?.trim() || null;

  if (!Number.isFinite(qtd) || qtd <= 0) {
    setStatus("Quantidade inválida.", "danger");
    return;
  }

  // regra simples no front: se for saída e estoque atual conhecido, evita erro óbvio
  const estoqueAtual = Number($("apEstoqueAtual")?.textContent ?? 0);
  if (tipo !== "entrada" && Number.isFinite(estoqueAtual) && estoqueAtual >= 0 && qtd > estoqueAtual) {
    setStatus("Quantidade maior que o estoque atual.", "danger");
    return;
  }

  try {
    setStatus("Salvando movimentação...", "muted");

    // Sua tabela tem valor_unitario, então vamos usar:
    // - se você preencher preço custo, mandamos também como valor_unitario
    // - e mandamos preco_custo para atualizar o produto (se existir)
    const payload = {
      produto_id: selectedProduto.id,
      tipo,
      quantidade: qtd,
      preco_custo: precoCusto,
      valor_unitario: precoCusto, // ✅ sua tabela TEM valor_unitario
      observacao: obs,            // ✅ backend ignora se não existir a coluna
    };

    const resp = await apiRequest("registrar_movimentacao", payload, "POST");
    if (!resp?.sucesso) {
      setStatus(resp?.mensagem || "Falha ao registrar movimentação.", "danger");
      return;
    }

    setStatus("Movimentação registrada com sucesso!", "success");

    // recarrega resumo e lista
    const resumo = await carregarResumoProduto(selectedProduto.id);
    if (resumo?.sucesso) {
      renderResumoNoModal(resumo?.dados);
    }

    window.dispatchEvent(new CustomEvent("estoque:atualizar"));

    // zera quantidade/obs (mantém produto)
    if ($("apQuantidade")) $("apQuantidade").value = "1";
    if ($("apObs")) $("apObs").value = "";

  } catch (err) {
    setStatus("Erro ao salvar movimentação.", "danger");
    logJsError({
      origem: "modal_adicionar_produto.js",
      mensagem: "Erro ao salvar movimentação",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function ajustarQuantidade(delta) {
  const input = $("apQuantidade");
  if (!input) return;
  const v = Number(input.value ?? 1);
  const n = Math.max(1, (Number.isFinite(v) ? v : 1) + delta);
  input.value = String(n);
}

function abrirModal({ preselectProduto = null, preselectTipo = null } = {}) {
  const elModal = $("modalAdicionarProduto");
  if (!elModal || !window.bootstrap?.Modal) return;

  modalInstance = modalInstance || new window.bootstrap.Modal(elModal, { backdrop: "static" });

  // reset leve
  setStatus("");
  $("apDataAgora") && ($("apDataAgora").textContent = nowBR());
  hideSugestoes();

  if (preselectTipo === "saida") {
    $("apTipoSaida") && ($("apTipoSaida").checked = true);
  } else if (preselectTipo === "entrada") {
    $("apTipoEntrada") && ($("apTipoEntrada").checked = true);
  }

  if (preselectProduto) {
    selecionarProduto(preselectProduto);
  } else {
    limparSelecaoProduto();
    $("apPrecoCusto") && ($("apPrecoCusto").value = "");
    $("apQuantidade") && ($("apQuantidade").value = "1");
    $("apObs") && ($("apObs").value = "");
  }

  modalInstance.show();
}

// expõe função pro estoque.js
window.abrirModalAdicionarProduto = abrirModal;

function bindEventos() {
  // abrir modal no botão principal
  $("btnAbrirModalAdicionar")?.addEventListener("click", () => abrirModal());

  // autocomplete
  $("apProdutoBusca")?.addEventListener("input", onInputProdutoBusca);
  $("apProdutoBusca")?.addEventListener("focus", onInputProdutoBusca);

  // click sugestão
  $("apSugestoes")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-id]");
    if (!btn) return;

    selecionarProduto({
      id: Number(btn.dataset.id),
      nome: btn.dataset.nome || "",
      quantidade: Number(btn.dataset.qtd || 0),
      preco_custo: Number(btn.dataset.preco || 0),
    });
  });

  // fechar sugestões ao clicar fora
  document.addEventListener("click", (ev) => {
    const box = $("apSugestoes");
    const input = $("apProdutoBusca");
    if (!box || box.style.display === "none") return;

    const inside = ev.target.closest("#apSugestoes") || ev.target === input || ev.target.closest("#apProdutoBusca");
    if (!inside) hideSugestoes();
  });

  // limpar produto
  $("apLimparProduto")?.addEventListener("click", () => {
    hideSugestoes();
    limparSelecaoProduto({ limparCampo: true });
    $("apPrecoCusto") && ($("apPrecoCusto").value = "");
    setStatus("Produto limpo.", "muted");
  });

  // criar produto
  $("apCriarProduto")?.addEventListener("click", criarProdutoNoModal);

  // steppers qtd
  $("apQtdMenos")?.addEventListener("click", () => ajustarQuantidade(-1));
  $("apQtdMais")?.addEventListener("click", () => ajustarQuantidade(+1));

  // salvar
  $("apSalvar")?.addEventListener("click", salvarMovimentacao);
}

document.addEventListener("DOMContentLoaded", () => {
  bindEventos();
});