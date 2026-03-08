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

let fornecedoresCache = [];
let modalInstance = null;

function getModal() {
  const el = $("modalFornecedor");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalInstance) {
    modalInstance = new window.bootstrap.Modal(el);
  }
  return modalInstance;
}

function renderProdutosVinculados(produtos) {
  const box = $("fornecedorProdutosVinculados");
  if (!box) return;

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
            <th style="width: 130px;">Principal</th>
          </tr>
        </thead>
        <tbody>
          ${produtos.map((p) => `
            <tr>
              <td>${Number(p?.produto_id ?? 0)}</td>
              <td>${escapeHtml(p?.produto_nome ?? "")}</td>
              <td>${escapeHtml(p?.codigo_produto_fornecedor ?? "") || "-"}</td>
              <td>
                <span class="badge ${Number(p?.principal ?? 0) === 1 ? "bg-primary" : "bg-secondary"}">
                  ${Number(p?.principal ?? 0) === 1 ? "Sim" : "Não"}
                </span>
              </td>
            </tr>
          `).join("")}
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
  if ($("fornecedorStatus")) $("fornecedorStatus").textContent = "";
  if ($("tituloModalFornecedor")) $("tituloModalFornecedor").textContent = "Novo Fornecedor";

  renderProdutosVinculados([]);
  const box = $("fornecedorProdutosVinculados");
  if (box) {
    box.innerHTML = `
      <div class="text-muted small">
        Salve o fornecedor ou abra um fornecedor existente para visualizar os produtos vinculados.
      </div>
    `;
  }
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
      if ($("fornecedorStatus")) {
        $("fornecedorStatus").textContent = resp?.mensagem || "Não foi possível carregar o fornecedor.";
      }
      return;
    }

    const fornecedor = resp.dados;

    if ($("fornecedorId")) $("fornecedorId").value = String(fornecedor.id ?? "");
    if ($("fornecedorNome")) $("fornecedorNome").value = fornecedor.nome ?? "";
    if ($("fornecedorCnpj")) $("fornecedorCnpj").value = fornecedor.cnpj ?? "";
    if ($("fornecedorTelefone")) $("fornecedorTelefone").value = fornecedor.telefone ?? "";
    if ($("fornecedorEmail")) $("fornecedorEmail").value = fornecedor.email ?? "";
    if ($("fornecedorAtivo")) $("fornecedorAtivo").value = String(Number(fornecedor.ativo ?? 1));
    if ($("fornecedorObservacao")) $("fornecedorObservacao").value = fornecedor.observacao ?? "";
    if ($("tituloModalFornecedor")) $("tituloModalFornecedor").textContent = "Editar Fornecedor";

    renderProdutosVinculados(Array.isArray(fornecedor.produtos) ? fornecedor.produtos : []);
    getModal()?.show();
  } catch (err) {
    logJsError({
      origem: "fornecedores.js",
      mensagem: "Erro ao obter fornecedor",
      detalhe: err?.message,
      stack: err?.stack,
      fornecedor_id: fornecedorId
    });

    if ($("fornecedorStatus")) {
      $("fornecedorStatus").textContent = "Erro ao carregar fornecedor.";
    }
  }
}

function renderTabela(fornecedores) {
  const tbody = $("tabelaFornecedores");
  if (!tbody) return;

  if (!Array.isArray(fornecedores) || fornecedores.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="4" class="text-center text-muted">Nenhum fornecedor encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = fornecedores.map((f) => {
    const id = Number(f?.id ?? 0);
    const nome = escapeHtml(f?.nome ?? "");
    const ativo = Number(f?.ativo ?? 1) === 1;

    return `
      <tr>
        <td>${id}</td>
        <td>${nome}</td>
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
          >
            Editar
          </button>
        </td>
      </tr>
    `;
  }).join("");
}

function aplicarFiltro() {
  const termo = ($("buscaFornecedor")?.value ?? "").trim().toLowerCase();

  if (!termo) {
    renderTabela(fornecedoresCache);
    return;
  }

  const filtrados = fornecedoresCache.filter((f) =>
    String(f?.nome ?? "").toLowerCase().includes(termo)
  );

  renderTabela(filtrados);
}

async function carregarFornecedores() {
  const tbody = $("tabelaFornecedores");
  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="4" class="text-center text-muted">Carregando...</td>
      </tr>
    `;
  }

  try {
    const resp = await apiRequest("listar_fornecedores", {}, "GET");

    if (!resp?.sucesso) {
      fornecedoresCache = [];
      renderTabela([]);
      return;
    }

    fornecedoresCache = Array.isArray(resp?.dados) ? resp.dados : [];
    aplicarFiltro();

    logJsInfo({
      origem: "fornecedores.js",
      mensagem: "Fornecedores carregados com sucesso",
      total: fornecedoresCache.length,
    });
  } catch (err) {
    fornecedoresCache = [];
    renderTabela([]);

    logJsError({
      origem: "fornecedores.js",
      mensagem: "Erro ao carregar fornecedores",
      detalhe: err?.message,
      stack: err?.stack,
    });
  }
}

async function salvarFornecedor() {
  const fornecedorId = Number($("fornecedorId")?.value ?? 0);
  const nome = ($("fornecedorNome")?.value ?? "").trim();
  const cnpj = ($("fornecedorCnpj")?.value ?? "").trim();
  const telefone = ($("fornecedorTelefone")?.value ?? "").trim();
  const email = ($("fornecedorEmail")?.value ?? "").trim();
  const ativo = Number($("fornecedorAtivo")?.value ?? 1);
  const observacao = ($("fornecedorObservacao")?.value ?? "").trim();
  const status = $("fornecedorStatus");

  if (status) status.textContent = "";

  if (!nome) {
    if (status) status.textContent = "Informe o nome do fornecedor.";
    return;
  }

  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    if (status) status.textContent = "Informe um e-mail válido.";
    return;
  }

  try {
    if (status) {
      status.textContent = fornecedorId > 0
        ? "Atualizando fornecedor..."
        : "Salvando fornecedor...";
    }

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
      if (status) status.textContent = resp?.mensagem || "Erro ao salvar fornecedor.";
      return;
    }

    if (status) {
      status.textContent = fornecedorId > 0
        ? "Fornecedor atualizado com sucesso."
        : "Fornecedor cadastrado com sucesso.";
    }

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
      ativo,
    });
  } catch (err) {
    if (status) status.textContent = "Erro inesperado ao salvar fornecedor.";

    logJsError({
      origem: "fornecedores.js",
      mensagem: "Erro ao salvar fornecedor",
      detalhe: err?.message,
      stack: err?.stack,
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
}

function bindAcoesTopo() {
  $("btnAtualizarFornecedores")?.addEventListener("click", carregarFornecedores);
  $("btnNovoFornecedor")?.addEventListener("click", abrirModalNovoFornecedor);
}

function bindModal() {
  $("btnSalvarFornecedor")?.addEventListener("click", salvarFornecedor);
}

document.addEventListener("DOMContentLoaded", async () => {
  bindTabelaAcoes();
  bindBusca();
  bindAcoesTopo();
  bindModal();
  carregarFornecedores();
});