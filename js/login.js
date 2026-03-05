// js/login.js
import { apiRequest } from "./api.js";
import { logJsError, logJsInfo } from "./logger.js";

const APP_BASE = "/estoque";
const LOGIN_PAGE = `${APP_BASE}/pages/login.html`;
const HOME_PAGE = `${APP_BASE}/index.html`;

function showError(el, msg) {
  if (!el) return;
  el.textContent = msg || "Erro";
  el.classList.remove("d-none");
}

function hideError(el) {
  if (!el) return;
  el.textContent = "";
  el.classList.add("d-none");
}

function setLoading(btn, loading) {
  if (!btn) return;
  btn.disabled = !!loading;
  btn.textContent = loading ? "Entrando..." : "Entrar";
}

async function jaLogadoRedirecionar() {
  try {
    const resp = await apiRequest("usuario_atual", null, "GET");
    const usuario = resp?.usuario || resp?.dados?.usuario || resp?.dados?.dados?.usuario || null;

    if (resp?.sucesso && usuario?.id) {
      localStorage.setItem("usuario", JSON.stringify(usuario));
      window.location.replace(HOME_PAGE);
      return true;
    }
  } catch {
    // silencioso: se falhar, continua na tela de login
  }
  return false;
}

document.addEventListener("DOMContentLoaded", async () => {
  // ✅ se já tiver sessão válida, não deixa ficar no login
  await jaLogadoRedirecionar();

  const formLogin = document.getElementById("formLogin");
  const msgErro = document.getElementById("msgErro");
  const btnEntrar = document.getElementById("btnEntrar");

  if (!formLogin) {
    logJsError({ origem: "login.js", mensagem: "Formulário #formLogin não encontrado" });
    return;
  }

  formLogin.addEventListener("submit", async (e) => {
    e.preventDefault();
    hideError(msgErro);

    const login = (document.getElementById("login")?.value || "").trim();
    const senha = (document.getElementById("senha")?.value || "");

    if (!login || !senha) {
      showError(msgErro, "Preencha login e senha.");
      return;
    }

    try {
      setLoading(btnEntrar, true);

      // ✅ login via actions (apiRequest já deve enviar cookies/sessão)
      const resp = await apiRequest("login", { login, senha }, "POST");

      if (resp?.sucesso === true) {
        const usuario = resp?.dados?.usuario || resp?.usuario || null;
        if (usuario) localStorage.setItem("usuario", JSON.stringify(usuario));

        logJsInfo({ origem: "login.js", mensagem: "Login OK", usuario: usuario?.nome || login });
        window.location.replace(HOME_PAGE);
        return;
      }

      showError(msgErro, resp?.mensagem || "Usuário ou senha inválidos.");

    } catch (err) {
      showError(msgErro, "Erro de comunicação com o servidor.");
      logJsError({
        origem: "login.js",
        mensagem: err?.message || String(err),
        stack: err?.stack
      });
    } finally {
      setLoading(btnEntrar, false);
    }
  });
});