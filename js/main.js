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

  if (path.includes("/pages/estoque.html")) return "estoque";
  if (path.includes("/pages/produtos.html")) return "produtos";
  if (path.includes("/pages/fornecedores.html")) return "fornecedores";
  if (path.includes("/relatorios.html")) return "relatorios";
  if (path.includes("/pages/usuarios.html")) return "usuarios";
  if (path.includes("/pages/movimentacoes.html")) return "movimentacoes";

  return "";
}

async function verificarLogin() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");

    const usuario = resp?.dados?.usuario || resp?.usuario;
    if (!resp?.sucesso || !usuario) {
      redirectToLogin();
      return null;
    }

    localStorage.setItem("usuario", JSON.stringify(usuario));
    return usuario;
  } catch (err) {
    logJsError({
      origem: "main.js",
      mensagem: "Erro ao verificar login",
      detalhe: err?.message || String(err),
      stack: err?.stack || null,
    });
    redirectToLogin();
    return null;
  }
}

async function carregarComponente(seletorOuId, url) {
  const el =
    document.querySelector(seletorOuId) ||
    document.getElementById(seletorOuId.replace(/^#/, ""));

  if (!el) return null;

  try {
    const resp = await fetch(url, {
      cache: "no-store",
      credentials: "same-origin",
    });

    if (!resp.ok) {
      el.innerHTML = "";
      logJsError({
        origem: "main.js",
        mensagem: "Falha ao carregar componente",
        detalhe: `HTTP ${resp.status} - ${url}`,
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
      stack: err?.stack || null,
    });
    return null;
  }
}

function preencherUsuario(usuario) {
  const nome = String(usuario?.nome ?? "").trim();
  const nivel = String(usuario?.nivel ?? "").trim();
  const textoUsuario = nivel ? `${nome} (${nivel})` : nome;

  const navbarUser = $("usuarioLogado");
  if (navbarUser) {
    navbarUser.textContent = textoUsuario || "Usuário";
  }

  const sidebarUser = $("sidebarUsuarioNome");
  if (sidebarUser) {
    sidebarUser.textContent = textoUsuario || "Usuário";
  }
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
    const href = String(link.getAttribute("href") || "").toLowerCase();

    const ativo =
      (paginaAtual === "estoque" && href.includes("/pages/estoque.html")) ||
      (paginaAtual === "produtos" && href.includes("/pages/produtos.html")) ||
      (paginaAtual === "fornecedores" && href.includes("/pages/fornecedores.html")) ||
      (paginaAtual === "relatorios" && href.includes("/relatorios.html")) ||
      (paginaAtual === "usuarios" && href.includes("/pages/usuarios.html")) ||
      (paginaAtual === "movimentacoes" && href.includes("/pages/movimentacoes.html"));

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
      stack: err?.stack || null,
    });
  } finally {
    localStorage.removeItem("usuario");
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
  await carregarComponente("#navbar", `${APP_BASE}/components/navbar.html?v=20260307`);
  const sidebarEl = await carregarComponente("#sidebar", `${APP_BASE}/components/sidebar.html?v=20260307`);

  if (sidebarEl) {
    sidebarEl.classList.remove("d-none");
  }

  preencherUsuario(usuario);
  marcarLinkAtivoSidebar();
  bindLogout();
}

document.addEventListener("DOMContentLoaded", async () => {
  const usuario = await verificarLogin();
  if (!usuario) return;

  await carregarLayout(usuario);

  logJsInfo({
    origem: "main.js",
    mensagem: "Usuário autenticado",
    usuario: usuario.nome || null,
  });
});