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

function normalizarTexto(valor) {
  return String(valor ?? "").trim().replace(/\s+/g, " ");
}

function parseNumeroPositivo(valor) {
  const n = Number(valor);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

function obterStatusEstoque(produto) {
  const quantidade = Number(produto?.quantidade ?? 0);
  const estoqueMinimo = Number(produto?.estoque_minimo ?? 0);

  if (quantidade <= 0) {
    return {
      chave: "zerado",
      texto: "Sem estoque",
      badge: "bg-danger",
      linhaClasse: "table-danger",
      alertaClasse: "fw-bold text-danger",
      alertaTexto: "Produto sem estoque.",
      emBaixa: true
    };
  }

  if (quantidade <= estoqueMinimo) {
    return {
      chave: "baixo",
      texto: "Estoque baixo",
      badge: "bg-warning text-dark",
      linhaClasse: "table-warning",
      alertaClasse: "fw-bold text-warning",
      alertaTexto: "Produto em estoque baixo.",
      emBaixa: true
    };
  }

  return {
    chave: "normal",
    texto: "Normal",
    badge: "bg-success",
    linhaClasse: "",
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

function getProdutoAtivoCache(produtoId) {
  const produto = getProdutoCache(produtoId);
  if (!produto) return null;
  return Number(produto?.ativo ?? 1) === 1 ? produto : null;
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

function atualizarKPIs(lista) {
  const produtos = Array.isArray(lista) ? lista : [];

  const total = produtos.length;
  const baixo = produtos.filter((p) => obterStatusEstoque(p).chave === "baixo").length;
  const zerado = produtos.filter((p) => obterStatusEstoque(p).chave === "zerado").length;

  setTexto("kpiTotalProdutos", total);
  setTexto("kpiProdutosBaixo", baixo);
  setTexto("kpiProdutosSemEstoque", zerado);
}

function atualizarResumoFiltro(listaFiltrada) {
  const el = $("estoqueResumoFiltro");
  if (!el) return;

  const ativos = produtosCache.filter((p) => Number(p?.ativo ?? 1) === 1);
  const total = ativos.length;
  const exibidos = Array.isArray(listaFiltrada) ? listaFiltrada.length : 0;
  const filtroStatus = $("filtroStatusEstoque")?.value ?? "todos";
  const busca = normalizarTexto($("buscaProduto")?.value ?? "");

  let descricaoStatus = "todos os produtos";
  if (filtroStatus === "baixo") descricaoStatus = "somente produtos com estoque baixo";
  if (filtroStatus === "zerado") descricaoStatus = "somente produtos sem estoque";
  if (filtroStatus === "alerta") descricaoStatus = "somente produtos em alerta";
  if (filtroStatus === "normal") descricaoStatus = "somente produtos com estoque normal";

  if (busca) {
    el.textContent = `Exibindo ${exibidos} de ${total} produto(s), filtrando por "${busca}" em ${descricaoStatus}.`;
    return;
  }

  el.textContent = `Exibindo ${exibidos} de ${total} produto(s) em ${descricaoStatus}.`;
}

function atualizarStatusTopo(listaFiltrada) {
  const el = $("estoqueStatusTopo");
  if (!el) return;

  const exibidos = Array.isArray(listaFiltrada) ? listaFiltrada.length : 0;
  const alertas = (Array.isArray(listaFiltrada) ? listaFiltrada : []).filter((p) => obterStatusEstoque(p).emBaixa).length;

  if (exibidos === 0) {
    el.textContent = "Nenhum produto encontrado.";
    return;
  }

  if (alertas > 0) {
    el.textContent = `${exibidos} produto(s) exibido(s) • ${alertas} em alerta`;
    return;
  }

  el.textContent = `${exibidos} produto(s) exibido(s)`;
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
    const nomeEscapado = escapeHtml(p?.nome ?? "");
    const nomeOriginal = String(p?.nome ?? "");
    const qtd = Number(p?.quantidade ?? 0);
    const estoqueMinimo = Number(p?.estoque_minimo ?? 0);
    const ativo = Number(p?.ativo ?? 1) === 1;
    const status = obterStatusEstoque(p);

    return `
      <tr class="${status.linhaClasse}">
        <td>${id}</td>
        <td>
          ${nomeEscapado}
          ${!ativo ? `<div class="small text-muted">Produto inativo</div>` : ""}
        </td>
        <td>
          <span class="badge ${status.emBaixa ? "bg-danger" : "bg-primary"}">${qtd}</span>
        </td>
        <td>${estoqueMinimo}</td>
        <td>
          <span class="badge ${status.badge}">${status.texto}</span>
        </td>
        <td>
          <div class="d-flex gap-2 flex-wrap">
            <button
              class="btn btn-sm btn-outline-success"
              type="button"
              data-acao="entrada"
              data-id="${id}"
              data-nome="${escapeHtml(nomeOriginal)}"
              ${!ativo ? "disabled" : ""}
            >
              Entrada
            </button>
            <button
              class="btn btn-sm btn-outline-danger"
              type="button"
              data-acao="saida"
              data-id="${id}"
              data-nome="${escapeHtml(nomeOriginal)}"
              ${!ativo ? "disabled" : ""}
            >
              Saída
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join("");
}

function filtrarProdutos() {
  const termo = normalizarTexto($("buscaProduto")?.value ?? "").toLowerCase();
  const filtroStatus = $("filtroStatusEstoque")?.value ?? "todos";

  let filtrados = produtosCache.filter((p) => Number(p?.ativo ?? 1) === 1);

  if (termo) {
    filtrados = filtrados.filter((p) => {
      const nome = String(p?.nome ?? "").toLowerCase();
      const id = String(p?.id ?? "").toLowerCase();
      return nome.includes(termo) || id.includes(termo);
    });
  }

  if (filtroStatus === "baixo") {
    filtrados = filtrados.filter((p) => obterStatusEstoque(p).chave === "baixo");
  } else if (filtroStatus === "zerado") {
    filtrados = filtrados.filter((p) => obterStatusEstoque(p).chave === "zerado");
  } else if (filtroStatus === "alerta") {
    filtrados = filtrados.filter((p) => obterStatusEstoque(p).emBaixa);
  } else if (filtroStatus === "normal") {
    filtrados = filtrados.filter((p) => obterStatusEstoque(p).chave === "normal");
  }

  return filtrados;
}

function aplicarFiltro() {
  const ativos = produtosCache.filter((p) => Number(p?.ativo ?? 1) === 1);
  const filtrados = filtrarProdutos();

  renderTabela(filtrados);
  atualizarKPIs(ativos);
  atualizarResumoFiltro(filtrados);
  atualizarStatusTopo(filtrados);
}

async function carregarProdutos() {
  const tbody = $("tabelaEstoque");
  const topo = $("estoqueStatusTopo");

  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted">Carregando...</td>
      </tr>
    `;
  }

  if (topo) {
    topo.textContent = "Carregando...";
  }

  try {
    const resp = await apiRequest("listar_produtos", {}, "GET");

    if (!resp?.sucesso) {
      produtosCache = [];
      renderTabela([]);
      atualizarKPIs([]);
      atualizarResumoFiltro([]);
      atualizarStatusTopo([]);
      return;
    }

    produtosCache = Array.isArray(resp?.dados) ? resp.dados : [];
    aplicarFiltro();

    logJsInfo({
      origem: "estoque.js",
      mensagem: "Produtos carregados com sucesso",
      total: produtosCache.length
    });
  } catch (err) {
    produtosCache = [];
    renderTabela([]);
    atualizarKPIs([]);
    atualizarResumoFiltro([]);
    atualizarStatusTopo([]);

    logJsError({
      origem: "estoque.js",
      mensagem: "Erro ao carregar produtos",
      detalhe: err?.message,
      stack: err?.stack
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

function atualizarAlertaModal(prefixo, produto) {
  const box = $(`${prefixo}AlertaEstoqueBox`);
  const texto = $(`${prefixo}AlertaEstoqueTexto`);

  if (!box || !texto) return;

  const qtdAtual = Number(produto?.quantidade ?? 0);
  const estoqueMinimo = Number(produto?.estoque_minimo ?? 0);
  const status = obterStatusEstoque({
    quantidade: qtdAtual,
    estoque_minimo: estoqueMinimo
  });

  if (prefixo === "entrada") {
    if (status.chave === "normal") {
      box.classList.add("d-none");
      texto.textContent = "";
      return;
    }

    if (status.chave === "zerado") {
      texto.textContent = "Este produto está sem estoque. Registre a entrada para reabastecer o item.";
    } else {
      texto.textContent = "Este produto está abaixo do estoque mínimo. Uma nova entrada pode normalizar o saldo.";
    }

    box.classList.remove("d-none");
    return;
  }

  if (prefixo === "saida") {
    if (status.chave === "zerado") {
      texto.textContent = "Este produto já está sem estoque. Não será possível registrar saída enquanto o saldo não for reabastecido.";
      box.classList.remove("d-none");
      return;
    }

    if (status.chave === "baixo") {
      texto.textContent = "Este produto já está com estoque baixo. Registrar uma saída pode agravar a situação.";
      box.classList.remove("d-none");
      return;
    }

    const saldoAposSaida = qtdAtual - Number($("saidaQuantidade")?.value ?? 0);

    if (saldoAposSaida <= 0) {
      texto.textContent = "Atenção: esta saída deixará o produto sem estoque.";
      box.classList.remove("d-none");
      return;
    }

    if (saldoAposSaida <= estoqueMinimo) {
      texto.textContent = "Atenção: esta saída deixará o produto abaixo do estoque mínimo.";
      box.classList.remove("d-none");
      return;
    }

    box.classList.add("d-none");
    texto.textContent = "";
  }
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

  atualizarAlertaModal(prefixo, produto);
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

  const alertaBox = $(`${prefixo}AlertaEstoqueBox`);
  const alertaTexto = $(`${prefixo}AlertaEstoqueTexto`);
  if (alertaBox) alertaBox.classList.add("d-none");
  if (alertaTexto) alertaTexto.textContent = "";
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
    atualizarAlertaModal("saida", produto);
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
    const termo = normalizarTexto(input.value).toLowerCase();

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
      Number(p?.ativo ?? 1) === 1 &&
      String(p?.nome ?? "").toLowerCase().includes(termo)
    );

    renderSugestoes(prefixo, encontrados.slice(0, 8));
  });

  input.addEventListener("keydown", (ev) => {
    if (ev.key !== "Enter") return;

    const termo = normalizarTexto(input.value).toLowerCase();
    if (!termo) return;

    const encontrados = produtosCache.filter((p) =>
      Number(p?.ativo ?? 1) === 1 &&
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

  if (idCampo === "saidaQuantidade") {
    const produtoId = Number($("saidaProdutoId")?.value ?? 0);
    if (produtoId > 0) {
      const detalhe = produtoDetalheCache.get(produtoId);
      const produto = detalhe?.resumo?.produto || detalhe?.produto || getProdutoCache(produtoId);
      if (produto) {
        atualizarAlertaModal("saida", produto);
      }
    }
  }
}

function limparModalEntrada() {
  if ($("entradaProdutoId")) $("entradaProdutoId").value = "";
  if ($("entradaProdutoNome")) $("entradaProdutoNome").value = "";
  if ($("entradaDataAgora")) $("entradaDataAgora").value = formatNowBR();
  if ($("entradaQuantidade")) $("entradaQuantidade").value = "1";
  if ($("entradaPrecoCusto")) $("entradaPrecoCusto").value = "";
  if ($("entradaObservacao")) $("entradaObservacao").value = "";
  if ($("entradaFornecedorId")) {
    $("entradaFornecedorId").innerHTML = `<option value="">Selecione um fornecedor...</option>`;
  }
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
    const produto = getProdutoAtivoCache(id);
    if (!produto) {
      setStatus("entradaStatus", "Produto inválido ou inativo para entrada.", "erro");
      return;
    }

    if ($("entradaProdutoId")) $("entradaProdutoId").value = String(id);
    if ($("entradaProdutoNome")) $("entradaProdutoNome").value = nome || produto.nome || "";
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
    const produto = getProdutoAtivoCache(id);
    if (!produto) {
      setStatus("saidaStatus", "Produto inválido ou inativo para saída.", "erro");
      return;
    }

    if ($("saidaProdutoId")) $("saidaProdutoId").value = String(id);
    if ($("saidaProdutoNome")) $("saidaProdutoNome").value = nome || produto.nome || "";
    await carregarResumoProduto("saida", Number(id));
  }

  modal.show();
}

function bindBusca() {
  $("buscaProduto")?.addEventListener("input", aplicarFiltro);

  $("btnLimparBusca")?.addEventListener("click", () => {
    if ($("buscaProduto")) $("buscaProduto").value = "";
    aplicarFiltro();
  });

  $("filtroStatusEstoque")?.addEventListener("change", aplicarFiltro);

  $("btnSomenteAlertas")?.addEventListener("click", () => {
    const select = $("filtroStatusEstoque");
    if (select) {
      select.value = "alerta";
    }
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
  const produtoNome = normalizarTexto($("entradaProdutoNome")?.value ?? "");
  const fornecedorId = Number($("entradaFornecedorId")?.value ?? 0);
  const quantidade = Number($("entradaQuantidade")?.value ?? 0);
  const precoCusto = Number($("entradaPrecoCusto")?.value ?? 0);
  const observacao = normalizarTexto($("entradaObservacao")?.value ?? "");

  setStatus("entradaStatus", "");

  const produto = getProdutoAtivoCache(produtoId);

  if (!produtoId || !produtoNome || !produto) {
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
      stack: err?.stack
    });
  } finally {
    setBtnLoading("entradaSalvar", false);
  }
}

async function salvarSaida() {
  const produtoId = Number($("saidaProdutoId")?.value ?? 0);
  const produtoNome = normalizarTexto($("saidaProdutoNome")?.value ?? "");
  const quantidade = Number($("saidaQuantidade")?.value ?? 0);
  const valorUnitario = Number($("saidaValorUnitario")?.value ?? 0);
  const observacao = normalizarTexto($("saidaObservacao")?.value ?? "");

  setStatus("saidaStatus", "");

  const produto = getProdutoAtivoCache(produtoId);

  if (!produtoId || !produtoNome || !produto) {
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

  const estoqueAtual = Number(produto?.quantidade ?? 0);
  if (estoqueAtual <= 0) {
    setStatus("saidaStatus", "Este produto está sem estoque para saída.", "erro");
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
      stack: err?.stack
    });
  } finally {
    setBtnLoading("saidaSalvar", false);
  }
}

function bindTabelaAcoes() {
  $("tabelaEstoque")?.addEventListener("click", async (ev) => {
    const btn = ev.target.closest("button[data-acao][data-id]");
    if (!btn) return;

    const acao = btn.dataset.acao || "";
    const id = Number(btn.dataset.id || 0);
    const nome = btn.dataset.nome || "";

    if (acao === "entrada") {
      await abrirModalEntrada({ id, nome });
      return;
    }

    if (acao === "saida") {
      await abrirModalSaida({ id, nome });
    }
  });
}

function bindModais() {
  bindAutocomplete("entrada");
  bindAutocomplete("saida");

  $("entradaQtdMenos")?.addEventListener("click", () => ajustarQuantidade("entradaQuantidade", -1));
  $("entradaQtdMais")?.addEventListener("click", () => ajustarQuantidade("entradaQuantidade", 1));
  $("saidaQtdMenos")?.addEventListener("click", () => ajustarQuantidade("saidaQuantidade", -1));
  $("saidaQtdMais")?.addEventListener("click", () => ajustarQuantidade("saidaQuantidade", 1));

  $("saidaQuantidade")?.addEventListener("input", () => {
    const produtoId = Number($("saidaProdutoId")?.value ?? 0);
    if (produtoId <= 0) return;

    const detalhe = produtoDetalheCache.get(produtoId);
    const produto = detalhe?.resumo?.produto || detalhe?.produto || getProdutoCache(produtoId);
    if (produto) {
      atualizarAlertaModal("saida", produto);
    }
  });

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
  bindBusca();
  bindAcoesTopo();
  bindTabelaAcoes();
  bindModais();

  await carregarFornecedores();
  await carregarProdutos();
});