// js/usuarios.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let usuariosCache = [];
let modalUsuarioInstance = null;

function $(id) {
  return document.getElementById(id);
}

function escapeHtml(valor) {
  return String(valor ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function obterUsuarioLocal() {
  try {
    const raw = localStorage.getItem("usuario");
    return raw ? JSON.parse(raw) : null;
  } catch (err) {
    logJsError({
      origem: "usuarios.js",
      mensagem: "Erro ao ler usuário do localStorage",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
    return null;
  }
}

function usuarioEhAdmin(usuario) {
  const nivel = String(usuario?.nivel ?? "").trim().toLowerCase();
  return nivel === "admin";
}

function formatarData(data) {
  if (!data) return "-";

  const d = new Date(String(data).replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return String(data);

  return d.toLocaleString("pt-BR");
}

function getModalUsuario() {
  const el = $("modalUsuario");
  if (!el || !window.bootstrap?.Modal) return null;

  if (!modalUsuarioInstance) {
    modalUsuarioInstance = new window.bootstrap.Modal(el);
  }

  return modalUsuarioInstance;
}

function setStatusMensagem(texto = "", tipo = "muted") {
  const el = $("usuarioStatus");
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

function renderMensagemRestricao() {
  const topo = $("usuariosStatusTopo");
  const tbody = $("tabelaUsuarios");
  const btnNovo = $("btnNovoUsuario");

  if (topo) {
    topo.textContent = "Acesso restrito.";
  }

  if (btnNovo) {
    btnNovo.disabled = true;
  }

  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-danger">
          Esta área é restrita para administradores.
        </td>
      </tr>
    `;
  }
}

function renderTabela(usuarios) {
  const tbody = $("tabelaUsuarios");
  if (!tbody) return;

  if (!Array.isArray(usuarios) || usuarios.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted">
          Nenhum usuário encontrado.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = usuarios.map((u) => {
    const nivel = String(u?.nivel ?? "").toLowerCase();
    const badgeNivel = nivel === "admin"
      ? `<span class="badge bg-dark">Admin</span>`
      : `<span class="badge bg-secondary">Operador</span>`;

    return `
      <tr>
        <td>${Number(u?.id ?? 0)}</td>
        <td>${escapeHtml(u?.nome ?? "")}</td>
        <td>${escapeHtml(u?.email ?? "")}</td>
        <td>${badgeNivel}</td>
        <td>${escapeHtml(formatarData(u?.criado_em ?? ""))}</td>
        <td>
          <button
            class="btn btn-sm btn-outline-primary"
            type="button"
            data-acao="editar"
            data-id="${Number(u?.id ?? 0)}"
          >
            Editar
          </button>
        </td>
      </tr>
    `;
  }).join("");
}

function aplicarFiltro() {
  const termo = ($("buscaUsuario")?.value ?? "").trim().toLowerCase();

  if (!termo) {
    renderTabela(usuariosCache);
    return;
  }

  const filtrados = usuariosCache.filter((u) => {
    const nome = String(u?.nome ?? "").toLowerCase();
    const email = String(u?.email ?? "").toLowerCase();
    return nome.includes(termo) || email.includes(termo);
  });

  renderTabela(filtrados);
}

async function carregarUsuarios() {
  const tbody = $("tabelaUsuarios");
  const topo = $("usuariosStatusTopo");

  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted">
          Carregando...
        </td>
      </tr>
    `;
  }

  if (topo) {
    topo.textContent = "Carregando usuários...";
  }

  try {
    const resp = await apiRequest("listar_usuarios", {}, "GET");

    if (!resp?.sucesso) {
      usuariosCache = [];
      renderTabela([]);
      if (topo) {
        topo.textContent = resp?.mensagem || "Erro ao carregar usuários.";
      }
      return;
    }

    usuariosCache = Array.isArray(resp?.dados) ? resp.dados : [];
    aplicarFiltro();

    if (topo) {
      topo.textContent = `${usuariosCache.length} usuário(s) carregado(s).`;
    }

    logJsInfo({
      origem: "usuarios.js",
      mensagem: "Usuários carregados com sucesso",
      total: usuariosCache.length
    });
  } catch (err) {
    usuariosCache = [];
    renderTabela([]);

    if (topo) {
      topo.textContent = "Erro ao carregar usuários.";
    }

    logJsError({
      origem: "usuarios.js",
      mensagem: "Erro ao carregar usuários",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
  }
}

function limparModal() {
  if ($("usuarioId")) $("usuarioId").value = "";
  if ($("usuarioNome")) $("usuarioNome").value = "";
  if ($("usuarioEmail")) $("usuarioEmail").value = "";
  if ($("usuarioNivel")) $("usuarioNivel").value = "operador";
  if ($("usuarioSenha")) $("usuarioSenha").value = "";

  if ($("tituloModalUsuario")) $("tituloModalUsuario").textContent = "Novo usuário";
  if ($("subtituloModalUsuario")) $("subtituloModalUsuario").textContent = "Preencha os dados para salvar o usuário.";
  if ($("labelSenhaUsuario")) $("labelSenhaUsuario").textContent = "Senha";
  if ($("usuarioSenhaHint")) $("usuarioSenhaHint").textContent = "A senha é obrigatória no cadastro.";

  setStatusMensagem("");
}

function abrirModalNovoUsuario() {
  limparModal();
  getModalUsuario()?.show();
}

async function abrirModalEditarUsuario(usuarioId) {
  limparModal();

  try {
    const resp = await apiRequest("obter_usuario", { usuario_id: usuarioId }, "GET");

    if (!resp?.sucesso || !resp?.dados) {
      setStatusMensagem(resp?.mensagem || "Não foi possível carregar o usuário.", "erro");
      return;
    }

    const u = resp.dados;

    if ($("usuarioId")) $("usuarioId").value = String(u?.id ?? "");
    if ($("usuarioNome")) $("usuarioNome").value = String(u?.nome ?? "");
    if ($("usuarioEmail")) $("usuarioEmail").value = String(u?.email ?? "");
    if ($("usuarioNivel")) $("usuarioNivel").value = String(u?.nivel ?? "operador");

    if ($("tituloModalUsuario")) $("tituloModalUsuario").textContent = "Editar usuário";
    if ($("subtituloModalUsuario")) $("subtituloModalUsuario").textContent = "Atualize os dados do usuário.";
    if ($("labelSenhaUsuario")) $("labelSenhaUsuario").textContent = "Nova senha";
    if ($("usuarioSenhaHint")) $("usuarioSenhaHint").textContent = "Deixe em branco para manter a senha atual.";

    getModalUsuario()?.show();
  } catch (err) {
    logJsError({
      origem: "usuarios.js",
      mensagem: "Erro ao obter usuário",
      detalhe: err?.message || String(err),
      stack: err?.stack || null,
      usuario_id: usuarioId
    });

    setStatusMensagem("Erro ao carregar usuário.", "erro");
  }
}

async function salvarUsuario() {
  const usuarioId = Number($("usuarioId")?.value ?? 0);
  const nome = ($("usuarioNome")?.value ?? "").trim();
  const email = ($("usuarioEmail")?.value ?? "").trim();
  const nivel = ($("usuarioNivel")?.value ?? "").trim();
  const senha = ($("usuarioSenha")?.value ?? "").trim();

  setStatusMensagem("");

  if (!nome) {
    setStatusMensagem("Informe o nome do usuário.", "erro");
    return;
  }

  if (!email) {
    setStatusMensagem("Informe o e-mail do usuário.", "erro");
    return;
  }

  if (!nivel || !["admin", "operador"].includes(nivel)) {
    setStatusMensagem("Informe um nível válido.", "erro");
    return;
  }

  if (usuarioId <= 0 && !senha) {
    setStatusMensagem("Informe a senha para o novo usuário.", "erro");
    return;
  }

  const payload = {
    usuario_id: usuarioId,
    nome,
    email,
    nivel,
    senha
  };

  setStatusMensagem(usuarioId > 0 ? "Atualizando usuário..." : "Salvando usuário...", "processando");
  setBtnLoading("btnSalvarUsuario", true);

  try {
    const resp = await apiRequest("salvar_usuario", payload, "POST");

    if (!resp?.sucesso) {
      setStatusMensagem(resp?.mensagem || "Erro ao salvar usuário.", "erro");
      return;
    }

    setStatusMensagem(
      usuarioId > 0 ? "Usuário atualizado com sucesso." : "Usuário cadastrado com sucesso.",
      "sucesso"
    );

    await carregarUsuarios();

    setTimeout(() => {
      getModalUsuario()?.hide();
    }, 500);

    logJsInfo({
      origem: "usuarios.js",
      mensagem: usuarioId > 0 ? "Usuário atualizado" : "Usuário criado",
      usuario_id: usuarioId || (resp?.dados?.id ?? null),
      nome,
      email,
      nivel
    });
  } catch (err) {
    setStatusMensagem("Erro inesperado ao salvar usuário.", "erro");

    logJsError({
      origem: "usuarios.js",
      mensagem: "Erro ao salvar usuário",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
  } finally {
    setBtnLoading("btnSalvarUsuario", false);
  }
}

function bindEventos() {
  $("btnNovoUsuario")?.addEventListener("click", abrirModalNovoUsuario);
  $("btnAtualizarUsuarios")?.addEventListener("click", carregarUsuarios);
  $("btnSalvarUsuario")?.addEventListener("click", salvarUsuario);

  $("buscaUsuario")?.addEventListener("input", aplicarFiltro);

  $("btnLimparBuscaUsuario")?.addEventListener("click", () => {
    if ($("buscaUsuario")) $("buscaUsuario").value = "";
    aplicarFiltro();
  });

  $("tabelaUsuarios")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-acao='editar'][data-id]");
    if (!btn) return;

    abrirModalEditarUsuario(Number(btn.dataset.id || 0));
  });

  $("modalUsuario")?.addEventListener("hidden.bs.modal", () => {
    limparModal();
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  bindEventos();

  const usuario = obterUsuarioLocal();
  if (!usuarioEhAdmin(usuario)) {
    renderMensagemRestricao();
    return;
  }

  await carregarUsuarios();

  logJsInfo({
    origem: "usuarios.js",
    mensagem: "Tela de usuários carregada para administrador",
    usuario: usuario?.nome || null
  });
});