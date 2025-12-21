// js/produtos.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

const inflight = new Set();

/**
 * Lista produtos
 */
async function listarProdutos() {
  try {
    const resp = await apiRequest("listar_produtos", null, "GET");

    const produtos =
      Array.isArray(resp?.dados?.produtos) ? resp.dados.produtos :
      Array.isArray(resp?.dados) ? resp.dados :
      Array.isArray(resp) ? resp :
      [];

    const tbody = document.querySelector("#tabelaProdutos tbody");
    if (!tbody) return;

    tbody.innerHTML = "";

    if (!produtos.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="4" class="text-center">
            Nenhum produto encontrado
          </td>
        </tr>`;
      return;
    }

    produtos.forEach(p => {
      const nome =
        p?.nome?.trim?.() ||
        p?.produto_nome?.trim?.() ||
        p?.nome_produto?.trim?.() ||
        p?.produto?.trim?.() ||
        "[Sem nome]";

      const quantidade = Number(p?.quantidade ?? p?.qtd ?? 0);

      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${p.id ?? "-"}</td>
        <td>${nome}</td>
        <td>${quantidade}</td>
        <td class="d-flex gap-2">
          <button class="btn btn-success btn-sm" onclick="entrada(${p.id})">Entrada</button>
          <button class="btn btn-warning btn-sm" onclick="saida(${p.id})">Saída</button>
          <button class="btn btn-danger btn-sm" onclick="remover(${p.id})">Remover</button>
        </td>
      `;
      tbody.appendChild(tr);
    });

    logJsInfo({
      origem: "produtos.js",
      mensagem: "Produtos listados",
      total: produtos.length
    });

  } catch (err) {
    console.error("Erro ao listar produtos:", err);

    logJsError({
      origem: "produtos.js",
      mensagem: "Falha ao listar produtos",
      detalhe: err.message,
      stack: err.stack
    });

    alert("Erro ao carregar produtos. Verifique o servidor.");
  }
}

/**
 * Executa ação (entrada, saída, remover)
 */
async function execAcao(acao, id, quantidade = null) {
  const key = `${acao}-${id}`;
  if (inflight.has(key)) {
    return { sucesso: false, mensagem: "Aguarde a conclusão da ação anterior." };
  }

  inflight.add(key);

  const rowBtns = document.querySelectorAll(`button[onclick*="(${id})"]`);
  rowBtns.forEach(b => (b.disabled = true));

  try {
    if (acao === "entrada" || acao === "saida") {
      return await apiRequest(
        "registrar_movimentacao",
        { produto_id: id, tipo: acao, quantidade },
        "POST"
      );
    }

    if (acao === "remover") {
      return await apiRequest(
        "remover_produto",
        { produto_id: id },
        "POST"
      );
    }

    return { sucesso: false, mensagem: "Ação inválida." };

  } catch (err) {
    console.error(`Erro em ${acao}:`, err);

    logJsError({
      origem: "produtos.js",
      mensagem: `Falha na ação ${acao}`,
      detalhe: err.message,
      stack: err.stack,
      produto_id: id
    });

    return { sucesso: false, mensagem: "Erro de comunicação com o servidor." };

  } finally {
    inflight.delete(key);
    rowBtns.forEach(b => (b.disabled = false));
  }
}

/**
 * Entrada de produto
 */
async function entrada(id) {
  const qtd = prompt("Quantidade de entrada:");
  if (qtd === null) return;

  const quantidade = Number(qtd);
  if (!Number.isFinite(quantidade) || quantidade <= 0) {
    alert("Quantidade inválida.");
    return;
  }

  const resp = await execAcao("entrada", id, quantidade);

  if (resp?.sucesso) {
    alert(resp.mensagem || "Entrada registrada com sucesso.");
    await listarProdutos();
    if (typeof window.listarMovimentacoes === "function") {
      await window.listarMovimentacoes();
    }
  } else {
    alert(resp?.mensagem || "Erro ao registrar entrada.");
  }
}

/**
 * Saída de produto
 */
async function saida(id) {
  const qtd = prompt("Quantidade de saída:");
  if (qtd === null) return;

  const quantidade = Number(qtd);
  if (!Number.isFinite(quantidade) || quantidade <= 0) {
    alert("Quantidade inválida.");
    return;
  }

  const resp = await execAcao("saida", id, quantidade);

  if (resp?.sucesso) {
    alert(resp.mensagem || "Saída registrada com sucesso.");
    await listarProdutos();
    if (typeof window.listarMovimentacoes === "function") {
      await window.listarMovimentacoes();
    }
  } else {
    alert(resp?.mensagem || "Erro ao registrar saída.");
  }
}

/**
 * Remover produto
 */
async function remover(id) {
  if (!confirm("Tem certeza que deseja remover este produto?")) return;

  const resp = await execAcao("remover", id);

  if (resp?.sucesso) {
    alert(resp.mensagem || "Produto removido com sucesso.");
    await listarProdutos();
    if (typeof window.listarMovimentacoes === "function") {
      await window.listarMovimentacoes();
    }
  } else {
    alert(resp?.mensagem || "Erro ao remover produto.");
  }
}

/**
 * Formulário: adicionar produto
 */
document.querySelector("#formAdicionarProduto")
  ?.addEventListener("submit", async function (e) {
    e.preventDefault();

    const nome = (document.querySelector("#nomeProduto")?.value || "").trim();
    if (!nome) {
      alert("Informe o nome do produto.");
      return;
    }

    try {
      const resp = await apiRequest(
        "adicionar_produto",
        { nome, quantidade: 0 },
        "POST"
      );

      if (resp?.sucesso) {
        this.reset();
        alert(resp.mensagem || "Produto adicionado com sucesso.");
        await listarProdutos();
        if (typeof window.preencherFiltroProdutos === "function") {
          await window.preencherFiltroProdutos();
        }
      } else {
        alert(resp?.mensagem || "Erro ao adicionar produto.");
      }

    } catch (err) {
      console.error("Erro ao adicionar produto:", err);

      logJsError({
        origem: "produtos.js",
        mensagem: "Falha ao adicionar produto",
        detalhe: err.message,
        stack: err.stack
      });

      alert("Erro de comunicação com o servidor.");
    }
  });

/**
 * Inicialização
 */
document.addEventListener("DOMContentLoaded", () => {
  listarProdutos();
});

/**
 * 🔑 Exposição mínima necessária (HTML inline)
 */
window.listarProdutos = listarProdutos;
window.entrada = entrada;
window.saida = saida;
window.remover = remover;
