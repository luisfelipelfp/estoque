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

function formatNowBR() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

let produtosCache = [];
let modalInstance = null;

async function carregarNavbar() {
  const host = $("navbar");
  if (!host) return;

  try {
    const resp = await fetch("/estoque/components/navbar.html?v=20260305", {
      cache: "no-store",
      credentials: "same-origin"
    });

    if (!resp.ok) {
      host.innerHTML = "";
      return;
    }

    host.innerHTML = await resp.text();

    const btn = document.getElementById("btnLogout");
    if (btn && !btn.dataset.boundLogout) {
      btn.dataset.boundLogout = "1";
      btn.addEventListener("click", async () => {
        try {
          await apiRequest("logout", null, "POST");
        } catch (err) {
          logJsError({
            origem: "estoque.js",
            mensagem: "Erro ao executar logout pelo navbar carregado dinamicamente",
            detalhe: err?.message,
            stack: err?.stack,
          });
        } finally {
          localStorage.removeItem("usuario");
          window.location.replace("/estoque/pages/login.html");
        }
      });
    }
  } catch (err) {
    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao carregar navbar",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function renderCards(produtos) {
  const totalProdutos = Array.isArray(produtos) ? produtos.length : 0;
  const totalItens = Array.isArray(produtos)
    ? produtos.reduce((acc, p) => acc + Number(p?.quantidade ?? 0), 0)
    : 0;

  const estoqueBaixo = Array.isArray(produtos)
    ? produtos.filter((p) => Number(p?.quantidade ?? 0) <= 0).length
    : 0;

  if ($("cardProdutos")) $("cardProdutos").textContent = String(totalProdutos);
  if ($("cardItens")) $("cardItens").textContent = String(totalItens);
  if ($("cardBaixo")) $("cardBaixo").textContent = String(estoqueBaixo);
}

function renderTabela(produtos) {
  const tbody = $("tabelaEstoque");
  if (!tbody) return;

  if (!Array.isArray(produtos) || produtos.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="4" class="text-center text-muted">Nenhum produto encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = produtos.map((p) => {
    const id = Number(p?.id ?? 0);
    const nome = escapeHtml(p?.nome ?? "");
    const qtd = Number(p?.quantidade ?? 0);

    return `
      <tr>
        <td>${id}</td>
        <td>${nome}</td>
        <td>
          <span class="badge bg-${qtd > 0 ? "primary" : "secondary"}">${qtd}</span>
        </td>
        <td class="text-nowrap">
          <div class="d-flex gap-2 flex-wrap">
            <button
              class="btn btn-sm btn-outline-success"
              data-acao="entrada"
              data-id="${id}"
              data-nome="${escapeHtml(p?.nome ?? "")}"
              data-qtd="${qtd}"
            >
              Entrada
            </button>
            <button
              class="btn btn-sm btn-outline-danger"
              data-acao="saida"
              data-id="${id}"
              data-nome="${escapeHtml(p?.nome ?? "")}"
              data-qtd="${qtd}"
            >
              Saída
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join("");
}

function aplicarFiltro() {
  const termo = ($("buscaProduto")?.value ?? "").trim().toLowerCase();

  if (!termo) {
    renderTabela(produtosCache);
    renderCards(produtosCache);
    return;
  }

  const filtrados = produtosCache.filter((p) =>
    String(p?.nome ?? "").toLowerCase().includes(termo)
  );

  renderTabela(filtrados);
  renderCards(filtrados);
}

async function carregarProdutos() {
  const tbody = $("tabelaEstoque");
  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="4" class="text-center text-muted">Carregando...</td>
      </tr>
    `;
  }

  try {
    const resp = await apiRequest("listar_produtos", {}, "GET");

    if (!resp?.sucesso) {
      produtosCache = [];
      renderTabela([]);
      renderCards([]);
      return;
    }

    const dados = Array.isArray(resp?.dados) ? resp.dados : [];
    produtosCache = dados;

    aplicarFiltro();

    logJsInfo({
      origem: "estoque.js",
      mensagem: "Produtos carregados com sucesso",
      total: produtosCache.length,
    });
  } catch (err) {
    produtosCache = [];
    renderTabela([]);
    renderCards([]);

    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao carregar produtos",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function getModal() {
  const el = $("modalMovimentar");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalInstance) {
    modalInstance = new window.bootstrap.Modal(el);
  }
  return modalInstance;
}

function limparSugestoes() {
  const box = $("movSugestoes");
  if (!box) return;
  box.innerHTML = "";
  box.style.display = "none";
}

function renderSugestoesModal(produtos) {
  const box = $("movSugestoes");
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
      data-id="${Number(p?.id ?? 0)}"
      data-nome="${escapeHtml(p?.nome ?? "")}"
    >
      ${escapeHtml(p?.nome ?? "")}
    </button>
  `).join("");

  box.style.display = "block";
}

function selecionarProdutoModal(id, nome) {
  if ($("movProdutoId")) $("movProdutoId").value = String(id || "");
  if ($("movProdutoNome")) $("movProdutoNome").value = nome || "";
  limparSugestoes();
  carregarResumoProduto(id);
}

function renderUltimasMovimentacoes(movs) {
  const box = $("movUltimasMovimentacoes");
  if (!box) return;

  if (!Array.isArray(movs) || movs.length === 0) {
    box.innerHTML = `<div class="text-muted">Nenhuma movimentação recente para este produto.</div>`;
    return;
  }

  const ultimas = movs.slice(0, 4);

  box.innerHTML = ultimas.map((mov, index) => {
    const tipo = String(mov?.tipo ?? "").toLowerCase();
    const classe =
      tipo === "saida"
        ? "bg-danger"
        : tipo === "entrada"
        ? "bg-success"
        : "bg-secondary";

    const borderClass = index < ultimas.length - 1 ? "border-bottom" : "";

    return `
      <div class="d-flex align-items-start justify-content-between gap-3 py-2 ${borderClass}">
        <div>
          <span class="badge ${classe}">${escapeHtml(tipo || "-")}</span>
          <div class="fw-semibold mt-1">${Number(mov?.quantidade ?? 0)}</div>
          <div class="text-muted">${escapeHtml(mov?.usuario ?? "Sistema")}</div>
        </div>
        <div class="text-end text-muted">
          ${escapeHtml(mov?.data ?? "-")}
        </div>
      </div>
    `;
  }).join("");
}

async function carregarResumoProduto(produtoId) {
  const estoqueEl = $("movEstoqueAtual");
  const historicoEl = $("movUltimasMovimentacoes");
  const alertaEl = $("movResumoAlerta");
  const btnHistorico = $("movVerHistorico");

  if (estoqueEl) estoqueEl.textContent = "-";
  if (alertaEl) alertaEl.textContent = "";
  if (historicoEl) historicoEl.innerHTML = `<div class="text-muted">Carregando informações do produto...</div>`;
  if (btnHistorico) btnHistorico.disabled = !produtoId;

  if (!produtoId) {
    if (historicoEl) historicoEl.innerHTML = `<div class="text-muted">Selecione um produto para visualizar o histórico.</div>`;
    return;
  }

  try {
    const resp = await apiRequest("produto_resumo", { produto_id: produtoId }, "GET");

    if (!resp?.sucesso) {
      if (historicoEl) historicoEl.innerHTML = `<div class="text-danger">Não foi possível carregar o resumo do produto.</div>`;
      return;
    }

    const dados = resp?.dados || {};
    const produto = dados?.produto || {};
    const movs = Array.isArray(dados?.ultimas_movimentacoes) ? dados.ultimas_movimentacoes : [];
    const qtdAtual = Number(produto?.quantidade ?? 0);

    if (estoqueEl) estoqueEl.textContent = String(qtdAtual);
    if (alertaEl) alertaEl.textContent = qtdAtual <= 0 ? "Produto sem estoque." : "";

    renderUltimasMovimentacoes(movs);
  } catch (err) {
    if (historicoEl) historicoEl.innerHTML = `<div class="text-danger">Erro ao carregar o histórico do produto.</div>`;

    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao carregar resumo do produto",
      detalhe: err?.message,
      stack: err?.stack,
      produto_id: produtoId
    });
  }
}

function bindAutocompleteModal() {
  const input = $("movProdutoNome");
  const box = $("movSugestoes");

  if (!input || !box) return;

  input.addEventListener("input", () => {
    const termo = input.value.trim().toLowerCase();

    if ($("movProdutoId")) $("movProdutoId").value = "";
    if ($("movEstoqueAtual")) $("movEstoqueAtual").textContent = "-";
    if ($("movResumoAlerta")) $("movResumoAlerta").textContent = "";
    if ($("movUltimasMovimentacoes")) {
      $("movUltimasMovimentacoes").innerHTML = `<div class="text-muted">Selecione um produto para visualizar o histórico.</div>`;
    }
    if ($("movVerHistorico")) $("movVerHistorico").disabled = true;

    if (!termo) {
      limparSugestoes();
      return;
    }

    const encontrados = produtosCache.filter((p) =>
      String(p?.nome ?? "").toLowerCase().includes(termo)
    );

    renderSugestoesModal(encontrados.slice(0, 8));
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
      selecionarProdutoModal(encontrados[0].id, encontrados[0].nome);
    }
  });

  box.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-id][data-nome]");
    if (!btn) return;

    selecionarProdutoModal(
      Number(btn.dataset.id || 0),
      btn.dataset.nome || ""
    );
  });

  document.addEventListener("click", (ev) => {
    const clicouNoInput = ev.target.closest("#movProdutoNome");
    const clicouNaLista = ev.target.closest("#movSugestoes");

    if (!clicouNoInput && !clicouNaLista) {
      limparSugestoes();
    }
  });
}

function limparModal() {
  if ($("movProdutoId")) $("movProdutoId").value = "";
  if ($("movProdutoNome")) $("movProdutoNome").value = "";
  if ($("movDataAgora")) $("movDataAgora").value = formatNowBR();
  if ($("movQuantidade")) $("movQuantidade").value = "1";
  if ($("movStatus")) $("movStatus").textContent = "";
  if ($("movEstoqueAtual")) $("movEstoqueAtual").textContent = "-";
  if ($("movResumoAlerta")) $("movResumoAlerta").textContent = "";
  if ($("movUltimasMovimentacoes")) {
    $("movUltimasMovimentacoes").innerHTML = `<div class="text-muted">Selecione um produto para visualizar o histórico.</div>`;
  }
  if ($("movVerHistorico")) $("movVerHistorico").disabled = true;

  limparSugestoes();

  if ($("movTipoEntrada")) $("movTipoEntrada").checked = true;
}

function abrirModalMovimentar({ id = "", nome = "", tipo = "entrada" } = {}) {
  const modal = getModal();
  if (!modal) return;

  limparModal();

  if ($("movProdutoId")) $("movProdutoId").value = id ? String(id) : "";
  if ($("movProdutoNome")) $("movProdutoNome").value = nome || "";
  if ($("movDataAgora")) $("movDataAgora").value = formatNowBR();

  if (tipo === "saida" && $("movTipoSaida")) {
    $("movTipoSaida").checked = true;
  } else if ($("movTipoEntrada")) {
    $("movTipoEntrada").checked = true;
  }

  if (id) {
    carregarResumoProduto(Number(id));
  }

  modal.show();
}

function ajustarQuantidade(delta) {
  const input = $("movQuantidade");
  if (!input) return;

  const atual = Number(input.value ?? 1);
  const novo = Math.max(1, (Number.isFinite(atual) ? atual : 1) + delta);
  input.value = String(novo);
}

function bindTabelaAcoes() {
  $("tabelaEstoque")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-acao][data-id]");
    if (!btn) return;

    abrirModalMovimentar({
      id: Number(btn.dataset.id || 0),
      nome: btn.dataset.nome || "",
      tipo: btn.dataset.acao === "saida" ? "saida" : "entrada",
    });
  });
}

function bindBusca() {
  $("buscaProduto")?.addEventListener("input", aplicarFiltro);

  $("btnLimparBusca")?.addEventListener("click", () => {
    if ($("buscaProduto")) $("buscaProduto").value = "";
    aplicarFiltro();
  });
}

function bindAcoesTopo() {
  $("btnAtualizar")?.addEventListener("click", carregarProdutos);

  $("btnAbrirModalMov")?.addEventListener("click", () => {
    abrirModalMovimentar();
  });

  $("movVerHistorico")?.addEventListener("click", () => {
    window.location.href = "/estoque/relatorios.html";
  });
}

function getTipoSelecionado() {
  if ($("movTipoSaida")?.checked) return "saida";
  return "entrada";
}

async function salvarMovimentacao() {
  const produtoId = Number($("movProdutoId")?.value ?? 0);
  const produtoNome = ($("movProdutoNome")?.value ?? "").trim();
  const quantidade = Number($("movQuantidade")?.value ?? 0);
  const tipo = getTipoSelecionado();

  const status = $("movStatus");
  if (status) status.textContent = "";

  if (!produtoId || !produtoNome) {
    if (status) status.textContent = "Selecione um produto válido.";
    return;
  }

  if (!Number.isFinite(quantidade) || quantidade <= 0) {
    if (status) status.textContent = "Informe uma quantidade válida.";
    return;
  }

  const payload = {
    produto_id: produtoId,
    tipo,
    quantidade
  };

  if (status) status.textContent = "Salvando movimentação...";

  try {
    const resp = await apiRequest("registrar_movimentacao", payload, "POST");

    if (!resp?.sucesso) {
      if (status) status.textContent = resp?.mensagem || "Erro ao salvar movimentação.";
      return;
    }

    if (status) status.textContent = "Movimentação registrada com sucesso.";

    await carregarProdutos();
    await carregarResumoProduto(produtoId);

    const modal = getModal();
    setTimeout(() => {
      modal?.hide();
    }, 500);

    logJsInfo({
      origem: "estoque.js",
      mensagem: "Movimentação registrada",
      produto_id: produtoId,
      tipo,
      quantidade
    });
  } catch (err) {
    if (status) status.textContent = "Erro inesperado ao salvar movimentação.";

    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao salvar movimentação",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function bindModal() {
  $("movQtdMenos")?.addEventListener("click", () => ajustarQuantidade(-1));
  $("movQtdMais")?.addEventListener("click", () => ajustarQuantidade(1));
  $("movSalvar")?.addEventListener("click", salvarMovimentacao);
  bindAutocompleteModal();
}

document.addEventListener("DOMContentLoaded", async () => {
  await carregarNavbar();
  bindTabelaAcoes();
  bindBusca();
  bindAcoesTopo();
  bindModal();
  carregarProdutos();
});