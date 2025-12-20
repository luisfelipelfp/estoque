// js/logger.js

const LOG_JS_ENDPOINT = "/estoque/api/log_js.php";

export function logJsError(payload) {
  try {
    fetch(LOG_JS_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        origem: payload.origem || "frontend",
        mensagem: payload.mensagem || "Erro JS",
        arquivo: payload.arquivo || null,
        linha: payload.linha || null,
        coluna: payload.coluna || null,
        stack: payload.stack || null
      })
    });
  } catch (e) {
    console.warn("Falha ao enviar log JS:", e);
  }
}
