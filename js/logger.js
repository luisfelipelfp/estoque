// js/logger.js

const LOG_ENDPOINT = "/estoque/public/api/log_js.php";

function enviarLog(payload) {
  try {
    fetch(LOG_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        ...payload,
        url: window.location.href,
        userAgent: navigator.userAgent,
        data: new Date().toISOString()
      })
    });
  } catch (e) {
    console.warn("Falha ao enviar log JS:", e);
  }
}

export function logJsError(payload = {}) {
  enviarLog({
    nivel: "ERROR",
    ...payload
  });
}

export function logJsInfo(payload = {}) {
  enviarLog({
    nivel: "INFO",
    ...payload
  });
}

// Erros globais
window.onerror = function (msg, url, linha, coluna, erro) {
  logJsError({
    origem: "window.onerror",
    mensagem: msg,
    arquivo: url,
    linha,
    coluna,
    stack: erro?.stack
  });
};

window.onunhandledrejection = function (event) {
  logJsError({
    origem: "unhandledrejection",
    mensagem: event.reason?.message || event.reason,
    stack: event.reason?.stack
  });
};
