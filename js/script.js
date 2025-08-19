document.addEventListener("DOMContentLoaded", function () {
    const formAdicionar = document.getElementById("formAdicionar");
    const formEntrada = document.getElementById("formEntrada");
    const formSaida = document.getElementById("formSaida");
    const tabelaProdutos = document
        .getElementById("tabelaProdutos")
        .querySelector("tbody");
    const tabelaRelatorio = document
        .getElementById("tabelaRelatorio")
        .querySelector("tbody");

    // Atualiza lista de produtos
    function atualizarListaProdutos() {
        fetch("actions.php?action=listar")
            .then((res) => res.json())
            .then((produtos) => {
                tabelaProdutos.innerHTML = "";
                produtos.forEach((produto) => {
                    const row = document.createElement("tr");
                    row.innerHTML = `
                        <td>${produto.id}</td>
                        <td>${produto.nome}</td>
                        <td>${produto.quantidade}</td>
                        <td>
                            <button class="btn btn-success btn-sm entrada" data-id="${produto.id}">Entrada</button>
                            <button class="btn btn-warning btn-sm saida" data-id="${produto.id}">Saída</button>
                            <button class="btn btn-danger btn-sm remover" data-id="${produto.id}">Remover</button>
                        </td>
                    `;
                    tabelaProdutos.appendChild(row);
                });
            });
    }

    // Atualiza relatório
    function atualizarRelatorio() {
        fetch("actions.php?action=relatorio")
            .then((res) => res.json())
            .then((movimentacoes) => {
                tabelaRelatorio.innerHTML = "";
                movimentacoes.forEach((mov) => {
                    const row = document.createElement("tr");
                    row.innerHTML = `
                        <td>${mov.id}</td>
                        <td>${mov.produto_nome ?? "(Produto removido)"}</td>
                        <td>${mov.tipo}</td>
                        <td>${mov.quantidade}</td>
                        <td>${mov.data}</td>
                    `;
                    tabelaRelatorio.appendChild(row);
                });
            });
    }

    // Evento adicionar produto
    formAdicionar.addEventListener("submit", function (e) {
        e.preventDefault();
        const formData = new FormData(formAdicionar);
        fetch("actions.php?action=adicionar", {
            method: "POST",
            body: formData,
        })
            .then((res) => res.text())
            .then(() => {
                formAdicionar.reset();
                atualizarListaProdutos();
                atualizarRelatorio();
            });
    });

    // Delegação de eventos para entrada, saída e remover
    tabelaProdutos.addEventListener("click", function (e) {
        if (e.target.classList.contains("entrada")) {
            const id = e.target.dataset.id;
            const quantidade = prompt("Quantidade de entrada:");
            if (quantidade) {
                const formData = new FormData();
                formData.append("id", id);
                formData.append("quantidade", quantidade);
                fetch("actions.php?action=entrada", {
                    method: "POST",
                    body: formData,
                })
                    .then((res) => res.text())
                    .then(() => {
                        atualizarListaProdutos();
                        atualizarRelatorio();
                    });
            }
        }

        if (e.target.classList.contains("saida")) {
            const id = e.target.dataset.id;
            const quantidade = prompt("Quantidade de saída:");
            if (quantidade) {
                const formData = new FormData();
                formData.append("id", id);
                formData.append("quantidade", quantidade);
                fetch("actions.php?action=saida", {
                    method: "POST",
                    body: formData,
                })
                    .then((res) => res.text())
                    .then(() => {
                        atualizarListaProdutos();
                        atualizarRelatorio();
                    });
            }
        }

        if (e.target.classList.contains("remover")) {
            const id = e.target.dataset.id;
            if (confirm("Tem certeza que deseja remover este produto?")) {
                const formData = new FormData();
                formData.append("id", id);
                fetch("actions.php?action=remover", {
                    method: "POST",
                    body: formData,
                })
                    .then((res) => res.text())
                    .then(() => {
                        atualizarListaProdutos();
                        atualizarRelatorio();
                    });
            }
        }
    });

    // Inicializa listas
    atualizarListaProdutos();
    atualizarRelatorio();
});
