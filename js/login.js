// js/login.js
import { apiRequest } from "./api.js";
import { logJsError } from "./logger.js";

const APP_BASE = "/estoque";

function $(id) {
  return document.getElementById(id);
}

function getNextUrl() {
  const params = new URLSearchParams(window.location.search);
  const next = params.get("next");

  if (
    next &&
    next.startsWith("/") &&
    !next.includes("/pages/login.html")
  ) {
    return next;
  }

  return `${APP_BASE}/pages/home.html`;
}

function mostrarErro(mensagem) {
  const msgErro = $("msgErro");
  if (!msgErro) return;

  msgErro.textContent = mensagem || "Ocorreu um erro.";
  msgErro.classList.remove("d-none");
}

function ocultarErro() {
  const msgErro = $("msgErro");
  if (!msgErro) return;

  msgErro.textContent = "";
  msgErro.classList.add("d-none");
}

function setBotaoLoading(loading) {
  const btnEntrar = $("btnEntrar");
  if (!btnEntrar) return;

  if (!btnEntrar.dataset.originalText) {
    btnEntrar.dataset.originalText = btnEntrar.textContent || "Entrar";
  }

  btnEntrar.disabled = !!loading;
  btnEntrar.textContent = loading ? "Entrando..." : btnEntrar.dataset.originalText;
}

function salvarUsuarioLocal(usuario) {
  try {
    localStorage.setItem("usuario", JSON.stringify(usuario));
  } catch (err) {
    logJsError({
      origem: "login.js",
      mensagem: "Erro ao salvar usuário no localStorage",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
  }
}

function bindLimpezaDeErro() {
  const inputLogin = $("login");
  const inputSenha = $("senha");

  [inputLogin, inputSenha].filter(Boolean).forEach((campo) => {
    campo.addEventListener("input", () => {
      ocultarErro();
    });
  });
}

document.addEventListener("DOMContentLoaded", () => {
  const formLogin = $("formLogin");
  const inputLogin = $("login");
  const inputSenha = $("senha");

  if (!formLogin) {
    logJsError({
      origem: "login.js",
      mensagem: "Formulário #formLogin não encontrado"
    });
    return;
  }

  bindLimpezaDeErro();
  ocultarErro();

  formLogin.addEventListener("submit", async (e) => {
    e.preventDefault();
    ocultarErro();

    const login = (inputLogin?.value || "").trim();
    const senha = inputSenha?.value || "";

    if (!login && !senha) {
      mostrarErro("Preencha login e senha.");
      inputLogin?.focus();
      return;
    }

    if (!login) {
      mostrarErro("Preencha o usuário ou e-mail.");
      inputLogin?.focus();
      return;
    }

    if (!senha) {
      mostrarErro("Preencha a senha.");
      inputSenha?.focus();
      return;
    }

    setBotaoLoading(true);

    try {
      const resp = await apiRequest("login", { login, senha }, "POST");

      if (resp?.sucesso === true) {
        const usuario = resp?.dados?.usuario || resp?.usuario || null;

        if (usuario) {
          salvarUsuarioLocal(usuario);
        }

        window.location.replace(getNextUrl());
        return;
      }

      mostrarErro(resp?.mensagem || "Usuário ou senha inválidos.");
      inputSenha?.focus();
      inputSenha?.select?.();
    } catch (err) {
      mostrarErro("Erro de comunicação com o servidor.");

      logJsError({
        origem: "login.js",
        mensagem: err?.message || String(err),
        stack: err?.stack || null
      });
    } finally {
      setBotaoLoading(false);
    }
  });
}); 