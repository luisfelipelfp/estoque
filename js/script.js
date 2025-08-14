// js/script.js
document.addEventListener("DOMContentLoaded", () => {
  const modalEl = document.getElementById("modalForm");
  const modal = new bootstrap.Modal(modalEl);
  const modalTitle = document.getElementById("modalTitle");
  const modalBody = document.getElementById("modalBody");
  const modalConfirm = document.getElementById("modalConfirm");

  const tabelaBody = document.querySelector("#tabelaProdutos tbody");

  async function fetchProdutos() {
    try {
      const res = await fetch(`${API_BASE}/getProdutos.php`);
      const data = await res.json();
      tabelaBody.innerHTML = "";
      data.forEach(p => {
        tabelaBody.insertAdjacentHTML("beforeend",
          `<tr><td>${p.id}</td><td>${escapeHtml(p.nome)}</td><td>${p.quantidade}</td></tr>`);
      });
    } catch (err) {
      alert("Erro ao buscar produtos: " + err);
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[m]));
  }

  // Reuso do modal
  function abrirModal(titulo, html, onConfirm) {
    modalTitle.innerText = titulo;
    modalBody.innerHTML = html;
    modalConfirm.onclick = async () => {
      await onConfirm();
    };
    modal.show();
  }

  // Ações
  document.getElementById("btnAdd").addEventListener("click", () => {
    abrirModal("Adicionar Produto",
      `<label>Nome</label><input id="nome" class="form-control mb-2" />
       <label>Quantidade</label><input id="quant" type="number" class="form-control" value="0" />`,
      async () => {
        const nome = document.getElementById("nome").value.trim();
        const quantidade = parseInt(document.getElementById("quant").value) || 0;
        const res = await fetch(`${API_BASE}/add.php`, {
          method: "POST",
          headers: {"Content-Type":"application/json"},
          body: JSON.stringify({nome, quantidade})
        });
        const data = await res.json();
        if (!res.ok) alert(data.error || "Erro");
        else { modal.hide(); fetchProdutos(); }
      });
  });

  document.getElementById("btnBuscar").addEventListener("click", () => {
    abrirModal("Buscar Produto",
      `<label>Nome ou ID</label><input id="q" class="form-control" />`,
      async () => {
        const q = document.getElementById("q").value.trim();
        if (!q) { alert("Informe Nome ou ID"); return; }
        const res = await fetch(`${API_BASE}/buscar.php?q=${encodeURIComponent(q)}`);
        const data = await res.json();
        modal.hide();
        if (!data) alert("Produto não encontrado");
        else alert(`ID: ${data.id}\nNome: ${data.nome}\nQuantidade: ${data.quantidade}`);
      });
  });

  document.getElementById("btnEntrada").addEventListener("click", () => {
    abrirModal("Entrada (adicionar quantidade)",
      `<label>ID</label><input id="id" type="number" class="form-control mb-2" />
       <label>Quantidade</label><input id="qtd" type="number" class="form-control" value="1" />`,
      async () => {
        const id = parseInt(document.getElementById("id").value);
        const quantidade = parseInt(document.getElementById("qtd").value);
        const res = await fetch(`${API_BASE}/entrada.php`, {
          method: "POST",
          headers: {"Content-Type":"application/json"},
          body: JSON.stringify({id, quantidade})
        });
        const data = await res.json();
        if (!res.ok) alert(data.error || "Erro");
        else { modal.hide(); fetchProdutos(); }
      });
  });

  document.getElementById("btnSaida").addEventListener("click", () => {
    abrirModal("Saída (remover quantidade)",
      `<label>ID</label><input id="id" type="number" class="form-control mb-2" />
       <label>Quantidade</label><input id="qtd" type="number" class="form-control" value="1" />`,
      async () => {
        const id = parseInt(document.getElementById("id").value);
        const quantidade = parseInt(document.getElementById("qtd").value);
        const res = await fetch(`${API_BASE}/saida.php`, {
          method: "POST",
          headers: {"Content-Type":"application/json"},
          body: JSON.stringify({id, quantidade})
        });
        const data = await res.json();
        if (!res.ok) alert(data.error || "Erro");
        else { modal.hide(); fetchProdutos(); }
      });
  });

  document.getElementById("btnExcluir").addEventListener("click", () => {
    abrirModal("Excluir produto",
      `<label>ID</label><input id="id" type="number" class="form-control" />`,
      async () => {
        const id = parseInt(document.getElementById("id").value);
        if (!confirm("Confirma exclusão do produto ID " + id + " ?")) return;
        const res = await fetch(`${API_BASE}/excluir.php`, {
          method: "POST",
          headers: {"Content-Type":"application/json"},
          body: JSON.stringify({id})
        });
        const data = await res.json();
        if (!res.ok) alert(data.error || "Erro");
        else { modal.hide(); fetchProdutos(); }
      });
  });

  document.getElementById("btnRefresh").addEventListener("click", fetchProdutos);

  // inicial
  fetchProdutos();
});
window.onload = () => {
  // Carrega inicialmente
  fetchProdutos();

  // Atualiza automaticamente a cada 5 segundos
  setInterval(fetchProdutos, 5000);
};
