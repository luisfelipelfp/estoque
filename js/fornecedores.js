// js/fornecedores.js
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

function normalizarTexto(valor) {
  return String(valor ?? "").trim().replace(/\s+/g, " ");
}

function somenteDigitos(valor) {
  return String(valor ?? "").replace(/\D/g, "");
}

function formatarCnpjVisual(valor) {
  const digits = somenteDigitos(valor).slice(0, 14);
  if (digits.length <= 2) return digits;
  if (digits.length <= 5) return digits.replace(/^(\d{2})(\d+)/, "$1.$2");
  if (digits.length <= 8) return digits.replace(/^(\d{2})(\d{3})(\d+)/, "$1.$2.$3");
  if (digits.length <= 12) return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d+)/, "$1.$2.$3/$4");
  return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2}).*/, "$1.$2.$3/$4-$5");
}

let fornecedoresCache = [];
let modalInstance = null;

function setStatusMensagem(texto = "", tipo = "muted") {
  const el = $("fornecedorStatus");
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

function getModal() {
  const el = $("modalFornecedor");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalInstance) {
    modalInstance = new window.bootstrap.Modal(el);
  }
  return modalInstance;
}

function urlAbrirProduto(produtoId) {
  return `/estoque/pages/produtos.html?produto_id=${encodeURIComponent(produtoId)}`;
}

function atualizarResumoModalFornecedor(dados = {}) {
  const totalProdutos = Number(dados?.total_produtos ?? 0);
  const totalMovimentacoes = Number(dados?.total_movimentacoes ?? 0);
  const ativo = Number(dados?.ativo ?? 1) === 1;
  const fornecedorId = Number(dados?.id ?? 0);

  if ($("fornecedorResumoProdutos")) {
    $("fornecedorResumoProdutos").textContent = `${totalProdutos} produto(s) vinculado(s)`;
  }

  if ($("fornecedorResumoMovimentacoes")) {
    $("fornecedorResumoMovimentacoes").textContent = `${totalMovimentacoes} movimentação(ões)`;
  }

  if ($("fornecedorResumoSituacao")) {
    if (fornecedorId <= 0) {
      $("fornecedorResumoSituacao").textContent = "Novo fornecedor em cadastro. Preencha os dados e salve para começar a usar.";
    } else if (ativo) {
      $("fornecedorResumoSituacao").textContent = "Fornecedor ativo e disponível para uso no sistema.";
    } else {
      $("fornecedorResumoSituacao").textContent = "Fornecedor inativo. Ele permanece apenas para histórico e consulta.";
    }
  }

  if ($("fornecedorIdVisual")) {
    $("fornecedorIdVisual").value = fornecedorId > 0 ? String(fornecedorId) : "Novo cadastro";
  }

  if ($("fornecedorRodapeInfo")) {
    if (fornecedorId > 0) {
      $("fornecedorRodapeInfo").textContent =
        `Fornecedor #${fornecedorId} • ${ativo ? "Ativo" : "Inativo"} • ${totalProdutos} produto(s) • ${totalMovimentacoes} movimentação(ões)`;
    } else {
      $("fornecedorRodapeInfo").textContent = "Novo fornecedor ainda não salvo.";
    }
  }
}

function renderProdutosVinculados(produtos) {
  const box = $("fornecedorProdutosVinculados");
  const total = $("fornecedorResumoProdutos");

  if (!box) return;
  if (total) {
    total.textContent = `${Array.isArray(produtos) ? produtos.length : 0} produto(s) vinculado(s)`;
  }

  if (!Array.isArray(produtos) || produtos.length === 0) {
    box.innerHTML = `
      <div class="text-muted small">
        Nenhum produto vinculado a este fornecedor.
      </div>
    `;
    return;
  }

  box.innerHTML = `
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle mb-0 bg-white">
        <thead>
          <tr>
            <th style="width: 90px;">ID</th>
            <th>Produto</th>
            <th style="width: 180px;">Código no fornecedor</th>
            <th style="width: 120px;">Status</th>
            <th style="width: 130px;">Principal</th>
            <th style="width: 150px;">Ações</th>
          </tr>
        </thead>
        <tbody>
          ${produtos.map((p) => {
            const ativo = Number(p?.ativo ?? 1) === 1;
            return `
              <tr>
                <td>${Number(p?.produto_id ?? 0)}</td>
                <td>${escapeHtml(p?.produto_nome ?? "")}</td>
                <td>${escapeHtml(p?.codigo_produto_fornecedor ?? "") || "-"}</td>
                <td>
                  <span class="badge ${ativo ? "bg-success" : "bg-secondary"}">
                    ${ativo ? "Ativo" : "Inativo"}
                  </span>
                </td>
                <td>
                  <span class="badge ${Number(p?.principal ?? 0) === 1 ? "bg-primary" : "bg-secondary"}">
                    ${Number(p?.principal ?? 0) === 1 ? "Sim" : "Não"}
                  </span>
                </td>
                <td>
                  <a
                    href="${urlAbrirProduto(Number(p?.produto_id ?? 0))}"
                    class="btn btn-sm btn-outline-primary"
                  >
                    Abrir produto
                  </a>
                </td>
              </tr>
            `;
          }).join("")}
        </tbody>
      </table>
    </div>
  `;
}

function limparModal() {
  if ($("fornecedorId")) $("fornecedorId").value = "";
  if ($("fornecedorNome")) $("fornecedorNome").value = "";
  if ($("fornecedorCnpj")) $("fornecedorCnpj").value = "";
  if ($("fornecedorTelefone")) $("fornecedorTelefone").value = "";
  if ($("fornecedorEmail")) $("fornecedorEmail").value = "";
  if ($("fornecedorAtivo")) $("fornecedorAtivo").value = "1";
  if ($("fornecedorObservacao")) $("fornecedorObservacao").value = "";
  if ($("tituloModalFornecedor")) $("tituloModalFornecedor").textContent = "Novo Fornecedor";

  setStatusMensagem("");

  const box = $("fornecedorProdutosVinculados");
  if (box) {
    box.innerHTML = `
      <div class="text-muted small">
        Salve o fornecedor ou abra um fornecedor existente para visualizar os produtos vinculados.
      </div>
    `;
  }

  atualizarResumoModalFornecedor({
    id: 0,
    ativo: 1,
    total_produtos: 0,
    total_movimentacoes: 0
  });
}

function abrirModalNovoFornecedor() {
  limparModal();
  getModal()?.show();
}

async function abrirModalFornecedorExistente(fornecedorId) {
  limparModal();

  try {
    const resp = await apiRequest("obter_fornecedor", { fornecedor_id: fornecedorId }, "GET");

    if (!resp?.sucesso || !resp?.dados) {
      setStatusMensagem(resp?.mensagem || "Não foi possível carregar o fornecedor.", "erro");
      return;
    }

    const fornecedor = resp.dados;

    if ($("fornecedorId")) $("fornecedorId").value = String(fornecedor.id ?? "");
    if ($("fornecedorNome")) $("fornecedorNome").value = fornecedor.nome ?? "";
    if ($("fornecedorCnpj")) $("fornecedorCnpj").value = formatarCnpjVisual(fornecedor.cnpj ?? "");
    if ($("fornecedorTelefone")) $("fornecedorTelefone").value = fornecedor.telefone ?? "";
    if ($("fornecedorEmail")) $("fornecedorEmail").value = fornecedor.email ?? "";
    if ($("fornecedorAtivo")) $("fornecedorAtivo").value = String(Number(fornecedor.ativo ?? 1));
    if ($("fornecedorObservacao")) $("fornecedorObservacao").value = fornecedor.observacao ?? "";
    if ($("tituloModalFornecedor")) $("tituloModalFornecedor").textContent = "Editar Fornecedor";

    renderProdutosVinculados(Array.isArray(fornecedor.produtos) ? fornecedor.produtos : []);
    atualizarResumoModalFornecedor(fornecedor);
    getModal()?.show();
  } catch (err) {
    logJsError({
      origem: "fornecedores.js",
      mensagem: "Erro ao obter fornecedor",
      detalhe: err?.message,
      stack: err?.stack,
      fornecedor_id: fornecedorId
    });

    setStatusMensagem("Erro ao carregar fornecedor.", "erro");
  }
}

function atualizarResumoListagem(listaFiltrada) {
  const el = $("fornecedoresResumoListagem");
  if (!el) return;

  const total = Array.isArray(fornecedoresCache) ? fornecedoresCache.length : 0;
  const exibidos = Array.isArray(listaFiltrada) ? listaFiltrada.length : 0;
  const filtroStatus = $("filtroStatusFornecedor")?.value ?? "todos";
  const busca = normalizarTexto($("buscaFornecedor")?.value ?? "");

  let descricaoStatus = "todos os fornecedores";
  if (filtroStatus === "ativos") descricaoStatus = "somente fornecedores ativos";
  if (filtroStatus === "inativos") descricaoStatus = "somente fornecedores inativos";

  if (busca) {
    el.textContent = `Exibindo ${exibidos} de ${total} fornecedor(es), filtrando por "${busca}" em ${descricaoStatus}.`;
    return;
  }

  el.textContent = `Exibindo ${exibidos} de ${total} fornecedor(es) em ${descricaoStatus}.`;
}

function atualizarStatusTopo(listaFiltrada) {
  const el = $("fornecedoresStatusTopo");
  if (!el) return;

  const exibidos = Array.isArray(listaFiltrada) ? listaFiltrada.length : 0;
  const ativos = (Array.isArray(listaFiltrada) ? listaFiltrada : []).filter((f) => Number(f?.ativo ?? 1) === 1).length;
  const inativos = exibidos - ativos;

  if (exibidos === 0) {
    el.textContent = "Nenhum fornecedor encontrado.";
    return;
  }

  el.textContent = `${exibidos} fornecedor(es) exibido(s) • ${ativos} ativo(s) • ${inativos} inativo(s)`;
}

function renderTabela(fornecedores) {
  const tbody = $("tabelaFornecedores");
  if (!tbody) return;

  if (!Array.isArray(fornecedores) || fornecedores.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted">Nenhum fornecedor encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = fornecedores.map((f) => {
    const id = Number(f?.id ?? 0);
    const nome = escapeHtml(f?.nome ?? "");
    const email = escapeHtml(f?.email ?? "");
    const telefone = escapeHtml(f?.telefone ?? "");
    const cnpj = formatarCnpjVisual(f?.cnpj ?? "");
    const ativo = Number(f?.ativo ?? 1) === 1;
    const totalProdutos = Number(f?.total_produtos ?? 0);

    const contatoHtml = `
      <div class="small">
        ${email ? `<div><strong>E-mail:</strong> ${email}</div>` : ""}
        ${telefone ? `<div><strong>Telefone:</strong> ${telefone}</div>` : ""}
        ${cnpj ? `<div><strong>CNPJ:</strong> ${escapeHtml(cnpj)}</div>` : ""}
        ${!email && !telefone && !cnpj ? `<span class="text-muted">Sem contato informado</span>` : ""}
      </div>
    `;

    return `
      <tr class="${ativo ? "" : "table-light"}">
        <td>${id}</td>
        <td>
          <div class="fw-semibold">${nome}</div>
        </td>
        <td>${contatoHtml}</td>
        <td class="text-center">${totalProdutos}</td>
        <td>
          <span class="badge ${ativo ? "bg-success" : "bg-secondary"}">
            ${ativo ? "Ativo" : "Inativo"}
          </span>
        </td>
        <td>
          <button
            class="btn btn-sm btn-outline-primary"
            data-acao="editar"
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

function filtrarFornecedores() {
  const termo = normalizarTexto($("buscaFornecedor")?.value ?? "").toLowerCase();
  const filtroStatus = $("filtroStatusFornecedor")?.value ?? "todos";

  let lista = [...fornecedoresCache];

  if (termo) {
    lista = lista.filter((f) => {
      const nome = String(f?.nome ?? "").toLowerCase();
      const email = String(f?.email ?? "").toLowerCase();
      const cnpj = somenteDigitos(f?.cnpj ?? "");
      const telefone = somenteDigitos(f?.telefone ?? "");
      const termoDigitos = somenteDigitos(termo);

      return (
        nome.includes(termo) ||
        email.includes(termo) ||
        cnpj.includes(termoDigitos) ||
        telefone.includes(termoDigitos)
      );
    });
  }

  if (filtroStatus === "ativos") {
    lista = lista.filter((f) => Number(f?.ativo ?? 1) === 1);
  } else if (filtroStatus === "inativos") {
    lista = lista.filter((f) => Number(f?.ativo ?? 1) === 0);
  }

  return lista;
}

function aplicarFiltro() {
  const filtrados = filtrarFornecedores();
  renderTabela(filtrados);
  atualizarResumoListagem(filtrados);
  atualizarStatusTopo(filtrados);
}

async function carregarFornecedores() {
  const tbody = $("tabelaFornecedores");
  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted">Carregando...</td>
      </tr>
    `;
  }

  const topo = $("fornecedoresStatusTopo");
  if (topo) topo.textContent = "Carregando...";

  try {
    const resp = await apiRequest("listar_fornecedores", {}, "GET");

    if (!resp?.sucesso) {
      fornecedoresCache = [];
      renderTabela([]);
      atualizarResumoListagem([]);
      atualizarStatusTopo([]);
      return;
    }

    fornecedoresCache = Array.isArray(resp?.dados) ? resp.dados : [];
    aplicarFiltro();

    logJsInfo({
      origem: "fornecedores.js",
      mensagem: "Fornecedores carregados com sucesso",
      total: fornecedoresCache.length
    });
  } catch (err) {
    fornecedoresCache = [];
    renderTabela([]);
    atualizarResumoListagem([]);
    atualizarStatusTopo([]);

    logJsError({
      origem: "fornecedores.js",
      mensagem: "Erro ao carregar fornecedores",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

async function salvarFornecedor() {
  const fornecedorId = Number($("fornecedorId")?.value ?? 0);
  const nome = normalizarTexto($("fornecedorNome")?.value ?? "");
  const cnpj = somenteDigitos($("fornecedorCnpj")?.value ?? "");
  const telefone = normalizarTexto($("fornecedorTelefone")?.value ?? "");
  const email = normalizarTexto($("fornecedorEmail")?.value ?? "");
  const ativo = Number($("fornecedorAtivo")?.value ?? 1);
  const observacao = normalizarTexto($("fornecedorObservacao")?.value ?? "");

  setStatusMensagem("");

  if (!nome) {
    setStatusMensagem("Informe o nome do fornecedor.", "erro");
    return;
  }

  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    setStatusMensagem("Informe um e-mail válido.", "erro");
    return;
  }

  if (cnpj && cnpj.length !== 14) {
    setStatusMensagem("O CNPJ deve conter 14 dígitos.", "erro");
    return;
  }

  try {
    setStatusMensagem(
      fornecedorId > 0 ? "Atualizando fornecedor..." : "Salvando fornecedor...",
      "processando"
    );

    const resp = await apiRequest(
      "salvar_fornecedor",
      {
        fornecedor_id: fornecedorId,
        nome,
        cnpj,
        telefone,
        email,
        ativo,
        observacao
      },
      "POST"
    );

    if (!resp?.sucesso) {
      setStatusMensagem(resp?.mensagem || "Erro ao salvar fornecedor.", "erro");
      return;
    }

    setStatusMensagem(
      fornecedorId > 0
        ? (ativo === 0 ? "Fornecedor inativado com sucesso." : "Fornecedor atualizado com sucesso.")
        : "Fornecedor cadastrado com sucesso.",
      "sucesso"
    );

    await carregarFornecedores();

    if (resp?.dados?.id) {
      await abrirModalFornecedorExistente(Number(resp.dados.id));
    }

    setTimeout(() => {
      getModal()?.hide();
    }, 700);

    logJsInfo({
      origem: "fornecedores.js",
      mensagem: fornecedorId > 0 ? "Fornecedor atualizado" : "Fornecedor criado",
      fornecedor_id: fornecedorId || (resp?.dados?.id ?? null),
      nome,
      ativo
    });
  } catch (err) {
    setStatusMensagem("Erro inesperado ao salvar fornecedor.", "erro");

    logJsError({
      origem: "fornecedores.js",
      mensagem: "Erro ao salvar fornecedor",
      detalhe: err?.message,
      stack: err?.stack
    });
  }
}

function bindTabelaAcoes() {
  $("tabelaFornecedores")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-acao='editar'][data-id]");
    if (!btn) return;

    abrirModalFornecedorExistente(Number(btn.dataset.id || 0));
  });
}

function bindBusca() {
  $("buscaFornecedor")?.addEventListener("input", aplicarFiltro);

  $("btnLimparBuscaFornecedor")?.addEventListener("click", () => {
    if ($("buscaFornecedor")) $("buscaFornecedor").value = "";
    aplicarFiltro();
  });

  $("filtroStatusFornecedor")?.addEventListener("change", aplicarFiltro);
}

function bindAcoesTopo() {
  $("btnAtualizarFornecedores")?.addEventListener("click", carregarFornecedores);
  $("btnNovoFornecedor")?.addEventListener("click", abrirModalNovoFornecedor);
}

function bindModal() {
  $("btnSalvarFornecedor")?.addEventListener("click", salvarFornecedor);

  $("fornecedorCnpj")?.addEventListener("input", (ev) => {
    ev.target.value = formatarCnpjVisual(ev.target.value);
  });

  $("fornecedorAtivo")?.addEventListener("change", () => {
    const ativo = Number($("fornecedorAtivo")?.value ?? 1);
    const totalProdutosTexto = $("fornecedorResumoProdutos")?.textContent ?? "0 produto(s) vinculado(s)";
    const totalMovTexto = $("fornecedorResumoMovimentacoes")?.textContent ?? "0 movimentação(ões)";
    const totalProdutos = Number((totalProdutosTexto.match(/\d+/) || [0])[0]);
    const totalMov = Number((totalMovTexto.match(/\d+/) || [0])[0]);

    atualizarResumoModalFornecedor({
      id: Number($("fornecedorId")?.value ?? 0),
      ativo,
      total_produtos: totalProdutos,
      total_movimentacoes: totalMov
    });
  });
}

function bindModalEventos() {
  const modalEl = $("modalFornecedor");
  if (!modalEl) return;

  modalEl.addEventListener("hidden.bs.modal", () => {
    limparModal();
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  bindTabelaAcoes();
  bindBusca();
  bindAcoesTopo();
  bindModal();
  bindModalEventos();
  await carregarFornecedores();
});