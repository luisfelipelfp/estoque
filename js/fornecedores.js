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

function limparModal() {
  if ($("fornecedorId")) $("fornecedorId").value = "";
  if ($("fornecedorNome")) $("fornecedorNome").value = "";
  if ($("fornecedorAtivo")) $("fornecedorAtivo").value = "1";
  if ($("fornecedorObservacao")) $("fornecedorObservacao").value = "";
  if ($("fornecedorStatus")) $("fornecedorStatus").textContent = "";
  if ($("tituloModalFornecedor")) $("tituloModalFornecedor").textContent = "Novo Fornecedor";
}

function abrirModalNovoFornecedor() {
  limparModal();
  getModal()?.show();
}

function abrirModalFornecedorExistente(fornecedorId) {
  const fornecedor = fornecedoresCache.find((f) => Number(f?.id ?? 0) === Number(fornecedorId));
  if (!fornecedor) return;

  limparModal();

  if ($("fornecedorId")) $("fornecedorId").value = String(fornecedor.id ?? "");
  if ($("fornecedorNome")) $("fornecedorNome").value = fornecedor.nome ?? "";
  if ($("fornecedorAtivo")) $("fornecedorAtivo").value = String(Number(fornecedor.ativo ?? 1));
  if ($("fornecedorObservacao")) $("fornecedorObservacao").value = fornecedor.observacao ?? "";
  if ($("tituloModalFornecedor")) $("tituloModalFornecedor").textContent = "Editar Fornecedor";

  getModal()?.show();
}

function renderTabela(fornecedores) {
  const tbody = $("tabelaFornecedores");
  if (!tbody) return;

  if (!Array.isArray(fornecedores) || fornecedores.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="5" class="text-center text-muted">Nenhum fornecedor encontrado.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = fornecedores.map((f) => {
    const id = Number(f?.id ?? 0);
    const nome = escapeHtml(f?.nome ?? "");
    const ativo = Number(f?.ativo ?? 1) === 1;
    const totalProdutos = Number(f?.total_produtos ?? 0);

    return `
      <tr>
        <td>${id}</td>
        <td>${nome}</td>
        <td>
          <span class="badge ${ativo ? "bg-success" : "bg-secondary"}">
            ${ativo ? "Ativo" : "Inativo"}
          </span>
        </td>
        <td>${totalProdutos}</td>
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
        <td colspan="5" class="text-center text-muted">Carregando...</td>
      </tr>
    `;
  }

  try {
    // Simulação visual enquanto a API ainda não existe
    fornecedoresCache = [
      { id: 1, nome: "Ferragens Brasil", ativo: 1, observacao: "", total_produtos: 12 },
      { id: 2, nome: "Acabamentos São Paulo", ativo: 1, observacao: "", total_produtos: 7 },
      { id: 3, nome: "Distribuidora Central", ativo: 0, observacao: "", total_produtos: 3 }
    ];

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
  const ativo = Number($("fornecedorAtivo")?.value ?? 1);
  const observacao = ($("fornecedorObservacao")?.value ?? "").trim();
  const status = $("fornecedorStatus");

  if (status) status.textContent = "";

  if (!nome) {
    if (status) status.textContent = "Informe o nome do fornecedor.";
    return;
  }

  try {
    if (status) {
      status.textContent = fornecedorId > 0
        ? "Atualizando fornecedor..."
        : "Salvando fornecedor...";
    }

    // Simulação visual enquanto a API ainda não existe
    if (fornecedorId > 0) {
      fornecedoresCache = fornecedoresCache.map((f) =>
        Number(f.id) === fornecedorId
          ? { ...f, nome, ativo, observacao }
          : f
      );
    } else {
      const novoId = fornecedoresCache.length > 0
        ? Math.max(...fornecedoresCache.map((f) => Number(f.id || 0))) + 1
        : 1;

      fornecedoresCache.unshift({
        id: novoId,
        nome,
        ativo,
        observacao,
        total_produtos: 0
      });
    }

    aplicarFiltro();

    if (status) {
      status.textContent = fornecedorId > 0
        ? "Fornecedor atualizado com sucesso."
        : "Fornecedor cadastrado com sucesso.";
    }

    setTimeout(() => {
      getModal()?.hide();
    }, 500);

    logJsInfo({
      origem: "fornecedores.js",
      mensagem: fornecedorId > 0 ? "Fornecedor atualizado" : "Fornecedor criado",
      fornecedor_id: fornecedorId || null,
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