import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

let usuariosCache = [];
let usuariosFiltradosCache = [];
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

function setTelaRestrita() {
  $("usuariosAvisoRestrito")?.classList.remove("d-none");

  const topo = $("usuariosStatusTopo");
  const tbody = $("tabelaUsuarios");
  const btnNovo = $("btnNovoUsuario");
  const filtroCard = $("usuariosFiltroCard");
  const resumoCard = $("usuariosResumoCard");

  if (topo) {
    topo.textContent = "Acesso restrito.";
  }

  if (btnNovo) {
    btnNovo.disabled = true;
  }

  if (filtroCard) {
    filtroCard.classList.add("d-none");
  }

  if (resumoCard) {
    resumoCard.classList.add("d-none");
  }

  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" class="text-center text-danger">
          Esta área é restrita para administradores.
        </td>
      </tr>
    `;
  }
}

function badgeNivel(nivel) {
  const n = String(nivel ?? "").toLowerCase();
  return n === "admin"
    ? `<span class="badge bg-dark">Admin</span>`
    : `<span class="badge bg-secondary">Operador</span>`;
}

function badgeStatus(ativo) {
  return Number(ativo) === 1
    ? `<span class="badge bg-success">Ativo</span>`
    : `<span class="badge bg-danger">Inativo</span>`;
}

function contarAdminsAtivos(lista) {
  return (Array.isArray(lista) ? lista : []).filter((u) => {
    const nivel = String(u?.nivel ?? "").toLowerCase();
    const ativo = Number(u?.ativo ?? 1);
    return nivel === "admin" && ativo === 1;
  }).length;
}

function contarUsuariosAtivos(lista) {
  return (Array.isArray(lista) ? lista : []).filter((u) => Number(u?.ativo ?? 1) === 1).length;
}

function atualizarResumoUsuarios(lista) {
  const total = Array.isArray(lista) ? lista.length : 0;
  const ativos = contarUsuariosAtivos(lista);
  const admins = contarAdminsAtivos(lista);

  if ($("usuariosKpiTotal")) $("usuariosKpiTotal").textContent = String(total);
  if ($("usuariosKpiAtivos")) $("usuariosKpiAtivos").textContent = String(ativos);
  if ($("usuariosKpiAdmins")) $("usuariosKpiAdmins").textContent = String(admins);
}

function renderTabela(usuarios) {
  const tbody = $("tabelaUsuarios");
  if (!tbody) return;

  const usuarioLogado = obterUsuarioLocal();
  const idLogado = Number(usuarioLogado?.id ?? 0);
  const totalAdminsAtivos = contarAdminsAtivos(usuariosCache);

  if (!Array.isArray(usuarios) || usuarios.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" class="text-center text-muted">
          Nenhum usuário encontrado.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = usuarios.map((u) => {
    const id = Number(u?.id ?? 0);
    const ativo = Number(u?.ativo ?? 1);
    const nivel = String(u?.nivel ?? "").toLowerCase();
    const ehProprioUsuario = id === idLogado;
    const ehUltimoAdminAtivo = nivel === "admin" && ativo === 1 && totalAdminsAtivos <= 1;

    const bloquearInativacao = ehProprioUsuario || ehUltimoAdminAtivo;
    const bloquearExclusao = ehProprioUsuario || ehUltimoAdminAtivo;

    const tituloInativar = ehProprioUsuario
      ? "Você não pode alterar o seu próprio status."
      : ehUltimoAdminAtivo
        ? "Não é permitido inativar o último administrador ativo."
        : "";

    const tituloExcluir = ehProprioUsuario
      ? "Você não pode excluir o seu próprio usuário."
      : ehUltimoAdminAtivo
        ? "Não é permitido excluir o último administrador ativo."
        : "";

    return `
      <tr>
        <td>${id}</td>
        <td>${escapeHtml(u?.nome ?? "")}</td>
        <td>${escapeHtml(u?.email ?? "")}</td>
        <td>${badgeNivel(u?.nivel)}</td>
        <td>${badgeStatus(ativo)}</td>
        <td>${escapeHtml(formatarData(u?.criado_em ?? ""))}</td>
        <td>
          <div class="d-flex gap-2 flex-wrap">
            <button
              class="btn btn-sm btn-outline-primary"
              type="button"
              data-acao="editar"
              data-id="${id}"
            >
              Editar
            </button>

            <button
              class="btn btn-sm ${ativo === 1 ? "btn-outline-warning" : "btn-outline-success"}"
              type="button"
              data-acao="toggle-status"
              data-id="${id}"
              ${bloquearInativacao ? "disabled" : ""}
              title="${escapeHtml(tituloInativar)}"
            >
              ${ativo === 1 ? "Inativar" : "Ativar"}
            </button>

            <button
              class="btn btn-sm btn-outline-danger"
              type="button"
              data-acao="excluir"
              data-id="${id}"
              ${bloquearExclusao ? "disabled" : ""}
              title="${escapeHtml(tituloExcluir)}"
            >
              Excluir
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join("");
}

function obterFiltros() {
  return {
    busca: ($("buscaUsuario")?.value ?? "").trim().toLowerCase(),
    nivel: ($("filtroNivelUsuario")?.value ?? "").trim().toLowerCase(),
    ativo: ($("filtroStatusUsuario")?.value ?? "").trim()
  };
}

function aplicarFiltro() {
  const { busca, nivel, ativo } = obterFiltros();

  let lista = [...usuariosCache];

  if (busca) {
    lista = lista.filter((u) => {
      const nome = String(u?.nome ?? "").toLowerCase();
      const email = String(u?.email ?? "").toLowerCase();
      return nome.includes(busca) || email.includes(busca);
    });
  }

  if (nivel) {
    lista = lista.filter((u) => String(u?.nivel ?? "").toLowerCase() === nivel);
  }

  if (ativo !== "") {
    lista = lista.filter((u) => String(Number(u?.ativo ?? 1)) === ativo);
  }

  usuariosFiltradosCache = lista;
  renderTabela(lista);

  const topo = $("usuariosStatusTopo");
  if (topo) {
    topo.textContent = `${lista.length} usuário(s) exibido(s) de ${usuariosCache.length}.`;
  }
}

async function carregarUsuarios() {
  const tbody = $("tabelaUsuarios");
  const topo = $("usuariosStatusTopo");

  if (tbody) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" class="text-center text-muted">
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
      usuariosFiltradosCache = [];
      renderTabela([]);
      atualizarResumoUsuarios([]);

      if (topo) {
        topo.textContent = resp?.mensagem || "Erro ao carregar usuários.";
      }
      return;
    }

    usuariosCache = Array.isArray(resp?.dados) ? resp.dados : [];
    atualizarResumoUsuarios(usuariosCache);
    aplicarFiltro();

    logJsInfo({
      origem: "usuarios.js",
      mensagem: "Usuários carregados com sucesso",
      total: usuariosCache.length
    });
  } catch (err) {
    usuariosCache = [];
    usuariosFiltradosCache = [];
    renderTabela([]);
    atualizarResumoUsuarios([]);

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
  if ($("usuarioAtivo")) $("usuarioAtivo").value = "1";
  if ($("usuarioSenha")) $("usuarioSenha").value = "";

  if ($("tituloModalUsuario")) $("tituloModalUsuario").textContent = "Novo usuário";
  if ($("subtituloModalUsuario")) $("subtituloModalUsuario").textContent = "Preencha os dados para salvar o usuário.";
  if ($("labelSenhaUsuario")) $("labelSenhaUsuario").textContent = "Senha";
  if ($("usuarioSenhaHint")) {
    $("usuarioSenhaHint").textContent = "A senha é obrigatória no cadastro e deve ter pelo menos 6 caracteres.";
  }

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
    if ($("usuarioAtivo")) $("usuarioAtivo").value = String(Number(u?.ativo ?? 1));

    if ($("tituloModalUsuario")) $("tituloModalUsuario").textContent = "Editar usuário";
    if ($("subtituloModalUsuario")) $("subtituloModalUsuario").textContent = "Atualize os dados do usuário.";
    if ($("labelSenhaUsuario")) $("labelSenhaUsuario").textContent = "Nova senha";
    if ($("usuarioSenhaHint")) {
      $("usuarioSenhaHint").textContent =
        "Deixe em branco para manter a senha atual. Se informar, a senha deve ter pelo menos 6 caracteres.";
    }

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
  const ativo = Number($("usuarioAtivo")?.value ?? 1);
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

  if (![0, 1].includes(ativo)) {
    setStatusMensagem("Informe um status válido.", "erro");
    return;
  }

  if (usuarioId <= 0 && !senha) {
    setStatusMensagem("Informe a senha para o novo usuário.", "erro");
    return;
  }

  if (senha && senha.length < 6) {
    setStatusMensagem("A senha deve ter pelo menos 6 caracteres.", "erro");
    return;
  }

  const payload = {
    usuario_id: usuarioId,
    nome,
    email,
    nivel,
    ativo,
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
      nivel,
      ativo
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

async function alterarStatusUsuario(usuarioId, ativoAtual) {
  const novoAtivo = Number(ativoAtual) === 1 ? 0 : 1;
  const acaoTexto = novoAtivo === 1 ? "ativar" : "inativar";

  if (!window.confirm(`Deseja realmente ${acaoTexto} este usuário?`)) {
    return;
  }

  try {
    const resp = await apiRequest("alterar_status_usuario", {
      usuario_id: usuarioId,
      ativo: novoAtivo
    }, "POST");

    if (!resp?.sucesso) {
      window.alert(resp?.mensagem || "Não foi possível alterar o status do usuário.");
      return;
    }

    await carregarUsuarios();
  } catch (err) {
    logJsError({
      origem: "usuarios.js",
      mensagem: "Erro ao alterar status do usuário",
      detalhe: err?.message || String(err),
      stack: err?.stack || null,
      usuario_id: usuarioId
    });

    window.alert("Erro ao alterar o status do usuário.");
  }
}

async function excluirUsuario(usuarioId) {
  if (!window.confirm("Deseja realmente excluir este usuário? Esta ação não poderá ser desfeita.")) {
    return;
  }

  try {
    const resp = await apiRequest("excluir_usuario", {
      usuario_id: usuarioId
    }, "POST");

    if (!resp?.sucesso) {
      window.alert(resp?.mensagem || "Não foi possível excluir o usuário.");
      return;
    }

    await carregarUsuarios();
  } catch (err) {
    logJsError({
      origem: "usuarios.js",
      mensagem: "Erro ao excluir usuário",
      detalhe: err?.message || String(err),
      stack: err?.stack || null,
      usuario_id: usuarioId
    });

    window.alert("Erro ao excluir o usuário.");
  }
}

function bindEventos() {
  $("btnNovoUsuario")?.addEventListener("click", abrirModalNovoUsuario);
  $("btnAtualizarUsuarios")?.addEventListener("click", carregarUsuarios);
  $("btnSalvarUsuario")?.addEventListener("click", salvarUsuario);

  $("buscaUsuario")?.addEventListener("input", aplicarFiltro);
  $("filtroNivelUsuario")?.addEventListener("change", aplicarFiltro);
  $("filtroStatusUsuario")?.addEventListener("change", aplicarFiltro);

  $("btnLimparBuscaUsuario")?.addEventListener("click", () => {
    if ($("buscaUsuario")) $("buscaUsuario").value = "";
    if ($("filtroNivelUsuario")) $("filtroNivelUsuario").value = "";
    if ($("filtroStatusUsuario")) $("filtroStatusUsuario").value = "";
    aplicarFiltro();
  });

  $("tabelaUsuarios")?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-acao][data-id]");
    if (!btn || btn.disabled) return;

    const acao = btn.dataset.acao || "";
    const usuarioId = Number(btn.dataset.id || 0);

    if (acao === "editar") {
      abrirModalEditarUsuario(usuarioId);
      return;
    }

    const usuario = usuariosCache.find((u) => Number(u?.id ?? 0) === usuarioId);
    if (!usuario) return;

    if (acao === "toggle-status") {
      alterarStatusUsuario(usuarioId, Number(usuario?.ativo ?? 1));
      return;
    }

    if (acao === "excluir") {
      excluirUsuario(usuarioId);
    }
  });

  $("modalUsuario")?.addEventListener("hidden.bs.modal", () => {
    limparModal();
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  bindEventos();

  const usuario = obterUsuarioLocal();
  if (!usuarioEhAdmin(usuario)) {
    setTelaRestrita();
    return;
  }

  await carregarUsuarios();

  logJsInfo({
    origem: "usuarios.js",
    mensagem: "Tela de usuários carregada para administrador",
    usuario: usuario?.nome || null
  });
});