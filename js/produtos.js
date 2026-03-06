// js/produtos.js
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

function formatBRL(valor) {
  const n = Number(valor || 0);
  return `R$ ${n.toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })}`;
}

let produtosCache = [];
let modalInstance = null;

async function carregarNavbar() {
  const host = $("navbar");
  if (!host) return;

  try {
    const resp = await fetch("/estoque/components/navbar.html?v=20260306", {
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
            origem: "produtos.js",
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
      origem: "produtos.js",
      mensagem: "Erro ao carregar navbar",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function calcularLucro(precoCusto, precoVenda) {
  return Number(precoVenda || 0) - Number(precoCusto || 0);
}

function calcularMargem(precoCusto, precoVenda) {
  const venda = Number(precoVenda || 0);
  const lucro = calcularLucro(precoCusto, precoVenda);
  if (venda <= 0) return 0;
  return (lucro / venda) * 100;
}

function atualizarResumoModal() {
  const precoCusto = Number($("produtoPrecoCusto")?.value || 0);
  const precoVenda = Number($("produtoPrecoVenda")?.value || 0);

  const lucro = calcularLucro(precoCusto, precoVenda);
  const margem = calcularMargem(precoCusto, precoVenda);

  if ($("produtoLucroUnitario")) $("produtoLucroUnitario").textContent = formatBRL(lucro);
  if ($("produtoMargemEstimada")) {
    $("produtoMargemEstimada").textContent = `${margem.toLocaleString("pt-BR", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    })}%`;
  }
}

function renderCards(produtos) {
  const totalProdutos = Array.isArray(produtos) ? produtos.length : 0;

  let somaCusto = 0;
  let somaLucro = 0;

  for (const p of produtos || []) {
    const custo = Number(p?.preco_custo ?? 0);
    const venda = Number(p?.preco_venda ?? 0);
    somaCusto += custo;
    somaLucro += calcularLucro(custo, venda);
  }

  const custoMedio = totalProdutos ? somaCusto / totalProdutos : 0;
  const lucroMedio = totalProdutos ? somaLucro / totalProdutos : 0;

  if ($("cardTotalProdutos")) $("cardTotalProdutos").textContent = String(totalProdutos);
  if ($("cardCustoMedio")) $("cardCustoMedio").textContent = formatBRL(custoMedio);
  if ($("cardLucroMedio")) $("cardLucroMedio").textContent = formatBRL(lucroMedio);
}

function renderTabela(produtos) {
  const tbody = $("tabelaProdutos");
  if (!tbody) return;

  if (!Array.isArray(produtos) || produtos.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" class="text-center text-muted">Nenhum produto encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = produtos.map((p) => {
    const id = Number(p?.id ?? 0);
    const nome = escapeHtml(p?.nome ?? "");
    const qtd = Number(p?.quantidade ?? 0);
    const precoCusto = Number(p?.preco_custo ?? 0);
    const precoVenda = Number(p?.preco_venda ?? 0);
    const lucro = calcularLucro(precoCusto, precoVenda);

    return `
      <tr>
        <td>${id}</td>
        <td>${nome}</td>
        <td>${qtd}</td>
        <td>${formatBRL(precoCusto)}</td>
        <td>${formatBRL(precoVenda)}</td>
        <td>${formatBRL(lucro)}</td>
        <td>
          <button
            class="btn btn-sm btn-outline-primary"
            data-acao="abrir"
            data-id="${id}"
          >
            Abrir
          </button>
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
  const tbody = $("tabelaProdutos");
  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" class="text-center text-muted">Carregando...</td>
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
      origem: "produtos.js",
      mensagem: "Produtos carregados com sucesso",
      total: produtosCache.length,
    });
  } catch (err) {
    produtosCache = [];
    renderTabela([]);
    renderCards([]);

    logJsError({
      origem: "produtos.js",
      mensagem: "Erro ao carregar produtos",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function getModal() {
  const el = $("modalProduto");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalInstance) {
    modalInstance = new window.bootstrap.Modal(el);
  }
  return modalInstance;
}

function limparModal() {
  if ($("produtoId")) $("produtoId").value = "";
  if ($("produtoNome")) $("produtoNome").value = "";
  if ($("produtoQuantidade")) $("produtoQuantidade").value = "0";
  if ($("produtoPrecoCusto")) $("produtoPrecoCusto").value = "0";
  if ($("produtoPrecoVenda")) $("produtoPrecoVenda").value = "0";
  if ($("produtoStatus")) $("produtoStatus").textContent = "";
  if ($("tituloModalProduto")) $("tituloModalProduto").textContent = "Novo Produto";
  atualizarResumoModal();
}

function abrirModalNovoProduto() {
  limparModal();
  getModal()?.show();
}

function abrirModalProdutoExistente(produtoId) {
  const produto = produtosCache.find((p) => Number(p?.id ?? 0) === Number(produtoId));
  if (!produto) return;

  limparModal();

  if ($("produtoId")) $("produtoId").value = String(produto.id ?? "");
  if ($("produtoNome")) $("produtoNome").value = produto.nome ?? "";
  if ($("produtoQuantidade")) $("produtoQuantidade").value = String(Number(produto.quantidade ?? 0));
  if ($("produtoPrecoCusto")) $("produtoPrecoCusto").value = String(Number(produto.preco_custo ?? 0));
  if ($("produtoPrecoVenda")) $("produtoPrecoVenda").value = String(Number(produto.preco_venda ?? 0));
  if ($("tituloModalProduto")) $("tituloModalProduto").textContent = "Cadastro do Produto";

  atualizarResumoModal();
  getModal()?.show();
}

async function salvarProduto() {
  const produtoId = Number($("produtoId")?.value ?? 0);
  const nome = ($("produtoNome")?.value ?? "").trim();
  const quantidade = Number($("produtoQuantidade")?.value ?? 0);
  const precoCusto = Number($("produtoPrecoCusto")?.value ?? 0);
  const precoVenda = Number($("produtoPrecoVenda")?.value ?? 0);
  const status = $("produtoStatus");

  if (status) status.textContent = "";

  if (!nome) {
    if (status) status.textContent = "Informe o nome do produto.";
    return;
  }

  if (!Number.isFinite(quantidade) || quantidade < 0) {
    if (status) status.textContent = "Informe uma quantidade válida.";
    return;
  }

  if (!Number.isFinite(precoCusto) || precoCusto < 0) {
    if (status) status.textContent = "Informe um preço de custo válido.";
    return;
  }

  if (!Number.isFinite(precoVenda) || precoVenda < 0) {
    if (status) status.textContent = "Informe um preço de venda válido.";
    return;
  }

  if (produtoId > 0) {
    if (status) {
      status.textContent = "A edição visual já está pronta. Falta encaixar a ação de atualizar produto no backend.";
    }
    return;
  }

  if (status) status.textContent = "Salvando produto...";

  try {
    const resp = await apiRequest(
      "criar_produto",
      {
        nome,
        quantidade,
        preco_custo: precoCusto,
        preco_venda: precoVenda
      },
      "POST"
    );

    if (!resp?.sucesso) {
      if (status) status.textContent = resp?.mensagem || "Erro ao salvar produto.";
      return;
    }

    if (status) status.textContent = "Produto cadastrado com sucesso.";

    await carregarProdutos();

    setTimeout(() => {
      getModal()?.hide();
    }, 500);

    logJsInfo({
      origem: "produtos.js",
      mensagem: "Produto criado com sucesso",
      nome,
      quantidade,
      preco_custo: precoCusto,
      preco_venda: precoVenda
    });
  } catch (err) {
    if (status) status.textContent = "Erro inesperado ao salvar produto.";

    logJsError({
      origem: "produtos.js",
      mensagem: "Erro ao salvar produto",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

function bindTabelaAcoes() {
  $("tabelaProdutos")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-acao='abrir'][data-id]");
    if (!btn) return;

    abrirModalProdutoExistente(Number(btn.dataset.id || 0));
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
  $("btnAtualizarProdutos")?.addEventListener("click", carregarProdutos);
  $("btnNovoProduto")?.addEventListener("click", abrirModalNovoProduto);
}

function bindModal() {
  $("produtoPrecoCusto")?.addEventListener("input", atualizarResumoModal);
  $("produtoPrecoVenda")?.addEventListener("input", atualizarResumoModal);
  $("btnSalvarProduto")?.addEventListener("click", salvarProduto);
}

document.addEventListener("DOMContentLoaded", async () => {
  await carregarNavbar();
  bindTabelaAcoes();
  bindBusca();
  bindAcoesTopo();
  bindModal();
  carregarProdutos();
});