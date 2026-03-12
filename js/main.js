import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

const APP_BASE = "/estoque";

function $(id) {
  return document.getElementById(id);
}

function redirectToLogin() {
  const next = window.location.pathname + window.location.search;
  const url = `${APP_BASE}/pages/login.html?next=${encodeURIComponent(next)}`;
  window.location.replace(url);
}

function obterPaginaAtual() {
  const path = window.location.pathname.toLowerCase();

  if (path.endsWith("/estoque/") || path.endsWith("/estoque/index.html")) return "home";
  if (path.includes("/pages/home.html")) return "home";
  if (path.includes("/pages/estoque.html")) return "estoque";
  if (path.includes("/pages/produtos.html")) return "produtos";
  if (path.includes("/pages/fornecedores.html")) return "fornecedores";
  if (path.includes("/pages/relatorios.html")) return "relatorios";
  if (path.includes("/pages/usuarios.html")) return "usuarios";
  if (path.includes("/pages/movimentacoes.html")) return "movimentacoes";
  if (path.includes("/pages/login.html")) return "login";

  return "";
}

function salvarUsuarioLocal(usuario) {
  try {
    localStorage.setItem("usuario", JSON.stringify(usuario));
  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: "Erro ao salvar usuário no localStorage",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
  }
}

function removerUsuarioLocal() {
  try {
    localStorage.removeItem("usuario");
  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: "Erro ao remover usuário do localStorage",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
  }
}

function obterUsuarioLocal() {
  try {
    const raw = localStorage.getItem("usuario");
    return raw ? JSON.parse(raw) : null;
  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: "Erro ao ler usuário do localStorage",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
    return null;
  }
}

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");
    const usuario = resp?.dados?.usuario || resp?.usuario || null;

    if (!resp?.sucesso || !usuario) {
      removerUsuarioLocal();
      redirectToLogin();
      return null;
    }

    salvarUsuarioLocal(usuario);
    return usuario;
  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: "Erro ao verificar login",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });

    removerUsuarioLocal();
    redirectToLogin();
    return null;
  }
}

async function carregarComponente(seletorOuId, url) {
  const el =
    document.querySelector(seletorOuId) ||
    document.getElementById(String(seletorOuId).replace(/^#/, ""));

  if (!el) return null;

  try {
    const resp = await fetch(url, {
      cache: "no-store",
      credentials: "same-origin"
    });

    if (!resp.ok) {
      el.innerHTML = "";
      logJsError({
        origem: "main.js",
        mensagem: "Falha ao carregar componente",
        detalhe: `HTTP ${resp.status} - ${url}`
      });
      return null;
    }

    const html = await resp.text();
    el.innerHTML = html;
    return el;
  } catch (err) {
    el.innerHTML = "";
    logJsError({
      origem: "main.js",
      mensagem: `Erro ao carregar componente: ${url}`,
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
    return null;
  }
}

function usuarioEhAdmin(usuario) {
  const nivel = String(usuario?.nivel ?? "").trim().toLowerCase();
  return nivel === "admin" || nivel === "administrador";
}

function preencherUsuario(usuario) {
  const nome = String(usuario?.nome ?? "").trim();
  const nivel = String(usuario?.nivel ?? "").trim();
  const textoUsuario = nivel ? `${nome} (${nivel})` : (nome || "Usuário");

  const navbarUser = $("usuarioLogado");
  if (navbarUser) {
    navbarUser.textContent = textoUsuario;
    navbarUser.setAttribute("title", textoUsuario);
  }

  const sidebarUser = $("sidebarUsuarioNome");
  if (sidebarUser) {
    sidebarUser.textContent = textoUsuario;
    sidebarUser.setAttribute("title", textoUsuario);
  }
}

function aplicarPermissoes(usuario) {
  const ehAdmin = usuarioEhAdmin(usuario);

  const menuUsuarios = $("menuUsuarios");
  const sidebarAdminDivider = $("sidebarAdminDivider");
  const navUsuariosItem = $("navUsuariosItem");

  if (menuUsuarios) {
    menuUsuarios.hidden = !ehAdmin;
  }

  if (sidebarAdminDivider) {
    sidebarAdminDivider.hidden = !ehAdmin;
  }

  if (navUsuariosItem) {
    navUsuariosItem.hidden = !ehAdmin;
  }
}

function protegerPaginaAdmin(usuario) {
  const paginaAtual = obterPaginaAtual();

  if (paginaAtual === "usuarios" && !usuarioEhAdmin(usuario)) {
    window.location.replace(`${APP_BASE}/pages/home.html`);
    return false;
  }

  return true;
}

function linkCorrespondePaginaAtual(link, paginaAtual) {
  const href = String(link.getAttribute("href") || "").toLowerCase();

  return (
    (paginaAtual === "home" && href.includes("/pages/home.html")) ||
    (paginaAtual === "estoque" && href.includes("/pages/estoque.html")) ||
    (paginaAtual === "produtos" && href.includes("/pages/produtos.html")) ||
    (paginaAtual === "fornecedores" && href.includes("/pages/fornecedores.html")) ||
    (paginaAtual === "relatorios" && href.includes("/pages/relatorios.html")) ||
    (paginaAtual === "usuarios" && href.includes("/pages/usuarios.html")) ||
    (paginaAtual === "movimentacoes" && href.includes("/pages/movimentacoes.html"))
  );
}

function marcarLinkAtivoSidebar() {
  const paginaAtual = obterPaginaAtual();

  document.querySelectorAll("[data-sidebar-page]").forEach((link) => {
    const paginaLink = String(link.getAttribute("data-sidebar-page") || "").toLowerCase();
    const ativo = paginaAtual !== "" && paginaLink === paginaAtual;

    link.classList.toggle("active", ativo);

    if (ativo) {
      link.setAttribute("aria-current", "page");
    } else {
      link.removeAttribute("aria-current");
    }
  });

  document.querySelectorAll(".navbar .nav-link").forEach((link) => {
    const ativo = linkCorrespondePaginaAtual(link, paginaAtual);

    link.classList.toggle("active", ativo);

    if (ativo) {
      link.setAttribute("aria-current", "page");
    } else {
      link.removeAttribute("aria-current");
    }
  });
}

function fecharNavbarMobile() {
  const navbarCollapse = $("navbarEstoque");
  if (!navbarCollapse || !window.bootstrap?.Collapse) return;

  if (navbarCollapse.classList.contains("show")) {
    const inst = window.bootstrap.Collapse.getInstance(navbarCollapse)
      || new window.bootstrap.Collapse(navbarCollapse, { toggle: false });

    inst.hide();
  }
}

function bindFecharNavbarAoClicarLink() {
  document.querySelectorAll("#navbarEstoque .nav-link").forEach((link) => {
    if (link.dataset.boundCloseNav === "1") return;

    link.dataset.boundCloseNav = "1";
    link.addEventListener("click", () => {
      fecharNavbarMobile();
    });
  });
}

async function executarLogout() {
  try {
    await apiRequest("logout", null, "POST");
  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: "Erro ao executar logout",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
  } finally {
    removerUsuarioLocal();
    window.location.replace(`${APP_BASE}/pages/login.html`);
  }
}

function bindLogout() {
  const botoes = [$("btnLogout"), $("btnLogoutSidebar")].filter(Boolean);

  botoes.forEach((btn) => {
    if (btn.dataset.boundLogout === "1") return;

    btn.dataset.boundLogout = "1";
    btn.addEventListener("click", executarLogout);
  });
}

async function carregarLayout(usuario) {
  const navbarEl = await carregarComponente("#navbar", `${APP_BASE}/components/navbar.html?v=20260311-s3`);
  const sidebarEl = await carregarComponente("#sidebar", `${APP_BASE}/components/sidebar.html?v=20260311-s3`);

  if (sidebarEl) {
    sidebarEl.classList.remove("d-none");
  }

  if (navbarEl || sidebarEl) {
    preencherUsuario(usuario);
    aplicarPermissoes(usuario);
    marcarLinkAtivoSidebar();
    bindLogout();
    bindFecharNavbarAoClicarLink();
  }
}

function paginaExigeAutenticacao() {
  return obterPaginaAtual() !== "login";
}

async function sincronizarUsuarioDaSessao() {
  const usuarioLocal = obterUsuarioLocal();

  if (!usuarioLocal) {
    return null;
  }

  return usuarioLocal;
}

document.addEventListener("DOMContentLoaded", async () => {
  if (!paginaExigeAutenticacao()) {
    return;
  }

  await sincronizarUsuarioDaSessao();

  const usuario = await verificarLogin();
  if (!usuario) return;

  if (!protegerPaginaAdmin(usuario)) {
    return;
  }

  await carregarLayout(usuario);

  logJsInfo({
    origem: "main.js",
    mensagem: "Usuário autenticado",
    usuario: usuario?.nome || null,
    pagina: obterPaginaAtual(),
    nivel: usuario?.nivel || null
  });
});