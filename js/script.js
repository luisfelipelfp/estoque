document.addEventListener("DOMContentLoaded", () => {
    const listaProdutos = document.getElementById("listaProdutos");
    const formAdd = document.getElementById("formAdd");
    const formEntrada = document.getElementById("formEntrada");
    const formSaida = document.getElementById("formSaida");

    // Função para carregar lista de produtos
    function carregarProdutos() {
        fetch("actions.php?action=list")
            .then(res => res.json())
            .then(data => {
                listaProdutos.innerHTML = "";
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach(produto => {
                        const li = document.createElement("li");
                        li.textContent = `${produto.nome} - Quantidade: ${produto.quantidade}`;
                        li.innerHTML += ` <button class="remover" data-id="${produto.id}">Remover</button>`;
                        listaProdutos.appendChild(li);
                    });
                } else {
                    listaProdutos.innerHTML = "<li>Nenhum produto cadastrado</li>";
                }
            })
            .catch(err => console.error("Erro ao carregar produtos:", err));
    }

    // Adicionar produto
    formAdd.addEventListener("submit", e => {
        e.preventDefault();
        const formData = new FormData(formAdd);
        formData.append("action", "add");

        fetch("actions.php", { method: "POST", body: formData })
            .then(res => res.json())
            .then(data => {
                alert(data.success || data.error);
                carregarProdutos();
                formAdd.reset();
            });
    });

    // Entrada de produto
    formEntrada.addEventListener("submit", e => {
        e.preventDefault();
        const formData = new FormData(formEntrada);
        formData.append("action", "entrada");

        fetch("actions.php", { method: "POST", body: formData })
            .then(res => res.json())
            .then(data => {
                alert(data.success || data.error);
                carregarProdutos();
                formEntrada.reset();
            });
    });

    // Saída de produto
    formSaida.addEventListener("submit", e => {
        e.preventDefault();
        const formData = new FormData(formSaida);
        formData.append("action", "saida");

        fetch("actions.php", { method: "POST", body: formData })
            .then(res => res.json())
            .then(data => {
                alert(data.success || data.error);
                carregarProdutos();
                formSaida.reset();
            });
    });

    // Remover produto
    listaProdutos.addEventListener("click", e => {
        if (e.target.classList.contains("remover")) {
            const produto_id = e.target.dataset.id;
            const formData = new FormData();
            formData.append("action", "remove");
            formData.append("produto_id", produto_id);

            fetch("actions.php", { method: "POST", body: formData })
                .then(res => res.json())
                .then(data => {
                    alert(data.success || data.error);
                    carregarProdutos();
                });
        }
    });

    // Carregar produtos ao abrir página
    carregarProdutos();
});
