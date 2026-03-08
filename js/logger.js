// js/logger.js
// Logger centralizado do frontend
// Versão sem dependência de endpoint no backend

function montarPayload(payload = {}, nivel = "info") {
  return {
    nivel,
    origem: payload.origem || "frontend",
    mensagem: payload.mensagem || "",
    detalhe: payload.detalhe || null,
    arquivo: payload.arquivo || null,
    linha: payload.linha || null,
    coluna: payload.coluna || null,
    stack: payload.stack || null,
    usuario: payload.usuario || null,
    extra: payload.extra || null,
    pagina: window.location.pathname,
    dataHora: new Date().toISOString(),
  };
}

/**
 * Log de erro JS
 */
export function logJsError(payload = {}) {
  const dados = montarPayload(payload, "error");

  try {
    console.error("[Frontend][ERROR]", dados);
  } catch (_) {
    // não deixa o logger quebrar a aplicação
  }
}

/**
 * Log informativo JS
 */
export function logJsInfo(payload = {}) {
  const dados = montarPayload(payload, "info");

  try {
    console.info("[Frontend][INFO]", dados);
  } catch (_) {
    // não deixa o logger quebrar a aplicação
  }
}