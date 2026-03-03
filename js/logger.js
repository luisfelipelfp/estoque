// js/logger.js
// Logger centralizado de erros e informações do frontend

// Base do app (onde o sistema está publicado)
const APP_BASE = "/estoque";

// Endpoint real do logger no backend
const LOG_JS_ENDPOINT = `${APP_BASE}/api/log_js.php`;

/**
 * Log de erro JS
 */
export function logJsError(payload = {}) {
  enviarLog({
    nivel: "error",
    ...payload,
  });
}

/**
 * Log informativo JS
 */
export function logJsInfo(payload = {}) {
  enviarLog({
    nivel: "info",
    ...payload,
  });
}

/**
 * Função interna de envio de logs
 * - Não pode travar a aplicação
 * - Não pode ficar gerando erros em cascata
 */
function enviarLog(payload) {
  const bodyObj = {
    nivel: payload.nivel || "info",
    origem: payload.origem || "frontend",
    mensagem: payload.mensagem || "",
    arquivo: payload.arquivo || null,
    linha: payload.linha || null,
    coluna: payload.coluna || null,
    stack: payload.stack || null,
  };

  try {
    const bodyJson = JSON.stringify(bodyObj);

    // ✅ Melhor opção para logs (não depende do ciclo normal do fetch)
    if (navigator.sendBeacon) {
      const blob = new Blob([bodyJson], { type: "application/json" });
      navigator.sendBeacon(LOG_JS_ENDPOINT, blob);
      return;
    }

    // ✅ Fallback: fetch normal, com sessão e sem cache
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 2500);

    fetch(LOG_JS_ENDPOINT, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        "Cache-Control": "no-cache",
      },
      body: bodyJson,
      signal: controller.signal,
    }).catch(() => {
      // não faz nada para evitar loop de erro
    }).finally(() => clearTimeout(timeout));

  } catch (e) {
    // Nunca pode explodir a app por causa de log
    console.warn("Falha ao enviar log JS:", e);
  }
}