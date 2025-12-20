import { apiRequest } from "./api.js";
import { logJsError, logJsInfo } from "./logger.js";

let paginaAtual = 1;
const limitePorPagina = 10;

async function listarMovimentacoes(filtros = {}, pagina = 1) {
  try {
    const tbody = document.querySelector("#tabelaMovimentacoes tbody");
    if (!tbody) return;

    if (!Object.keys(filtros).length) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted">
        Use os filtros para buscar movimentações
      </td></tr>`;
      return;
    }

    filtros.pagina = pagina;
    filtros.limite = limitePorPagina;

    const resp = await apiRequest("listar_movimentacoes", filtros);

    tbody.innerHTML = "";

    (resp?.dados || []).forEach(m => {
      tbody.insertAdjacentHTML("beforeend", `
        <tr>
          <td>${m.id}</td>
          <td>${m.produto_nome}</td>
          <td>${m.tipo}</td>
          <td>${m.quantidade}</td>
          <td>${m.data}</td>
          <td>${m.usuario}</td>
        </tr>
      `);
    });

  } catch (err) {
    logJsError({
      origem: "movimentacoes.js",
      mensagem: err.message,
      stack: err.stack
    });
  }
}

window.listarMovimentacoes = listarMovimentacoes;
