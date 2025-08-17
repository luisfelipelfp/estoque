// scripts.js

// Função para abrir modal
function openModal(modalId) {
    document.getElementById(modalId).style.display = "flex";
}

// Função para fechar modal
function closeModal(modalId) {
    document.getElementById(modalId).style.display = "none";
}

// ====================== CADASTRAR PRODUTO ======================
document.getElementById("formAdd").addEventListener("submit", function(e) {
    e.preventDefault();

    let formData = new FormData(this);
    formData.append("action", "add");

    fetch("api/actions.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "sucesso") {
            alert("Produto cadastrado com sucesso!");
            closeModal("modalAdd");
            carregarProdutos();
        } else {
            alert("Erro: " + data.mensagem);
        }
    });
});

// ====================== ENTRADA / SAÍDA PRODUTO ======================
document.getElementById("formMovimentacao").addEventListener("submit", function(e) {
    e.preventDefault();

    let formData = new FormData(this);
    formData.append("action", "movimentacao");

    fetch("api/actions.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "sucesso") {
            alert("Movimentação registrada!");
            closeModal("modalMovimentacao");
            carregarProdutos();
        } else {
            alert("Erro ao registrar movimentação!");
        }
    });
});

// ====================== REMOVER PRODUTO ======================
document.getElementById("formRemove").addEventListener("submit", function(e) {
    e.preventDefault();

    let formData = new FormData(this);
    formData.append("action", "remover");

    fetch("api/actions.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "sucesso") {
            alert("Produto removido com sucesso!");
            closeModal("modalRemove");
            carregarProdutos();
        } else {
            alert("Erro ao remover produto!");
        }
    });
});

// ====================== RELATÓRIO ======================
document.getElementById("btnRelatorio").addEventListener("click", function() {
    fetch("api/actions.php", {
        method: "POST",
        body: new URLSearchParams({ action: "relatorio" })
    })
    .then(res => res.json())
    .then(data => {
        let tabela = document.querySelector("#tabelaRelatorio tbody");
        tabela.innerHTML = "";

        data.forEach(mov => {
            let row = `
                <tr>
                    <td>${mov.id}</td>
                    <td>${mov.nome}</td>
                    <td>${mov.quantidade}</td>
                    <td>${mov.tipo}</td>
                    <td>${mov.data}</td>
                </tr>
            `;
            tabela.innerHTML += row;
        });

        openModal("modalRelatorio");
    });
});

// ====================== LISTAR PRODUTOS NA TELA INICIAL ======================
function carregarProdutos() {
    fetch("api/actions.php", {
        method: "POST",
        body: new URLSearchParams({ action: "listar" })
    })
    .then(res => res.json())
    .then(data => {
        let tabela = document.querySelector("#tabelaProdutos tbody");
        tabela.innerHTML = "";

        let selectEntrada = document.getElementById("produtoEntrada");
        let selectSaida = document.getElementById("produtoSaida");
        let selectRemover = document.getElementById("produtoRemover");

        // Limpa selects antes de recarregar
        selectEntrada.innerHTML = "";
        selectSaida.innerHTML = "";
        selectRemover.innerHTML = "";

        data.forEach(prod => {
            // Atualiza tabela principal
            let row = `
                <tr>
                    <td>${prod.id}</td>
                    <td>${prod.nome}</td>
                    <td>${prod.quantidade}</td>
                </tr>
            `;
            tabela.innerHTML += row;

            // Atualiza selects dos modais
            let option = `<option value="${prod.id}">${prod.nome}</option>`;
            selectEntrada.innerHTML += option;
            selectSaida.innerHTML += option;
            selectRemover.innerHTML += option;
        });
    });
}

// Carrega produtos ao iniciar
document.addEventListener("DOMContentLoaded", carregarProdutos);
