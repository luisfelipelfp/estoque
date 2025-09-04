// js/produtos.js

// Evita registrar eventos 2x se o script for incluído mais de uma vez
if (!window.__PRODUTOS_JS_BOUND__) {
  window.__PRODUTOS_JS_BOUND__ = true;

  // Controle de chamadas em andamento (trava por produto/ação)
  const inflight = new Set(); // ex: "entrada-5", "saida-5", "remover-5"

  // Lista de produtos
  async function listarProdutos() {
    try {
      const resp = await apiRequest("listar_produtos", null, "GET");
      const produtos = Array.isArray(resp) ? resp : (resp?.dados || resp || []);
      const tbody = document.querySelector("#tabelaProdutos tbody");
      if (!tbody) return;

      tbody.innerHTML = "";

      if (!produtos.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td colspan="4" class="text-center">Nenhum produto encontrado</td>`;
        tbody.appendChild(tr);
        return;
      }

      produtos.forEach(p => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${p.id}</td>
          <td>${p.nome}</td>
          <td>${p.quantidade}</td>
          <td class="d-flex gap-2">
            <button class="btn btn-success btn-sm btn-entrada" data-id="${p.id}">Entrada</button>
            <button class="btn btn-warning btn-sm btn-saida" data-id="${p.id}">Saída</button>
            <button class="btn btn-danger btn-sm btn-remover" data-id="${p.id}">Remover</button>
          </td>
        `;
        tbody.appendChild(tr);
      });
    } catch (err) {
      console.error("Erro ao listar produtos:", err);
    }
  }

  async function execAcao(acao, id, quantidade) {
    const key = `${acao}-${id}`;
    if (inflight.has(key)) {
      // já existe uma requisição em curso para esse botão/produto
      return { sucesso: false, mensagem: "Aguarde a conclusão da ação anterior." };
    }
    inflight.add(key);

    // desabilita temporariamente os botões do produto
    const rowBtns = document.querySelectorAll(`button[data-id="${id}"]`);
    rowBtns.forEach(b => b.disabled = true);

    try {
      const payload = quantidade != null ? { id, quantidade } : { id };
      // IMPORTANTE: usar POST para ações que alteram estado
      const resp = await apiRequest(acao, payload, "POST");
      return resp;
    } catch (err) {
      console.error(`Erro em ${acao}:`, err);
      return { sucesso: false, mensagem: "Erro de comunicação." };
    } finally {
      inflight.delete(key);
      rowBtns.forEach(b => b.disabled = false);
    }
  }

  // --- Funções globais (para usar em onclicks, se necessário) ---
  window.entrada = async function (id) {
    const qtd = prompt("Quantidade de entrada:");
    if (qtd === null) return;
    const quantidade = parseInt(qtd, 10);
    if (!Number.isFinite(quantidade) || quantidade <= 0) {
      alert("Quantidade inválida.");
      return;
    }

    const resp = await execAcao("entrada", id, quantidade);
    if (resp?.sucesso) {
      alert(resp.mensagem || "Entrada registrada.");
      await listarProdutos();
      if (typeof listarMovimentacoes === "function") await listarMovimentacoes();
    } else if (resp) {
      alert(resp.mensagem || "Erro ao registrar entrada.");
    }
  };

  window.saida = async function (id) {
    const qtd = prompt("Quantidade de saída:");
    if (qtd === null) return;
    const quantidade = parseInt(qtd, 10);
    if (!Number.isFinite(quantidade) || quantidade <= 0) {
      alert("Quantidade inválida.");
      return;
    }

    const resp = await execAcao("saida", id, quantidade);
    if (resp?.sucesso) {
      alert(resp.mensagem || "Saída registrada.");
      await listarProdutos();
      if (typeof listarMovimentacoes === "function") await listarMovimentacoes();
    } else if (resp) {
      alert(resp.mensagem || "Erro ao registrar saída.");
    }
  };

  window.remover = async function (id) {
    if (!confirm("Tem certeza que deseja remover este produto?")) return;

    const resp = await execAcao("remover", id);
    if (resp?.sucesso) {
      alert(resp.mensagem || "Produto removido.");
      await listarProdutos();
      if (typeof listarMovimentacoes === "function") await listarMovimentacoes();
    } else if (resp) {
      alert(resp.mensagem || "Erro ao remover produto.");
    }
  };

  // --- Event delegation (registrado uma única vez) ---
  document.addEventListener("click", function (e) {
    const btn = e.target.closest("button");
    if (!btn) return;

    const id = btn.dataset.id;
    if (!id) return;

    if (btn.classList.contains("btn-entrada")) {
      window.entrada(parseInt(id, 10));
    } else if (btn.classList.contains("btn-saida")) {
      window.saida(parseInt(id, 10));
    } else if (btn.classList.contains("btn-remover")) {
      window.remover(parseInt(id, 10));
    }
  }, { once: false }); // o guard global acima impede múltiplos bindings

  // --- Formulário de adicionar produto (usa POST) ---
  document.querySelector("#formAdicionarProduto")?.addEventListener("submit", async function (e) {
    e.preventDefault();
    const nome = (document.querySelector("#nomeProduto")?.value || "").trim();
    if (!nome) {
      alert("Informe o nome do produto.");
      return;
    }
    try {
      const resp = await apiRequest("adicionar", { nome, quantidade: 0 }, "POST");
      if (resp?.sucesso) {
        this.reset();
        await listarProdutos();
        if (typeof preencherFiltroProdutos === "function") await preencherFiltroProdutos();
      } else {
        alert(resp?.mensagem || "Erro ao adicionar produto.");
      }
    } catch (err) {
      console.error("Erro ao adicionar produto:", err);
      alert("Erro de comunicação.");
    }
  });

  // Inicialização
  window.addEventListener("DOMContentLoaded", () => {
    listarProdutos();
  });
}
