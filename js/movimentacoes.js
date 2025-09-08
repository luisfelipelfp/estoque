// js/movimentacoes.js

if (!window.__MOVIMENTACOES_JS_BOUND__) {
  window.__MOVIMENTACOES_JS_BOUND__ = true;

  let paginaAtual = 1;
  const limitePorPagina = 10;

  // Lista de movimentações
  async function listarMovimentacoes(filtros = {}, pagina = 1) {
    try {
      filtros.pagina = pagina;
      filtros.limite = limitePorPagina;

      const resp = await apiRequest("listar_movimentacoes", filtros, "GET");
      const movs = Array.isArray(resp) ? resp : (resp?.dados || resp || []);
      const total = resp?.total || movs.length; // backend pode enviar total

      const tbody = document.querySelector("#tabelaMovimentacoes tbody");
      if (!tbody) return;

      tbody.innerHTML = "";

      if (!movs.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td colspan="6" class="text-center">Nenhuma movimentação encontrada</td>`;
        tbody.appendChild(tr);
        document.querySelector("#paginacaoMovs").innerHTML = "";
        return;
      }

      movs.forEach(m => {
        const tr = document.createElement("tr");

        // Define classe por tipo
        let tipoClass =
          m.tipo === "entrada"
            ? "text-success fw-bold"
            : m.tipo === "saida"
            ? "text-danger fw-bold"
            : "text-muted fw-bold";

        // Corrigido campo do usuário (aceita usuario_nome ou usuario)
        tr.innerHTML = `
          <td>${m.id}</td>
          <td>${m.produto_nome || m.produto_id}</td>
          <td class="${tipoClass}">${m.tipo}</td>
          <td>${m.quantidade}</td>
          <td>${m.data}</td>
          <td>${m.usuario_nome || m.usuario || ""}</td>
        `;
        tbody.appendChild(tr);
      });

      // Paginação
      renderizarPaginacao(total, pagina);
    } catch (err) {
      console.error("Erro ao listar movimentações:", err);
    }
  }

  // Renderiza os botões de paginação
  function renderizarPaginacao(total, pagina) {
    const divPag = document.querySelector("#paginacaoMovs");
    if (!divPag) return;

    const totalPaginas = Math.ceil(total / limitePorPagina);
    if (totalPaginas <= 1) {
      divPag.innerHTML = "";
      return;
    }

    let html = `<nav><ul class="pagination justify-content-center">`;

    // Botão Anterior
    html += `<li class="page-item ${pagina <= 1 ? "disabled" : ""}">
      <button class="page-link" data-pagina="${pagina - 1}">Anterior</button>
    </li>`;

    // Números
    for (let p = 1; p <= totalPaginas; p++) {
      html += `<li class="page-item ${p === pagina ? "active" : ""}">
        <button class="page-link" data-pagina="${p}">${p}</button>
      </li>`;
    }

    // Botão Próximo
    html += `<li class="page-item ${pagina >= totalPaginas ? "disabled" : ""}">
      <button class="page-link" data-pagina="${pagina + 1}">Próximo</button>
    </li>`;

    html += `</ul></nav>`;
    divPag.innerHTML = html;

    // Eventos
    divPag.querySelectorAll("button[data-pagina]").forEach(btn => {
      btn.addEventListener("click", async () => {
        const novaPagina = parseInt(btn.dataset.pagina);
        if (novaPagina !== pagina && novaPagina > 0 && novaPagina <= totalPaginas) {
          paginaAtual = novaPagina;
          await listarMovimentacoes(getFiltrosAtuais(), paginaAtual);
        }
      });
    });
  }

  // Captura filtros do formulário
  function getFiltrosAtuais() {
    const produto_id = document.querySelector("#filtroProduto")?.value || "";
    const tipo = document.querySelector("#filtroTipo")?.value || "";
    const data_inicio = document.querySelector("#filtroDataInicio")?.value || "";
    const data_fim = document.querySelector("#filtroDataFim")?.value || "";

    const filtros = {};
    if (produto_id) filtros.produto_id = produto_id;
    if (tipo) filtros.tipo = tipo;
    if (data_inicio) filtros.data_inicio = data_inicio;
    if (data_fim) filtros.data_fim = data_fim;

    return filtros;
  }

  // 🔑 Expondo para ser usado em outros scripts
  window.listarMovimentacoes = listarMovimentacoes;

  // Filtro de movimentações
  document.querySelector("#formFiltrosMovimentacoes")?.addEventListener("submit", async function (e) {
    e.preventDefault();
    paginaAtual = 1; // sempre reinicia da primeira página ao aplicar filtro
    await listarMovimentacoes(getFiltrosAtuais(), paginaAtual);
  });

  // Preenche filtro de produtos dinamicamente
  async function preencherFiltroProdutos() {
    try {
      const resp = await apiRequest("listar_produtos", null, "GET");
      const produtos = Array.isArray(resp) ? resp : (resp?.dados || resp || []);
      const select = document.querySelector("#filtroProduto");
      if (!select) return;

      select.innerHTML = `<option value="">Todos os Produtos</option>`;
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

  // 🔑 Expondo globalmente
  window.preencherFiltroProdutos = preencherFiltroProdutos;

  // Inicialização
  window.addEventListener("DOMContentLoaded", async () => {
    await preencherFiltroProdutos();
    await listarMovimentacoes({}, paginaAtual);
  });
}
