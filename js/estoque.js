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

function formatBRL(valor) {
  const n = Number(valor || 0);
  return n.toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function obterStatusEstoque(produto) {
  const quantidade = Number(produto?.quantidade ?? 0);
  const estoqueMinimo = Number(produto?.estoque_minimo ?? 0);

  if (quantidade <= 0) {
    return {
      texto: "Sem estoque",
      badge: "bg-danger",
      alertaClasse: "fw-bold text-danger",
      alertaTexto: "Produto sem estoque.",
      emBaixa: true
    };
  }

  if (quantidade <= estoqueMinimo) {
    return {
      texto: "Estoque baixo",
      badge: "bg-warning text-dark",
      alertaClasse: "fw-bold text-warning",
      alertaTexto: "Produto em estoque baixo.",
      emBaixa: true
    };
  }

  return {
    texto: "Normal",
    badge: "bg-success",
    alertaClasse: "fw-bold text-success",
    alertaTexto: "Estoque normal.",
    emBaixa: false
  };
}

let produtosCache = [];
let fornecedoresCache = [];
let produtoDetalheCache = new Map();

let modalEntradaInstance = null;
let modalSaidaInstance = null;

function getModalEntrada() {
  const el = $("modalEntrada");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalEntradaInstance) {
    modalEntradaInstance = new window.bootstrap.Modal(el);
  }
  return modalEntradaInstance;
}

function getModalSaida() {
  const el = $("modalSaida");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalSaidaInstance) {
    modalSaidaInstance = new window.bootstrap.Modal(el);
  }
  return modalSaidaInstance;
}

function getProdutoCache(produtoId) {
  return produtosCache.find((p) => Number(p?.id ?? 0) === Number(produtoId)) || null;
}

function setTexto(id, valor) {
  const el = $(id);
  if (el) {
    el.textContent = String(valor ?? "");
  }
}

function setStatus(id, texto = "", tipo = "muted") {
  const el = $(id);
  if (!el) return;

  el.className = "small";
  if (!texto) {
    el.textContent = "";
    return;
  }

  if (tipo === "erro") {
    el.classList.add("text-danger");
  } else if (tipo === "sucesso") {
    el.classList.add("text-success");
  } else if (tipo === "processando") {
    el.classList.add("text-primary");
  } else {
    el.classList.add("text-muted");
  }

  el.textContent = texto;
}

function setBtnLoading(id, loading, textoLoading = "Salvando...") {
  const btn = $(id);
  if (!btn) return;

  if (!btn.dataset.originalText) {
    btn.dataset.originalText = btn.textContent || "";
  }

  btn.disabled = !!loading;
  btn.textContent = loading ? textoLoading : btn.dataset.originalText;
}

function renderTabela(produtos) {
  const tbody = $("tabelaEstoque");
  if (!tbody) return;

  if (!Array.isArray(produtos) || produtos.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted">Nenhum produto encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = produtos.map((p) => {
    const id = Number(p?.id ?? 0);
    const nome = escapeHtml(p?.nome ?? "");
    const qtd = Number(p?.quantidade ?? 0);
    const estoqueMinimo = Number(p?.estoque_minimo ?? 0);
    const status = obterStatusEstoque(p);

    return `
      <tr class="${status.emBaixa ? "table-warning" : ""}">
        <td>${id}</td>
        <td>${nome}</td>
        <td>
          <span class="badge ${status.emBaixa ? "bg-danger" : "bg-primary"}">${qtd}</span>
        </td>
        <td>${estoqueMinimo}</td>
        <td>
          <span class="badge ${status.badge}">${status.texto}</span>
        </td>
        <td class="text-nowrap">
          <div class="d-flex gap-2 flex-wrap">
            <button
              class="btn btn-sm btn-outline-success"
              data-acao="entrada"
              data-id="${id}"
              data-nome="${escapeHtml(p?.nome ?? "")}"
            >
              Entrada
            </button>
            <button
              class="btn btn-sm btn-outline-danger"
              data-acao="saida"
              data-id="${id}"
              data-nome="${escapeHtml(p?.nome ?? "")}"
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
    return;
  }

  const filtrados = produtosCache.filter((p) =>
    String(p?.nome ?? "").toLowerCase().includes(termo)
  );

  renderTabela(filtrados);
}

async function carregarProdutos() {
  const tbody = $("tabelaEstoque");
  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted">Carregando...</td>
      </tr>
    `;
  }

  try {
    const resp = await apiRequest("listar_produtos", {}, "GET");

    if (!resp?.sucesso) {
      produtosCache = [];
      renderTabela([]);
      return;
    }

    produtosCache = Array.isArray(resp?.dados) ? resp.dados : [];
    aplicarFiltro();

    logJsInfo({
      origem: "estoque.js",
      mensagem: "Produtos carregados com sucesso",
      total: produtosCache.length,
    });
  } catch (err) {
    produtosCache = [];
    renderTabela([]);

    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao carregar produtos",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

async function carregarFornecedores() {
  try {
    const resp = await apiRequest("listar_fornecedores", {}, "GET");

    if (!resp?.sucesso) {
      fornecedoresCache = [];
      return;
    }

    fornecedoresCache = (Array.isArray(resp?.dados) ? resp.dados : [])
      .filter((f) => Number(f?.ativo ?? 1) === 1)
      .sort((a, b) => String(a?.nome ?? "").localeCompare(String(b?.nome ?? ""), "pt-BR"));
  } catch (err) {
    fornecedoresCache = [];

    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao carregar fornecedores",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

function limparSugestoes(prefixo) {
  const box = $(`${prefixo}Sugestoes`);
  if (!box) return;
  box.innerHTML = "";
  box.style.display = "none";
}

function renderSugestoes(prefixo, produtos) {
  const box = $(`${prefixo}Sugestoes`);
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

function renderUltimasMovimentacoes(prefixo, movs) {
  const box = $(`${prefixo}UltimasMovimentacoes`);
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

function preencherResumoBasico(prefixo, produto) {
  const qtdAtual = Number(produto?.quantidade ?? 0);
  const estoqueMinimo = Number(produto?.estoque_minimo ?? 0);
  const status = obterStatusEstoque({
    quantidade: qtdAtual,
    estoque_minimo: estoqueMinimo
  });

  setTexto(`${prefixo}EstoqueAtual`, qtdAtual);
  setTexto(`${prefixo}EstoqueMinimo`, estoqueMinimo);

  const alertaEl = $(`${prefixo}ResumoAlerta`);
  if (alertaEl) {
    alertaEl.textContent = status.alertaTexto;
    alertaEl.className = status.alertaClasse;
  }
}

function resetResumo(prefixo) {
  setTexto(`${prefixo}EstoqueAtual`, "-");
  setTexto(`${prefixo}EstoqueMinimo`, "-");

  const alertaEl = $(`${prefixo}ResumoAlerta`);
  if (alertaEl) {
    alertaEl.textContent = "";
    alertaEl.className = "fw-bold";
  }

  const historicoEl = $(`${prefixo}UltimasMovimentacoes`);
  if (historicoEl) {
    historicoEl.innerHTML = `<div class="text-muted">Selecione um produto para visualizar o histórico.</div>`;
  }

  const btnHistorico = $(`${prefixo}VerHistorico`);
  if (btnHistorico) {
    btnHistorico.disabled = true;
  }
}

function montarFornecedorOptions(vinculados = []) {
  const idsVinculados = new Set(
    (Array.isArray(vinculados) ? vinculados : [])
      .map((f) => Number(f?.fornecedor_id ?? f?.id ?? 0))
      .filter((n) => n > 0)
  );

  const vinculadosAtivos = fornecedoresCache.filter((f) => idsVinculados.has(Number(f?.id ?? 0)));
  const restantes = fornecedoresCache.filter((f) => !idsVinculados.has(Number(f?.id ?? 0)));

  const grupos = [];

  if (vinculadosAtivos.length > 0) {
    grupos.push(`
      <optgroup label="Vinculados ao produto">
        ${vinculadosAtivos.map((f) =>
          `<option value="${Number(f.id)}">${escapeHtml(f.nome)}</option>`
        ).join("")}
      </optgroup>
    `);
  }

  if (restantes.length > 0) {
    grupos.push(`
      <optgroup label="Demais fornecedores">
        ${restantes.map((f) =>
          `<option value="${Number(f.id)}">${escapeHtml(f.nome)}</option>`
        ).join("")}
      </optgroup>
    `);
  }

  return `
    <option value="">Selecione um fornecedor...</option>
    ${grupos.join("")}
  `;
}

function preencherSelectFornecedores(vinculados = []) {
  const select = $("entradaFornecedorId");
  if (!select) return;

  select.innerHTML = montarFornecedorOptions(vinculados);
}

async function carregarDetalhesProduto(produtoId) {
  const id = Number(produtoId || 0);
  if (id <= 0) return null;

  if (produtoDetalheCache.has(id)) {
    return produtoDetalheCache.get(id);
  }

  try {
    const [respProduto, respResumo] = await Promise.allSettled([
      apiRequest("obter_produto", { produto_id: id }, "GET"),
      apiRequest("produto_resumo", { produto_id: id }, "GET")
    ]);

    const detalhe = {
      produto: null,
      resumo: null,
      fornecedores: []
    };

    if (respProduto.status === "fulfilled" && respProduto.value?.sucesso) {
      detalhe.produto = respProduto.value?.dados || null;
      detalhe.fornecedores = Array.isArray(respProduto.value?.dados?.fornecedores)
        ? respProduto.value.dados.fornecedores
        : [];
    }

    if (respResumo.status === "fulfilled" && respResumo.value?.sucesso) {
      detalhe.resumo = respResumo.value?.dados || null;
    }

    produtoDetalheCache.set(id, detalhe);
    return detalhe;
  } catch (err) {
    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao carregar detalhes do produto",
      detalhe: err?.message,
      stack: err?.stack,
      produto_id: id
    });
    return null;
  }
}

async function carregarResumoProduto(prefixo, produtoId) {
  resetResumo(prefixo);

  const id = Number(produtoId || 0);
  if (id <= 0) return;

  const historicoEl = $(`${prefixo}UltimasMovimentacoes`);
  if (historicoEl) {
    historicoEl.innerHTML = `<div class="text-muted">Carregando informações do produto...</div>`;
  }

  const btnHistorico = $(`${prefixo}VerHistorico`);
  if (btnHistorico) {
    btnHistorico.disabled = false;
  }

  const produtoCache = getProdutoCache(id);
  if (produtoCache) {
    preencherResumoBasico(prefixo, produtoCache);
  }

  const detalhe = await carregarDetalhesProduto(id);

  const produto = detalhe?.resumo?.produto || detalhe?.produto || produtoCache || {};
  const movs = Array.isArray(detalhe?.resumo?.ultimas_movimentacoes)
    ? detalhe.resumo.ultimas_movimentacoes
    : [];

  preencherResumoBasico(prefixo, produto);
  renderUltimasMovimentacoes(prefixo, movs);

  if (prefixo === "entrada") {
    preencherSelectFornecedores(detalhe?.fornecedores || []);
  }

  if (prefixo === "saida") {
    const precoVenda = Number(detalhe?.produto?.preco_venda ?? produto?.preco_venda ?? 0);
    const saidaValor = $("saidaValorUnitario");
    if (saidaValor && !saidaValor.value && precoVenda > 0) {
      saidaValor.value = String(precoVenda);
    }
  }
}

function selecionarProduto(prefixo, id, nome) {
  if ($(`${prefixo}ProdutoId`)) $(`${prefixo}ProdutoId`).value = String(id || "");
  if ($(`${prefixo}ProdutoNome`)) $(`${prefixo}ProdutoNome`).value = nome || "";
  limparSugestoes(prefixo);
  carregarResumoProduto(prefixo, id);
}

function bindAutocomplete(prefixo) {
  const input = $(`${prefixo}ProdutoNome`);
  const box = $(`${prefixo}Sugestoes`);

  if (!input || !box) return;

  input.addEventListener("input", () => {
    const termo = input.value.trim().toLowerCase();

    if ($(`${prefixo}ProdutoId`)) $(`${prefixo}ProdutoId`).value = "";
    resetResumo(prefixo);

    if (prefixo === "entrada") {
      preencherSelectFornecedores([]);
    }

    if (prefixo === "saida" && $("saidaValorUnitario")) {
      $("saidaValorUnitario").value = "";
    }

    if (!termo) {
      limparSugestoes(prefixo);
      return;
    }

    const encontrados = produtosCache.filter((p) =>
      String(p?.nome ?? "").toLowerCase().includes(termo)
    );

    renderSugestoes(prefixo, encontrados.slice(0, 8));
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
      selecionarProduto(prefixo, encontrados[0].id, encontrados[0].nome);
    }
  });

  box.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-id][data-nome]");
    if (!btn) return;

    selecionarProduto(
      prefixo,
      Number(btn.dataset.id || 0),
      btn.dataset.nome || ""
    );
  });

  document.addEventListener("click", (ev) => {
    const clicouNoInput = ev.target.closest(`#${prefixo}ProdutoNome`);
    const clicouNaLista = ev.target.closest(`#${prefixo}Sugestoes`);

    if (!clicouNoInput && !clicouNaLista) {
      limparSugestoes(prefixo);
    }
  });
}

function ajustarQuantidade(idCampo, delta) {
  const input = $(idCampo);
  if (!input) return;

  const atual = Number(input.value ?? 1);
  const novo = Math.max(1, (Number.isFinite(atual) ? atual : 1) + delta);
  input.value = String(novo);
}

function limparModalEntrada() {
  if ($("entradaProdutoId")) $("entradaProdutoId").value = "";
  if ($("entradaProdutoNome")) $("entradaProdutoNome").value = "";
  if ($("entradaDataAgora")) $("entradaDataAgora").value = formatNowBR();
  if ($("entradaQuantidade")) $("entradaQuantidade").value = "1";
  if ($("entradaPrecoCusto")) $("entradaPrecoCusto").value = "";
  if ($("entradaObservacao")) $("entradaObservacao").value = "";
  if ($("entradaFornecedorId")) $("entradaFornecedorId").innerHTML = `<option value="">Selecione um fornecedor...</option>`;
  setStatus("entradaStatus", "");
  resetResumo("entrada");
  limparSugestoes("entrada");
}

function limparModalSaida() {
  if ($("saidaProdutoId")) $("saidaProdutoId").value = "";
  if ($("saidaProdutoNome")) $("saidaProdutoNome").value = "";
  if ($("saidaDataAgora")) $("saidaDataAgora").value = formatNowBR();
  if ($("saidaQuantidade")) $("saidaQuantidade").value = "1";
  if ($("saidaValorUnitario")) $("saidaValorUnitario").value = "";
  if ($("saidaObservacao")) $("saidaObservacao").value = "";
  setStatus("saidaStatus", "");
  resetResumo("saida");
  limparSugestoes("saida");
}

async function abrirModalEntrada({ id = "", nome = "" } = {}) {
  limparModalEntrada();
  const modal = getModalEntrada();
  if (!modal) return;

  if (id) {
    if ($("entradaProdutoId")) $("entradaProdutoId").value = String(id);
    if ($("entradaProdutoNome")) $("entradaProdutoNome").value = nome || "";
    await carregarResumoProduto("entrada", Number(id));
  } else {
    preencherSelectFornecedores([]);
  }

  modal.show();
}

async function abrirModalSaida({ id = "", nome = "" } = {}) {
  limparModalSaida();
  const modal = getModalSaida();
  if (!modal) return;

  if (id) {
    if ($("saidaProdutoId")) $("saidaProdutoId").value = String(id);
    if ($("saidaProdutoNome")) $("saidaProdutoNome").value = nome || "";
    await carregarResumoProduto("saida", Number(id));
  }

  modal.show();
}

function bindTabelaAcoes() {
  $("tabelaEstoque")?.addEventListener("click", async (ev) => {
    const btn = ev.target.closest("button[data-acao][data-id]");
    if (!btn) return;

    const payload = {
      id: Number(btn.dataset.id || 0),
      nome: btn.dataset.nome || ""
    };

    if (btn.dataset.acao === "entrada") {
      await abrirModalEntrada(payload);
      return;
    }

    await abrirModalSaida(payload);
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
  $("btnAtualizar")?.addEventListener("click", async () => {
    produtoDetalheCache.clear();
    await carregarFornecedores();
    await carregarProdutos();
  });

  $("btnAbrirModalEntrada")?.addEventListener("click", async () => {
    await abrirModalEntrada();
  });

  $("btnAbrirModalSaida")?.addEventListener("click", async () => {
    await abrirModalSaida();
  });

  $("entradaVerHistorico")?.addEventListener("click", () => {
    window.location.href = "/estoque/pages/relatorios.html";
  });

  $("saidaVerHistorico")?.addEventListener("click", () => {
    window.location.href = "/estoque/pages/relatorios.html";
  });
}

async function salvarEntrada() {
  const produtoId = Number($("entradaProdutoId")?.value ?? 0);
  const produtoNome = ($("entradaProdutoNome")?.value ?? "").trim();
  const fornecedorId = Number($("entradaFornecedorId")?.value ?? 0);
  const quantidade = Number($("entradaQuantidade")?.value ?? 0);
  const precoCusto = Number($("entradaPrecoCusto")?.value ?? 0);
  const observacao = ($("entradaObservacao")?.value ?? "").trim();

  setStatus("entradaStatus", "");

  if (!produtoId || !produtoNome) {
    setStatus("entradaStatus", "Selecione um produto válido.", "erro");
    return;
  }

  if (!fornecedorId) {
    setStatus("entradaStatus", "Selecione um fornecedor.", "erro");
    return;
  }

  if (!Number.isFinite(quantidade) || quantidade <= 0) {
    setStatus("entradaStatus", "Informe uma quantidade válida.", "erro");
    return;
  }

  if (!Number.isFinite(precoCusto) || precoCusto <= 0) {
    setStatus("entradaStatus", "Informe um preço de custo válido.", "erro");
    return;
  }

  const payload = {
    produto_id: produtoId,
    fornecedor_id: fornecedorId,
    tipo: "entrada",
    quantidade,
    preco_custo: precoCusto,
    observacao
  };

  setStatus("entradaStatus", "Salvando entrada...", "processando");
  setBtnLoading("entradaSalvar", true, "Salvando...");

  try {
    const resp = await apiRequest("registrar_movimentacao", payload, "POST");

    if (!resp?.sucesso) {
      setStatus("entradaStatus", resp?.mensagem || "Erro ao salvar entrada.", "erro");
      return;
    }

    setStatus("entradaStatus", "Entrada registrada com sucesso.", "sucesso");

    produtoDetalheCache.delete(produtoId);
    await carregarProdutos();
    await carregarResumoProduto("entrada", produtoId);

    setTimeout(() => {
      getModalEntrada()?.hide();
    }, 600);

    logJsInfo({
      origem: "estoque.js",
      mensagem: "Entrada registrada",
      produto_id: produtoId,
      fornecedor_id: fornecedorId,
      quantidade,
      preco_custo: precoCusto
    });
  } catch (err) {
    setStatus("entradaStatus", "Erro inesperado ao salvar entrada.", "erro");

    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao salvar entrada",
      detalhe: err?.message,
      stack: err?.stack,
    });
  } finally {
    setBtnLoading("entradaSalvar", false);
  }
}

async function salvarSaida() {
  const produtoId = Number($("saidaProdutoId")?.value ?? 0);
  const produtoNome = ($("saidaProdutoNome")?.value ?? "").trim();
  const quantidade = Number($("saidaQuantidade")?.value ?? 0);
  const valorUnitario = Number($("saidaValorUnitario")?.value ?? 0);
  const observacao = ($("saidaObservacao")?.value ?? "").trim();

  setStatus("saidaStatus", "");

  if (!produtoId || !produtoNome) {
    setStatus("saidaStatus", "Selecione um produto válido.", "erro");
    return;
  }

  if (!Number.isFinite(quantidade) || quantidade <= 0) {
    setStatus("saidaStatus", "Informe uma quantidade válida.", "erro");
    return;
  }

  if (!Number.isFinite(valorUnitario) || valorUnitario <= 0) {
    setStatus("saidaStatus", "Informe um valor de venda unitário válido.", "erro");
    return;
  }

  const payload = {
    produto_id: produtoId,
    tipo: "saida",
    quantidade,
    valor_unitario: valorUnitario,
    observacao
  };

  setStatus("saidaStatus", "Salvando saída...", "processando");
  setBtnLoading("saidaSalvar", true, "Salvando...");

  try {
    const resp = await apiRequest("registrar_movimentacao", payload, "POST");

    if (!resp?.sucesso) {
      setStatus("saidaStatus", resp?.mensagem || "Erro ao salvar saída.", "erro");
      return;
    }

    const lucro = resp?.dados?.lucro;
    if (typeof lucro === "number") {
      setStatus("saidaStatus", `Saída registrada com sucesso. Lucro real: R$ ${formatBRL(lucro)}.`, "sucesso");
    } else {
      setStatus("saidaStatus", "Saída registrada com sucesso.", "sucesso");
    }

    produtoDetalheCache.delete(produtoId);
    await carregarProdutos();
    await carregarResumoProduto("saida", produtoId);

    setTimeout(() => {
      getModalSaida()?.hide();
    }, 700);

    logJsInfo({
      origem: "estoque.js",
      mensagem: "Saída registrada",
      produto_id: produtoId,
      quantidade,
      valor_unitario: valorUnitario,
      lucro: lucro ?? null
    });
  } catch (err) {
    setStatus("saidaStatus", "Erro inesperado ao salvar saída.", "erro");

    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao salvar saída",
      detalhe: err?.message,
      stack: err?.stack,
    });
  } finally {
    setBtnLoading("saidaSalvar", false);
  }
}

function bindModais() {
  bindAutocomplete("entrada");
  bindAutocomplete("saida");

  $("entradaQtdMenos")?.addEventListener("click", () => ajustarQuantidade("entradaQuantidade", -1));
  $("entradaQtdMais")?.addEventListener("click", () => ajustarQuantidade("entradaQuantidade", 1));
  $("saidaQtdMenos")?.addEventListener("click", () => ajustarQuantidade("saidaQuantidade", -1));
  $("saidaQtdMais")?.addEventListener("click", () => ajustarQuantidade("saidaQuantidade", 1));

  $("entradaSalvar")?.addEventListener("click", salvarEntrada);
  $("saidaSalvar")?.addEventListener("click", salvarSaida);

  $("modalEntrada")?.addEventListener("hidden.bs.modal", () => {
    limparModalEntrada();
  });

  $("modalSaida")?.addEventListener("hidden.bs.modal", () => {
    limparModalSaida();
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  bindTabelaAcoes();
  bindBusca();
  bindAcoesTopo();
  bindModais();

  await carregarFornecedores();
  await carregarProdutos();
}); 