// js/movimentacoes.js

if (!window.__MOVIMENTACOES_JS_BOUND__) {
  window.__MOVIMENTACOES_JS_BOUND__ = true;

  // Lista de movimentações
  async function listarMovimentacoes(filtros = {}) {
    try {
      const resp = await apiRequest("listar_movimentacoes", filtros, "GET");
      const movs = Array.isArray(resp) ? resp : (resp?.dados || resp || []);
      const tbody = document.querySelector("#tabelaMovimentacoes tbody");
      if (!tbody) return;

      tbody.innerHTML = "";

      if (!movs.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td colspan="6" class="text-center">Nenhuma movimentação encontrada</td>`;
        tbody.appendChild(tr);
        return;
      }

      movs.forEach(m => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${m.id}</td>
          <td>${m.produto_nome || m.produto_id}</td>
          <td>${m.tipo}</td>
          <td>${m.quantidade}</td>
          <td>${m.data}</td>
          <td>${m.usuario || ""}</td>
        `;
        tbody.appendChild(tr);
      });
    } catch (err) {
      console.error("Erro ao listar movimentações:", err);
    }
  }

  // 🔑 Expondo para ser usado no produtos.js e main.js
  window.listarMovimentacoes = listarMovimentacoes;

  // Filtro de movimentações
  document.querySelector("#formFiltroMov")?.addEventListener("submit", async function (e) {
    e.preventDefault();
    const produto_id = document.querySelector("#filtroProduto")?.value || "";
    const tipo = document.querySelector("#filtroTipo")?.value || "";
    const data_inicio = document.querySelector("#filtroDataIni")?.value || ""; // corrigido
    const data_fim = document.querySelector("#filtroDataFim")?.value || "";
    const usuario = document.querySelector("#filtroBusca")?.value || ""; // corrigido

    const filtros = {};
    if (produto_id) filtros.produto_id = produto_id;
    if (tipo) filtros.tipo = tipo;
    if (data_inicio) filtros.data_inicio = data_inicio; // nome igual ao PHP
    if (data_fim) filtros.data_fim = data_fim;
    if (usuario) filtros.usuario = usuario; // nome igual ao PHP

    await listarMovimentacoes(filtros);
  });

  // Preenche filtro de produtos dinamicamente
  async function preencherFiltroProdutos() {
    try {
      const resp = await apiRequest("listar_produtos", null, "GET");
      const produtos = Array.isArray(resp) ? resp : (resp?.dados || resp || []);
      const select = document.querySelector("#filtroProduto");
      if (!select) return;

      select.innerHTML = `<option value="">Todos</option>`;
      produtos.forEach(p => {
        const opt = document.createElement("option");
        opt.value = p.id;
        opt.textContent = p.nome;
        select.appendChild(opt);
      });
    } catch (err) {
      console.error("Erro ao preencher filtro de produtos:", err);
    }
  }

  // 🔑 Expondo globalmente para ser usado no produtos.js
  window.preencherFiltroProdutos = preencherFiltroProdutos;

  // Inicialização
  window.addEventListener("DOMContentLoaded", async () => {
    await preencherFiltroProdutos();
    await listarMovimentacoes();
  });
}
