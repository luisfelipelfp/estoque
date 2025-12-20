// js/logger.js

const LOG_ENDPOINT = "/estoque/public/api/log_js.php";

export function logJsError(payload = {}) {
  try {
    fetch(LOG_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        origem: payload.origem || "frontend",
        mensagem: payload.mensagem || "Erro JS",
        arquivo: payload.arquivo || null,
        linha: payload.linha || null,
        coluna: payload.coluna || null,
        stack: payload.stack || null,
        url: window.location.href,
        userAgent: navigator.userAgent,
        data: new Date().toISOString()
      })
    });
  } catch (e) {
    console.warn("Falha ao enviar log JS:", e);
  }
}

// Captura erros globais
window.onerror = function (msg, url, linha, coluna, erro) {
  logJsError({
    origem: "window.onerror",
    mensagem: msg,
    arquivo: url,
    linha,
    coluna,
    stack: erro?.stack || null
  });
};

window.onunhandledrejection = function (event) {
  logJsError({
    origem: "unhandledrejection",
    mensagem: event.reason?.message || event.reason,
    stack: event.reason?.stack || null
  });
};
