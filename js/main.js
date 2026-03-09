// js/main.js
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

function usuarioEhAdmin(usuario) {
  const nivel = String(usuario?.nivel ?? "").trim().toLowerCase();
  return nivel === "admin" || nivel === "administrador";
}

function aplicarPermissoes(usuario) {
  const ehAdmin = usuarioEhAdmin(usuario);

  const menuUsuarios = $("menuUsuarios");
  const sidebarAdminDivider = $("sidebarAdminDivider");
  const navUsuariosItem = $("navUsuariosItem");

  if (menuUsuarios) {
    menuUsuarios.style.display = ehAdmin ? "flex" : "none";
  }

  if (sidebarAdminDivider) {
    sidebarAdminDivider.style.display = ehAdmin ? "block" : "none";
  }

  if (navUsuariosItem) {
    navUsuariosItem.style.display = ehAdmin ? "" : "none";
  }
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
  const navbarEl = await carregarComponente("#navbar", `${APP_BASE}/components/navbar.html?v=20260308-menu`);
  const sidebarEl = await carregarComponente("#sidebar", `${APP_BASE}/components/sidebar.html?v=20260308-menu`);

  if (sidebarEl) {
    sidebarEl.classList.remove("d-none");
  }

  if (navbarEl || sidebarEl) {
    preencherUsuario(usuario);
    aplicarPermissoes(usuario);
    marcarLinkAtivoSidebar();
    bindLogout();
  }
}

function paginaExigeAutenticacao() {
  return obterPaginaAtual() !== "login";
}

document.addEventListener("DOMContentLoaded", async () => {
  if (!paginaExigeAutenticacao()) {
    return;
  }

  const usuario = await verificarLogin();
  if (!usuario) return;

  await carregarLayout(usuario);

  logJsInfo({
    origem: "main.js",
    mensagem: "Usuário autenticado",
    usuario: usuario.nome || null,
    pagina: obterPaginaAtual(),
    nivel: usuario?.nivel || null
  });
});