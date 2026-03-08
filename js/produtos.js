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

function calcularLucro(precoCusto, precoVenda) {
  return Number(precoVenda || 0) - Number(precoCusto || 0);
}

function formatMargem(precoCusto, precoVenda) {
  const venda = Number(precoVenda || 0);
  const lucro = calcularLucro(precoCusto, precoVenda);
  if (venda <= 0) return "0,00%";

  const margem = (lucro / venda) * 100;
  return `${margem.toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })}%`;
}

let produtosCache = [];
let fornecedoresCadastrados = [];
let modalInstance = null;
let fornecedoresTemp = [];

function renderTabela(produtos) {
  const tbody = $("tabelaProdutos");
  if (!tbody) return;

  if (!Array.isArray(produtos) || produtos.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="3" class="text-center text-muted">Nenhum produto encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = produtos.map((p) => {
    const id = Number(p?.id ?? 0);
    const nome = escapeHtml(p?.nome ?? "");

    return `
      <tr>
        <td>${id}</td>
        <td>${nome}</td>
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
    return;
  }

  const filtrados = produtosCache.filter((p) =>
    String(p?.nome ?? "").toLowerCase().includes(termo)
  );

  renderTabela(filtrados);
}

async function carregarProdutos() {
  const tbody = $("tabelaProdutos");
  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="3" class="text-center text-muted">Carregando...</td>
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
      origem: "produtos.js",
      mensagem: "Produtos carregados com sucesso",
      total: produtosCache.length
    });
  } catch (err) {
    produtosCache = [];
    renderTabela([]);

    logJsError({
      origem: "produtos.js",
      mensagem: "Erro ao carregar produtos",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

async function carregarFornecedoresCadastrados() {
  try {
    const resp = await apiRequest("listar_fornecedores", {}, "GET");

    if (!resp?.sucesso) {
      fornecedoresCadastrados = [];
      atualizarAvisoFornecedores();
      return;
    }

    fornecedoresCadastrados = (Array.isArray(resp?.dados) ? resp.dados : [])
      .sort((a, b) => String(a?.nome ?? "").localeCompare(String(b?.nome ?? ""), "pt-BR"));

    atualizarAvisoFornecedores();
  } catch (err) {
    fornecedoresCadastrados = [];
    atualizarAvisoFornecedores();

    logJsError({
      origem: "produtos.js",
      mensagem: "Erro ao carregar fornecedores cadastrados",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

function atualizarAvisoFornecedores() {
  const aviso = $("avisoFornecedoresCadastrados");
  if (!aviso) return;

  aviso.classList.toggle("d-none", fornecedoresCadastrados.length > 0);
}

function getModal() {
  const el = $("modalProduto");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalInstance) {
    modalInstance = new window.bootstrap.Modal(el);
  }
  return modalInstance;
}

function criarFornecedorVazio() {
  return {
    fornecedor_id: "",
    nome: "",
    codigo: "",
    preco_custo: "",
    preco_venda: "",
    observacao: "",
    principal: 0
  };
}

function garantirFornecedorPrincipal() {
  if (!Array.isArray(fornecedoresTemp) || fornecedoresTemp.length === 0) {
    return;
  }

  const indicesValidos = fornecedoresTemp
    .map((f, i) => ({
      index: i,
      fornecedor_id: Number(f?.fornecedor_id ?? 0),
      principal: Number(f?.principal ?? 0)
    }))
    .filter((f) => f.fornecedor_id > 0);

  if (indicesValidos.length === 0) {
    fornecedoresTemp = fornecedoresTemp.map((f) => ({ ...f, principal: 0 }));
    return;
  }

  const primeiroPrincipal = indicesValidos.find((f) => f.principal === 1);

  const indicePrincipal = primeiroPrincipal
    ? primeiroPrincipal.index
    : indicesValidos[0].index;

  fornecedoresTemp = fornecedoresTemp.map((f, i) => ({
    ...f,
    principal: i === indicePrincipal ? 1 : 0
  }));
}

function obterNomeFornecedorPorId(fornecedorId) {
  const item = fornecedoresCadastrados.find(
    (f) => Number(f?.id ?? 0) === Number(fornecedorId)
  );
  return item?.nome ?? "";
}

function atualizarResumoFornecedorNoDOM(index) {
  const custoInput = document.querySelector(`[data-campo="preco_custo"][data-index="${index}"]`);
  const vendaInput = document.querySelector(`[data-campo="preco_venda"][data-index="${index}"]`);
  const lucroInput = document.querySelector(`[data-lucro-index="${index}"]`);
  const margemEl = document.querySelector(`[data-margem-index="${index}"]`);

  const precoCusto = Number(custoInput?.value || 0);
  const precoVenda = Number(vendaInput?.value || 0);
  const lucro = calcularLucro(precoCusto, precoVenda);

  if (lucroInput) {
    lucroInput.value = formatBRL(lucro);
  }

  if (margemEl) {
    margemEl.textContent = `Margem estimada: ${formatMargem(precoCusto, precoVenda)}`;
  }
}

function atualizarTituloFornecedorNoDOM(index, valor) {
  const titulo = document.querySelector(`[data-titulo-fornecedor="${index}"]`);
  if (!titulo) return;

  titulo.textContent = String(valor || "").trim() || `Fornecedor ${index + 1}`;
}

function existeFornecedorDuplicado(noIndex, fornecedorId) {
  const alvo = Number(fornecedorId || 0);
  if (alvo <= 0) return false;

  return fornecedoresTemp.some((f, i) => {
    if (i === noIndex) return false;
    return Number(f?.fornecedor_id ?? 0) === alvo;
  });
}

function atualizarFornecedor(index, campo, valor) {
  if (!fornecedoresTemp[index]) return;

  if (campo === "fornecedor_id") {
    const fornecedorId = String(valor ?? "");
    const fornecedorIdNum = Number(fornecedorId || 0);

    if (fornecedorIdNum > 0 && existeFornecedorDuplicado(index, fornecedorIdNum)) {
      alert("O mesmo fornecedor não pode ser adicionado mais de uma vez para o mesmo produto.");
      renderListaFornecedores();
      return;
    }

    fornecedoresTemp[index].fornecedor_id = fornecedorId;
    fornecedoresTemp[index].nome = obterNomeFornecedorPorId(fornecedorId);
    atualizarTituloFornecedorNoDOM(index, fornecedoresTemp[index].nome);

    garantirFornecedorPrincipal();
    renderListaFornecedores();
    return;
  }

  fornecedoresTemp[index][campo] = valor;

  if (campo === "principal" && Number(valor) === 1) {
    fornecedoresTemp = fornecedoresTemp.map((f, i) => ({
      ...f,
      principal: i === index ? 1 : 0
    }));
    garantirFornecedorPrincipal();
    renderListaFornecedores();
    return;
  }

  if (campo === "preco_custo" || campo === "preco_venda") {
    atualizarResumoFornecedorNoDOM(index);
  }

  if (campo === "nome") {
    atualizarTituloFornecedorNoDOM(index, valor);
  }
}

function removerFornecedor(index) {
  fornecedoresTemp.splice(index, 1);
  garantirFornecedorPrincipal();
  renderListaFornecedores();
}

function montarOptionsFornecedor(fornecedor, indexAtual) {
  const selecionadoId = Number(fornecedor?.fornecedor_id ?? 0);

  let options = `<option value="">Selecione um fornecedor...</option>`;

  options += fornecedoresCadastrados.map((f) => {
    const id = Number(f?.id ?? 0);
    const nome = String(f?.nome ?? "");
    const ativo = Number(f?.ativo ?? 1) === 1;
    const selected = id === selecionadoId ? "selected" : "";

    const usadoEmOutraLinha = fornecedoresTemp.some((item, idx) => {
      if (idx === indexAtual) return false;
      return Number(item?.fornecedor_id ?? 0) === id;
    });

    const disabled = (!ativo || usadoEmOutraLinha) ? "disabled" : "";
    const labelExtra = !ativo
      ? " (Inativo)"
      : (usadoEmOutraLinha ? " (Já selecionado)" : "");

    return `
      <option value="${id}" ${selected} ${disabled}>
        ${escapeHtml(nome)}${labelExtra}
      </option>
    `;
  }).join("");

  return options;
}

function criarFornecedorCard(fornecedor, index) {
  const precoCusto = Number(fornecedor?.preco_custo || 0);
  const precoVenda = Number(fornecedor?.preco_venda || 0);
  const lucro = calcularLucro(precoCusto, precoVenda);
  const principal = Number(fornecedor?.principal || 0) === 1;
  const tituloFornecedor = escapeHtml((fornecedor?.nome ?? "").trim() || `Fornecedor ${index + 1}`);

  return `
    <div class="card border mb-3 ${principal ? "border-primary" : ""}">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <strong data-titulo-fornecedor="${index}">${tituloFornecedor}</strong>

          <div class="d-flex align-items-center gap-3">
            <div class="form-check m-0">
              <input
                class="form-check-input"
                type="radio"
                name="fornecedorPrincipal"
                ${principal ? "checked" : ""}
                data-marcar-principal="${index}"
                ${Number(fornecedor?.fornecedor_id ?? 0) <= 0 ? "disabled" : ""}
              >
              <label class="form-check-label">Principal</label>
            </div>

            <button
              type="button"
              class="btn btn-sm btn-outline-danger"
              data-remover-fornecedor="${index}"
            >
              Remover
            </button>
          </div>
        </div>

        ${principal ? `
          <div class="alert alert-primary py-2 mb-3">
            Este é o fornecedor principal usado como referência do produto.
          </div>
        ` : ""}

        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <label class="form-label">Fornecedor cadastrado</label>
            <select
              class="form-select"
              data-campo="fornecedor_id"
              data-index="${index}"
            >
              ${montarOptionsFornecedor(fornecedor, index)}
            </select>
          </div>

          <div class="col-12 col-lg-6">
            <label class="form-label">Código do produto no fornecedor</label>
            <input
              class="form-control"
              value="${escapeHtml(fornecedor?.codigo ?? "")}"
              data-campo="codigo"
              data-index="${index}"
              placeholder="Ex.: 83921"
            >
          </div>

          <div class="col-12 col-lg-4">
            <label class="form-label">Preço de custo</label>
            <div class="input-group">
              <span class="input-group-text">R$</span>
              <input
                type="number"
                step="0.01"
                min="0"
                class="form-control"
                value="${escapeHtml(fornecedor?.preco_custo ?? "")}"
                data-campo="preco_custo"
                data-index="${index}"
                placeholder="0,00"
              >
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <label class="form-label">Preço de venda</label>
            <div class="input-group">
              <span class="input-group-text">R$</span>
              <input
                type="number"
                step="0.01"
                min="0"
                class="form-control"
                value="${escapeHtml(fornecedor?.preco_venda ?? "")}"
                data-campo="preco_venda"
                data-index="${index}"
                placeholder="0,00"
              >
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <label class="form-label">Lucro estimado</label>
            <input
              class="form-control"
              value="${formatBRL(lucro)}"
              readonly
              data-lucro-index="${index}"
            >
            <div class="form-text" data-margem-index="${index}">
              Margem estimada: ${formatMargem(precoCusto, precoVenda)}
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Observação</label>
            <input
              class="form-control"
              value="${escapeHtml(fornecedor?.observacao ?? "")}"
              data-campo="observacao"
              data-index="${index}"
              placeholder="Ex.: acabamento fosco, cromado, lote especial..."
            >
          </div>
        </div>
      </div>
    </div>
  `;
}

function renderListaFornecedores() {
  const lista = $("listaFornecedores");
  if (!lista) return;

  atualizarAvisoFornecedores();

  if (!Array.isArray(fornecedoresTemp) || fornecedoresTemp.length === 0) {
    lista.innerHTML = `
      <div class="alert alert-light text-center text-muted mb-0">
        Nenhum fornecedor adicionado.
      </div>
    `;
    return;
  }

  garantirFornecedorPrincipal();

  lista.innerHTML = fornecedoresTemp
    .map((fornecedor, index) => criarFornecedorCard(fornecedor, index))
    .join("");
}

function adicionarFornecedor() {
  if (fornecedoresCadastrados.length === 0) {
    const status = $("produtoStatus");
    if (status) {
      status.textContent = "Não há fornecedores cadastrados para adicionar.";
    }
    return;
  }

  fornecedoresTemp.push(criarFornecedorVazio());
  garantirFornecedorPrincipal();
  renderListaFornecedores();
}

function limparModal() {
  if ($("produtoId")) $("produtoId").value = "";
  if ($("produtoNome")) $("produtoNome").value = "";
  if ($("produtoEstoqueMinimo")) $("produtoEstoqueMinimo").value = "0";
  if ($("produtoStatus")) $("produtoStatus").textContent = "";
  if ($("tituloModalProduto")) $("tituloModalProduto").textContent = "Novo Produto";

  fornecedoresTemp = [];
  renderListaFornecedores();
}

async function abrirModalNovoProduto() {
  await carregarFornecedoresCadastrados();
  limparModal();
  getModal()?.show();
}

async function abrirModalProdutoExistente(produtoId) {
  await carregarFornecedoresCadastrados();

  let produto = produtosCache.find((p) => Number(p?.id ?? 0) === Number(produtoId));

  try {
    const resp = await apiRequest("obter_produto", { produto_id: produtoId }, "GET");
    if (resp?.sucesso && resp?.dados) {
      produto = resp.dados;
    }
  } catch (err) {
    logJsError({
      origem: "produtos.js",
      mensagem: "Erro ao obter produto para edição",
      detalhe: err?.message,
      stack: err?.stack,
      produto_id: produtoId
    });
  }

  if (!produto) return;

  limparModal();

  if ($("produtoId")) $("produtoId").value = String(produto.id ?? "");
  if ($("produtoNome")) $("produtoNome").value = produto.nome ?? "";
  if ($("produtoEstoqueMinimo")) $("produtoEstoqueMinimo").value = String(Number(produto.estoque_minimo ?? 0));
  if ($("tituloModalProduto")) $("tituloModalProduto").textContent = "Cadastro do Produto";

  if (Array.isArray(produto.fornecedores) && produto.fornecedores.length > 0) {
    fornecedoresTemp = produto.fornecedores.map((f) => ({
      fornecedor_id: String(f?.fornecedor_id ?? ""),
      nome: f?.nome ?? "",
      codigo: f?.codigo ?? "",
      preco_custo: String(Number(f?.preco_custo ?? 0)),
      preco_venda: String(Number(f?.preco_venda ?? 0)),
      observacao: f?.observacao ?? "",
      principal: Number(f?.principal ?? 0) === 1 ? 1 : 0
    }));
  } else {
    fornecedoresTemp = [];
  }

  garantirFornecedorPrincipal();
  renderListaFornecedores();
  getModal()?.show();
}

function fornecedoresValidosParaEnvio() {
  return (fornecedoresTemp || [])
    .map((f) => {
      const fornecedorId = Number(f?.fornecedor_id ?? 0);
      const nome = fornecedorId > 0
        ? obterNomeFornecedorPorId(fornecedorId)
        : "";

      return {
        fornecedor_id: fornecedorId,
        nome: String(nome ?? "").trim(),
        codigo: String(f?.codigo ?? "").trim(),
        preco_custo: Number(f?.preco_custo || 0),
        preco_venda: Number(f?.preco_venda || 0),
        observacao: String(f?.observacao ?? "").trim(),
        principal: Number(f?.principal ?? 0) === 1 ? 1 : 0
      };
    })
    .filter((f) => f.fornecedor_id > 0 && f.nome !== "");
}

async function salvarProduto() {
  const produtoId = Number($("produtoId")?.value ?? 0);
  const nome = ($("produtoNome")?.value ?? "").trim();
  const estoqueMinimo = Number($("produtoEstoqueMinimo")?.value ?? 0);
  const status = $("produtoStatus");

  if (status) status.textContent = "";

  if (!nome) {
    if (status) status.textContent = "Informe o nome do produto.";
    return;
  }

  if (!Number.isFinite(estoqueMinimo) || estoqueMinimo < 0) {
    if (status) status.textContent = "Informe um estoque mínimo válido.";
    return;
  }

  const fornecedores = fornecedoresValidosParaEnvio();

  const fornecedoresComErro = fornecedores.find(
    (f) => f.preco_custo < 0 || f.preco_venda < 0
  );

  if (fornecedoresComErro) {
    if (status) status.textContent = "Os preços dos fornecedores devem ser maiores ou iguais a zero.";
    return;
  }

  const fornecedorDuplicado = fornecedores.find((f, index) =>
    fornecedores.findIndex((x) => Number(x.fornecedor_id) === Number(f.fornecedor_id)) !== index
  );

  if (fornecedorDuplicado) {
    if (status) status.textContent = "O mesmo fornecedor não pode ser adicionado mais de uma vez para o mesmo produto.";
    return;
  }

  if (fornecedores.length > 0) {
    const principalCount = fornecedores.filter((f) => Number(f.principal) === 1).length;
    if (principalCount !== 1) {
      fornecedores[0].principal = 1;
      for (let i = 1; i < fornecedores.length; i += 1) {
        fornecedores[i].principal = 0;
      }
    }
  }

  if (status) {
    status.textContent = produtoId > 0 ? "Atualizando produto..." : "Salvando produto...";
  }

  try {
    let resp;

    const produtoAtual = produtosCache.find((p) => Number(p?.id ?? 0) === produtoId);

    const payload = {
      nome,
      quantidade: produtoId > 0 ? Number(produtoAtual?.quantidade ?? 0) : 0,
      estoque_minimo: estoqueMinimo,
      fornecedores
    };

    if (produtoId > 0) {
      resp = await apiRequest(
        "atualizar_produto",
        {
          ...payload,
          produto_id: produtoId
        },
        "POST"
      );
    } else {
      resp = await apiRequest("criar_produto", payload, "POST");
    }

    if (!resp?.sucesso) {
      if (status) status.textContent = resp?.mensagem || "Erro ao salvar produto.";
      return;
    }

    if (status) {
      status.textContent = produtoId > 0
        ? "Produto atualizado com sucesso."
        : "Produto cadastrado com sucesso.";
    }

    await carregarProdutos();

    setTimeout(() => {
      getModal()?.hide();
    }, 500);

    logJsInfo({
      origem: "produtos.js",
      mensagem: produtoId > 0 ? "Produto atualizado com sucesso" : "Produto criado com sucesso",
      produto_id: produtoId || (resp?.dados?.id ?? null),
      nome,
      estoque_minimo: estoqueMinimo,
      fornecedores: fornecedores.length
    });
  } catch (err) {
    if (status) status.textContent = "Erro inesperado ao salvar produto.";

    logJsError({
      origem: "produtos.js",
      mensagem: "Erro ao salvar produto",
      detalhe: err?.message,
      stack: err?.stack
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
  $("btnAtualizarProdutos")?.addEventListener("click", async () => {
    await carregarFornecedoresCadastrados();
    await carregarProdutos();
  });

  $("btnNovoProduto")?.addEventListener("click", abrirModalNovoProduto);

  $("btnAdicionarFornecedor")?.addEventListener("click", async () => {
    if (fornecedoresCadastrados.length === 0) {
      await carregarFornecedoresCadastrados();
    }
    adicionarFornecedor();
  });
}

function bindModal() {
  $("btnSalvarProduto")?.addEventListener("click", salvarProduto);

  $("listaFornecedores")?.addEventListener("input", (ev) => {
    const input = ev.target.closest("[data-campo][data-index]");
    if (!input) return;

    const index = Number(input.dataset.index || 0);
    const campo = input.dataset.campo || "";

    atualizarFornecedor(index, campo, input.value);
  });

  $("listaFornecedores")?.addEventListener("change", (ev) => {
    const field = ev.target.closest("[data-campo][data-index]");
    if (field) {
      const index = Number(field.dataset.index || 0);
      const campo = field.dataset.campo || "";
      atualizarFornecedor(index, campo, field.value);
      return;
    }

    const radioPrincipal = ev.target.closest("[data-marcar-principal]");
    if (radioPrincipal) {
      const index = Number(radioPrincipal.dataset.marcarPrincipal || 0);
      atualizarFornecedor(index, "principal", 1);
    }
  });

  $("listaFornecedores")?.addEventListener("click", (ev) => {
    const btnRemover = ev.target.closest("[data-remover-fornecedor]");
    if (btnRemover) {
      const index = Number(btnRemover.dataset.removerFornecedor || 0);
      removerFornecedor(index);
      return;
    }

    const radioPrincipal = ev.target.closest("[data-marcar-principal]");
    if (radioPrincipal) {
      const index = Number(radioPrincipal.dataset.marcarPrincipal || 0);
      atualizarFornecedor(index, "principal", 1);
    }
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  bindTabelaAcoes();
  bindBusca();
  bindAcoesTopo();
  bindModal();
  renderListaFornecedores();
  await carregarFornecedoresCadastrados();
  await carregarProdutos();
});