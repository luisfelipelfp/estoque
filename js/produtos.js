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

function normalizarNcm(valor) {
  return String(valor ?? "").replace(/\D/g, "").slice(0, 8);
}

function valorSeguroNumero(valor, fallback = 0) {
  const n = Number(valor);
  return Number.isFinite(n) ? n : fallback;
}

let produtosCache = [];
let fornecedoresCadastrados = [];
let modalInstance = null;
let fornecedoresTemp = [];

function setStatusMensagem(texto = "", tipo = "muted") {
  const el = $("produtoStatus");
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

function getFornecedorSelecionadosCount() {
  return fornecedoresTemp.filter((f) => Number(f?.fornecedor_id ?? 0) > 0).length;
}

function atualizarResumoModal() {
  const badge = $("produtoResumoFornecedorBadge");
  const info = $("produtoResumoFornecedorTexto");

  if (!badge || !info) return;

  const totalLinhas = fornecedoresTemp.length;
  const totalSelecionados = getFornecedorSelecionadosCount();
  const principal = fornecedoresTemp.find(
    (f) => Number(f?.fornecedor_id ?? 0) > 0 && Number(f?.principal ?? 0) === 1
  );

  badge.textContent = `${totalSelecionados} selecionado(s)`;

  if (!totalLinhas) {
    info.textContent = "Você ainda não adicionou fornecedores para este produto.";
    return;
  }

  if (!totalSelecionados) {
    info.textContent = "Existem linhas de fornecedor abertas, mas nenhuma seleção foi feita ainda.";
    return;
  }

  if (principal) {
    const nome = principal.nome || "Fornecedor principal";
    info.textContent = `Fornecedor principal atual: ${nome}.`;
    return;
  }

  info.textContent = "Há fornecedores adicionados, mas nenhum principal válido foi identificado.";
}

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

  tbody.innerHTML = produtos.map((p) => {
    const id = Number(p?.id ?? 0);
    const nome = escapeHtml(p?.nome ?? "");
    const ncmNormalizado = normalizarNcm(p?.ncm ?? "");
    const ncm = ncmNormalizado ? escapeHtml(ncmNormalizado) : "—";

    return `
      <tr>
        <td>${id}</td>
        <td>${nome}</td>
        <td>${ncm}</td>
        <td>
          <button
            class="btn btn-sm btn-outline-primary"
            data-acao="abrir"
            data-id="${id}"
            type="button"
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

  const filtrados = produtosCache.filter((p) => {
    const nome = String(p?.nome ?? "").toLowerCase();
    const ncm = String(p?.ncm ?? "").toLowerCase();
    return nome.includes(termo) || ncm.includes(termo);
  });

  renderTabela(filtrados);
}

async function carregarProdutos() {
  const tbody = $("tabelaProdutos");
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
      atualizarResumoModal();
      return;
    }

    fornecedoresCadastrados = (Array.isArray(resp?.dados) ? resp.dados : [])
      .sort((a, b) => String(a?.nome ?? "").localeCompare(String(b?.nome ?? ""), "pt-BR"));

    atualizarAvisoFornecedores();
    atualizarResumoModal();
  } catch (err) {
    fornecedoresCadastrados = [];
    atualizarAvisoFornecedores();
    atualizarResumoModal();

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
      setStatusMensagem("O mesmo fornecedor não pode ser adicionado mais de uma vez para o mesmo produto.", "erro");
      renderListaFornecedores();
      return;
    }

    fornecedoresTemp[index].fornecedor_id = fornecedorId;
    fornecedoresTemp[index].nome = obterNomeFornecedorPorId(fornecedorId);
    atualizarTituloFornecedorNoDOM(index, fornecedoresTemp[index].nome);

    garantirFornecedorPrincipal();
    renderListaFornecedores();
    setStatusMensagem("");
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

  if (campo === "nome") {
    atualizarTituloFornecedorNoDOM(index, valor);
  }

  atualizarResumoModal();
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
  const principal = Number(fornecedor?.principal || 0) === 1;
  const tituloFornecedor = escapeHtml((fornecedor?.nome ?? "").trim() || `Fornecedor ${index + 1}`);
  const fornecedorSelecionado = Number(fornecedor?.fornecedor_id ?? 0) > 0;

  return `
    <div class="card fornecedor-card border mb-3 ${principal ? "border-primary shadow-sm" : ""}">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <strong data-titulo-fornecedor="${index}">${tituloFornecedor}</strong>
            <div class="small text-muted">
              ${fornecedorSelecionado ? "Fornecedor vinculado ao produto." : "Selecione um fornecedor para completar este bloco."}
            </div>
          </div>

          <div class="d-flex align-items-center gap-3">
            <div class="form-check m-0">
              <input
                class="form-check-input"
                type="radio"
                name="fornecedorPrincipal"
                ${principal ? "checked" : ""}
                data-marcar-principal="${index}"
                ${!fornecedorSelecionado ? "disabled" : ""}
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
            Este é o fornecedor principal usado como referência de vínculo do produto.
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
    atualizarResumoModal();
    return;
  }

  garantirFornecedorPrincipal();

  lista.innerHTML = fornecedoresTemp
    .map((fornecedor, index) => criarFornecedorCard(fornecedor, index))
    .join("");

  atualizarResumoModal();
}

function adicionarFornecedor() {
  if (fornecedoresCadastrados.length === 0) {
    setStatusMensagem("Não há fornecedores cadastrados para adicionar.", "erro");
    return;
  }

  fornecedoresTemp.push(criarFornecedorVazio());
  garantirFornecedorPrincipal();
  renderListaFornecedores();
  setStatusMensagem("Nova linha de fornecedor adicionada.");
}

function limparModal() {
  if ($("produtoId")) $("produtoId").value = "";
  if ($("produtoNome")) $("produtoNome").value = "";
  if ($("produtoNcm")) $("produtoNcm").value = "";
  if ($("produtoEstoqueMinimo")) $("produtoEstoqueMinimo").value = "0";
  if ($("tituloModalProduto")) $("tituloModalProduto").textContent = "Novo Produto";

  setStatusMensagem("");
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
  if ($("produtoNome")) $("produtoNome").value = String(produto.nome ?? "");
  if ($("produtoNcm")) $("produtoNcm").value = normalizarNcm(produto.ncm ?? "");
  if ($("produtoEstoqueMinimo")) $("produtoEstoqueMinimo").value = String(valorSeguroNumero(produto.estoque_minimo, 0));
  if ($("tituloModalProduto")) $("tituloModalProduto").textContent = "Cadastro do Produto";

  if (Array.isArray(produto.fornecedores) && produto.fornecedores.length > 0) {
    fornecedoresTemp = produto.fornecedores.map((f) => ({
      fornecedor_id: String(f?.fornecedor_id ?? ""),
      nome: f?.nome ?? "",
      codigo: f?.codigo ?? "",
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
        observacao: String(f?.observacao ?? "").trim(),
        principal: Number(f?.principal ?? 0) === 1 ? 1 : 0
      };
    })
    .filter((f) => f.fornecedor_id > 0 && f.nome !== "");
}

async function salvarProduto() {
  const produtoId = Number($("produtoId")?.value ?? 0);
  const nome = ($("produtoNome")?.value ?? "").trim();
  const ncm = normalizarNcm($("produtoNcm")?.value ?? "");
  const estoqueMinimo = Number($("produtoEstoqueMinimo")?.value ?? 0);

  setStatusMensagem("");

  if (!nome) {
    setStatusMensagem("Informe o nome do produto.", "erro");
    return;
  }

  if (ncm && ncm.length !== 8) {
    setStatusMensagem("O NCM deve conter exatamente 8 dígitos.", "erro");
    return;
  }

  if (!Number.isFinite(estoqueMinimo) || estoqueMinimo < 0) {
    setStatusMensagem("Informe um estoque mínimo válido.", "erro");
    return;
  }

  const fornecedores = fornecedoresValidosParaEnvio();

  const fornecedorDuplicado = fornecedores.find((f, index) =>
    fornecedores.findIndex((x) => Number(x.fornecedor_id) === Number(f.fornecedor_id)) !== index
  );

  if (fornecedorDuplicado) {
    setStatusMensagem("O mesmo fornecedor não pode ser adicionado mais de uma vez para o mesmo produto.", "erro");
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

  setStatusMensagem(
    produtoId > 0 ? "Atualizando produto..." : "Salvando produto...",
    "processando"
  );

  try {
    let resp;

    const produtoAtual = produtosCache.find((p) => Number(p?.id ?? 0) === produtoId);

    const payload = {
      nome,
      ncm,
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
      setStatusMensagem(resp?.mensagem || "Erro ao salvar produto.", "erro");
      return;
    }

    setStatusMensagem(
      produtoId > 0
        ? "Produto atualizado com sucesso."
        : "Produto cadastrado com sucesso.",
      "sucesso"
    );

    await carregarProdutos();

    setTimeout(() => {
      getModal()?.hide();
    }, 500);

    logJsInfo({
      origem: "produtos.js",
      mensagem: produtoId > 0 ? "Produto atualizado com sucesso" : "Produto criado com sucesso",
      produto_id: produtoId || (resp?.dados?.id ?? null),
      nome,
      ncm,
      estoque_minimo: estoqueMinimo,
      fornecedores: fornecedores.length
    });
  } catch (err) {
    setStatusMensagem("Erro inesperado ao salvar produto.", "erro");

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

  $("produtoNcm")?.addEventListener("input", (ev) => {
    ev.target.value = normalizarNcm(ev.target.value);
  });

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

function bindModalEventos() {
  const modalEl = $("modalProduto");
  if (!modalEl) return;

  modalEl.addEventListener("hidden.bs.modal", () => {
    limparModal();
  });
}

async function abrirProdutoViaQueryStringSeExistir() {
  const url = new URL(window.location.href);
  const produtoId = Number(url.searchParams.get("produto_id") || 0);

  if (produtoId <= 0) return;

  await abrirModalProdutoExistente(produtoId);

  url.searchParams.delete("produto_id");
  window.history.replaceState({}, "", url.toString());
}

document.addEventListener("DOMContentLoaded", async () => {
  bindTabelaAcoes();
  bindBusca();
  bindAcoesTopo();
  bindModal();
  bindModalEventos();

  renderListaFornecedores();
  await carregarFornecedoresCadastrados();
  await carregarProdutos();
  await abrirProdutoViaQueryStringSeExistir();
});