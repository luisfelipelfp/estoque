// js/logger.js
// Logger centralizado de erros e informações do frontend

// Endpoint real da API (NGINX root)
const LOG_JS_ENDPOINT = "/api/log_js.php";

/**
 * Log de erro JS
 */
export function logJsError(payload = {}) {
  enviarLog({
    nivel: "error",
    ...payload
  });
}

/**
 * Log informativo JS
 */
export function logJsInfo(payload = {}) {
  enviarLog({
    nivel: "info",
    ...payload
  });
}

/**
 * Função interna de envio de logs
 */
function enviarLog(payload) {
  try {
    fetch(LOG_JS_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        nivel: payload.nivel || "info",
        origem: payload.origem || "frontend",
        mensagem: payload.mensagem || "",
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
