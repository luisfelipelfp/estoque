import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let modal = null;
let produtoSelecionado = null; // { id, nome, ... }
let debounceTimer = null;

function $(id) { return document.getElementById(id); }

function agoraBR() {
  const d = new Date();
  const pad2 = (n) => String(n).padStart(2, "0");
  return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function setStatus(msg, isError = false) {
  const el = $("apStatus");
  if (!el) return;
  el.textContent = msg || "";
  el.className = "me-auto small " + (isError ? "text-danger" : "text-muted");
}

function showSugestoes(html) {
  const box = $("apSugestoes");
  if (!box) return;
  box.innerHTML = html || "";
  box.style.display = html ? "block" : "none";
}

function limparProduto() {
  produtoSelecionado = null;
  $("apProdutoBusca").value = "";
  $("apProdutoInfo").textContent = "";
  $("apEstoqueAtual").textContent = "-";
  $("apAlertaEstoque").textContent = "";
  $("apUltimasMov").textContent = "Selecione um produto…";
  $("apVerHistorico").disabled = true;
  showSugestoes("");
  setStatus("");
}

async function buscarSugestoes(q) {
  // ✅ aqui você aponta para a ação que você tiver no backend
  // Sugestão de ação: "buscar_produtos" retornando [{id, nome, quantidade, categoria?...}]
  // Ajuste se o seu backend usar outro nome.
  return apiRequest("buscar_produtos", { q }, "GET");
}

function renderSugestoes(lista = []) {
  if (!Array.isArray(lista) || lista.length === 0) {
    showSugestoes(`<div class="px-2 py-2 text-muted">Nenhum resultado.</div>`);
    return;
  }

  const html = lista.map(p => `
    <div class="ap-item" data-id="${p.id}" data-nome="${(p.nome || "").replaceAll('"', "&quot;")}">
      <div>📦</div>
      <div class="flex-grow-1">
        <div class="ap-titulo">${p.nome ?? "-"}</div>
        <div class="ap-sub">${Number(p.quantidade ?? 0)} unidades</div>
      </div>
    </div>
  `).join("");

  showSugestoes(html);
}

async function carregarResumoProduto(id) {
  // ✅ sugestão de ação: "produto_resumo" que retorna
  // { id, nome, quantidade, estoque_minimo, ultimas_mov:[...]}
  // Ajuste conforme seu backend.
  return apiRequest("produto_resumo", { id }, "GET");
}

function renderResumoProduto(resumo) {
  $("apProdutoInfo").textContent = resumo?.nome ? `Selecionado: ${resumo.nome}` : "";
  $("apEstoqueAtual").textContent = String(resumo?.quantidade ?? "-");

  const min = Number(resumo?.estoque_minimo ?? 0);
  const qtd = Number(resumo?.quantidade ?? 0);
  if (min > 0 && qtd < min) {
    $("apAlertaEstoque").textContent = `⚠ Estoque baixo! (mín.: ${min})`;
  } else {
    $("apAlertaEstoque").textContent = "";
  }

  const ult = resumo?.ultimas_mov || [];
  if (!Array.isArray(ult) || ult.length === 0) {
    $("apUltimasMov").innerHTML = `<span class="text-muted">Sem movimentações recentes.</span>`;
  } else {
    $("apUltimasMov").innerHTML = ult.slice(0, 5).map(m => {
      const tipo = m.tipo === "entrada" ? "Entrada" : (m.tipo === "saida" ? "Saída" : "Mov.");
      const sinal = m.tipo === "saida" ? "−" : "+";
      return `<div>${tipo} <strong>${sinal}${m.quantidade}</strong> em ${m.data}</div>`;
    }).join("");
  }

  $("apVerHistorico").disabled = false;
}

function getTipoSelecionado() {
  const el = document.querySelector('input[name="apTipo"]:checked');
  return el ? el.value : "entrada";
}

async function salvarMovimentacao() {
  if (!produtoSelecionado?.id) {
    setStatus("Selecione um produto.", true);
    return;
  }

  const tipo = getTipoSelecionado();
  const quantidade = Math.max(1, Number($("apQuantidade").value || 1));
  const precoCusto = $("apPrecoCusto").value === "" ? null : Number($("apPrecoCusto").value);
  const obs = ($("apObs").value || "").trim();

  setStatus("Salvando…");

  try {
    // ✅ sugestão de ação: "registrar_movimentacao"
    // { produto_id, tipo, quantidade, preco_custo?, obs? }
    const resp = await apiRequest("registrar_movimentacao", {
      produto_id: produtoSelecionado.id,
      tipo,
      quantidade,
      preco_custo: precoCusto,
      obs
    }, "POST");

    if (!resp?.sucesso) {
      setStatus(resp?.mensagem || "Falha ao salvar.", true);
      return;
    }

    setStatus("Movimentação salva com sucesso ✅");

    logJsInfo({
      origem: "modal_adicionar_produto.js",
      mensagem: "Movimentação salva",
      produto_id: produtoSelecionado.id,
      tipo,
      quantidade
    });

    // ✅ aqui você pode:
    // 1) recarregar a lista principal do estoque
    // 2) fechar o modal
    // 3) limpar campos
    // Eu deixei fechando + disparando um evento para a tela atualizar.
    document.dispatchEvent(new CustomEvent("estoque:atualizar"));
    modal?.hide();

  } catch (err) {
    setStatus("Erro inesperado ao salvar.", true);
    logJsError({
      origem: "modal_adicionar_produto.js",
      mensagem: "Erro ao salvar movimentação",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

function bindModal() {
  const elModal = $("modalAdicionarProduto");
  if (!elModal) return;

  modal = new bootstrap.Modal(elModal);

  $("apDataAgora").textContent = agoraBR();

  // abrir
  $("btnAbrirModalAdicionar")?.addEventListener("click", () => {
    $("apDataAgora").textContent = agoraBR();
    setStatus("");
    modal.show();
    setTimeout(() => $("apProdutoBusca")?.focus(), 150);
  });

  // limpar produto
  $("apLimparProduto")?.addEventListener("click", limparProduto);

  // stepper
  $("apQtdMenos")?.addEventListener("click", () => {
    const v = Math.max(1, Number($("apQuantidade").value || 1) - 1);
    $("apQuantidade").value = String(v);
  });
  $("apQtdMais")?.addEventListener("click", () => {
    const v = Math.max(1, Number($("apQuantidade").value || 1) + 1);
    $("apQuantidade").value = String(v);
  });

  // salvar
  $("apSalvar")?.addEventListener("click", salvarMovimentacao);

  // autocomplete
  $("apProdutoBusca")?.addEventListener("input", () => {
    const q = ($("apProdutoBusca").value || "").trim();
    produtoSelecionado = null;
    $("apProdutoInfo").textContent = "";
    $("apVerHistorico").disabled = true;

    if (debounceTimer) clearTimeout(debounceTimer);

    if (q.length < 2) {
      showSugestoes("");
      return;
    }

    debounceTimer = setTimeout(async () => {
      try {
        const resp = await buscarSugestoes(q);
        if (!resp?.sucesso) {
          renderSugestoes([]);
          return;
        }
        renderSugestoes(resp?.dados || []);
      } catch {
        renderSugestoes([]);
      }
    }, 250);
  });

  // clique na sugestão
  $("apSugestoes")?.addEventListener("click", async (ev) => {
    const item = ev.target.closest(".ap-item");
    if (!item) return;

    const id = Number(item.dataset.id);
    const nome = item.dataset.nome || "";
    produtoSelecionado = { id, nome };

    $("apProdutoBusca").value = nome;
    showSugestoes("");
    setStatus("");

    try {
      const resp = await carregarResumoProduto(id);
      if (resp?.sucesso) {
        renderResumoProduto(resp?.dados);
      }
    } catch {
      // ok: só não mostra resumo
    }
  });

  // esconder sugestões ao clicar fora
  document.addEventListener("click", (e) => {
    const box = $("apSugestoes");
    const input = $("apProdutoBusca");
    if (!box || !input) return;
    if (box.contains(e.target) || input.contains(e.target)) return;
    box.style.display = "none";
  });

  // quando fechar, reset leve
  elModal.addEventListener("hidden.bs.modal", () => {
    $("apQuantidade").value = "1";
    $("apPrecoCusto").value = "";
    $("apObs").value = "";
    limparProduto();
  });

  // botão “Criar produto” (placeholder)
  $("apCriarProduto")?.addEventListener("click", () => {
    setStatus("Abrir tela/fluxo de criação de produto (a gente implementa já já).");
  });

  // histórico (placeholder)
  $("apVerHistorico")?.addEventListener("click", () => {
    setStatus("Abrir histórico do produto (podemos linkar pro relatório filtrado).");
  });
}

document.addEventListener("DOMContentLoaded", bindModal);