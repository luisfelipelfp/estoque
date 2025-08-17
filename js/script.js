document.addEventListener("DOMContentLoaded", () => {

    const produtosTable = document.getElementById("produtos-table");
    const btnCadastrar = document.getElementById("btn-cadastrar");
    const btnEntradaSaida = document.getElementById("btn-entrada-saida");
    const btnRelatorio = document.getElementById("btn-relatorio");
    const btnRemover = document.getElementById("btn-remover");
    const darkModeToggle = document.getElementById("dark-mode-toggle");

    // Função auxiliar para criar modais
    function criarModal(titulo, conteudo, onConfirm) {
        const modal = document.createElement("div");
        modal.classList.add("modal");
        modal.innerHTML = `
            <div class="modal-content">
                <h2>${titulo}</h2>
                <div class="modal-body">${conteudo}</div>
                <div class="modal-footer">
                    <button class="btn btn-cancel">Cancelar</button>
                    <button class="btn btn-confirm">Confirmar</button>
                </div>
            </div>`;
        document.body.appendChild(modal);

        modal.querySelector(".btn-cancel").onclick = () => modal.remove();
        modal.querySelector(".btn-confirm").onclick = () => { onConfirm(modal); };
    }

    // Função para atualizar tabela de produtos
    function atualizarTabela() {
        fetch("api/actions.php", {
            method: "POST",
            body: new URLSearchParams({ acao: "listar_produtos" })
        })
        .then(res => res.json())
        .then(produtos => {
            produtosTable.innerHTML = `
                <tr>
                    <th>ID</th><th>Nome</th><th>Quantidade</th><th>Ações</th>
                </tr>`;
            produtos.forEach(p => {
                const tr = document.createElement("tr");
                tr.innerHTML = `
                    <td>${p.id}</td>
                    <td>${p.nome}</td>
                    <td>${p.quantidade}</td>
                    <td>
                        <button class="btn btn-entrada" data-id="${p.id}">Entrada</button>
                        <button class="btn btn-saida" data-id="${p.id}">Saída</button>
                        <button class="btn btn-remover" data-id="${p.id}">Remover</button>
                    </td>`;
                produtosTable.appendChild(tr);
            });
            adicionarEventosBotoes();
        });
    }

    // Eventos dos botões na tabela
    function adicionarEventosBotoes() {
        document.querySelectorAll(".btn-entrada").forEach(btn => {
            btn.onclick = () => abrirEntradaSaida(btn.dataset.id, "entrada");
        });
        document.querySelectorAll(".btn-saida").forEach(btn => {
            btn.onclick = () => abrirEntradaSaida(btn.dataset.id, "saida");
        });
        document.querySelectorAll(".btn-remover").forEach(btn => {
            btn.onclick = () => removerProduto(btn.dataset.id);
        });
    }

    // Modal de cadastro
    btnCadastrar.onclick = () => {
        criarModal("Cadastrar Produto",
            `<input type="text" id="nome-produto" placeholder="Nome do produto">
             <input type="number" id="quantidade-produto" placeholder="Quantidade inicial">`,
            (modal) => {
                const nome = modal.querySelector("#nome-produto").value.trim();
                const quantidade = modal.querySelector("#quantidade-produto").value.trim();
                fetch("api/actions.php", {
                    method: "POST",
                    body: new URLSearchParams({
                        acao: "cadastrar_produto",
                        nome,
                        quantidade
                    })
                })
                .then(res => res.json())
                .then(resp => {
                    alert(resp.mensagem);
                    modal.remove();
                    atualizarTabela();
                });
            }
        );
    };

    // Entrada/Saída
    function abrirEntradaSaida(produtoId, tipo) {
        criarModal(tipo === "entrada" ? "Registrar Entrada" : "Registrar Saída",
            `<input type="number" id="quantidade-mov" placeholder="Quantidade">`,
            (modal) => {
                const quantidade = modal.querySelector("#quantidade-mov").value.trim();
                fetch("api/actions.php", {
                    method: "POST",
                    body: new URLSearchParams({
                        acao: "entrada_saida",
                        produto_id: produtoId,
                        quantidade,
                        tipo
                    })
                })
                .then(res => res.json())
                .then(resp => {
                    alert(resp.mensagem);
                    modal.remove();
                    atualizarTabela();
                });
            }
        );
    }

    // Remover produto
    function removerProduto(produtoId) {
        if(!confirm("Deseja realmente remover este produto?")) return;
        fetch("api/actions.php", {
            method: "POST",
            body: new URLSearchParams({
                acao: "remover_produto",
                produto_id: produtoId
            })
        })
        .then(res => res.json())
        .then(resp => {
            alert(resp.mensagem);
            atualizarTabela();
        });
    }

    // Relatório
    btnRelatorio.onclick = () => {
        criarModal("Relatório de Movimentações",
            `<label>Data início: <input type="date" id="data-inicio"></label>
             <label>Data fim: <input type="date" id="data-fim"></label>
             <div id="resultado-relatorio"></div>`,
            (modal) => {
                const inicio = modal.querySelector("#data-inicio").value;
                const fim = modal.querySelector("#data-fim").value;
                fetch("api/actions.php", {
                    method: "POST",
                    body: new URLSearchParams({
                        acao: "relatorio",
                        data_inicio: inicio,
                        data_fim: fim
                    })
                })
                .then(res => res.json())
                .then(movs => {
                    const div = modal.querySelector("#resultado-relatorio");
                    div.innerHTML = "<h3>Movimentações:</h3>";
                    if(movs.length === 0) div.innerHTML += "<p>Nenhuma movimentação encontrada</p>";
                    else {
                        const table = document.createElement("table");
                        table.innerHTML = "<tr><th>Produto</th><th>Tipo</th><th>Quantidade</th><th>Data</th></tr>";
                        movs.forEach(m => {
                            const tr = document.createElement("tr");
                            tr.innerHTML = `<td>${m.nome}</td><td>${m.tipo}</td><td>${m.quantidade}</td><td>${m.data}</td>`;
                            table.appendChild(tr);
                        });
                        div.appendChild(table);
                    }
                });
            }
        );
    };

    // Dark/Light Mode
    darkModeToggle.onclick = () => {
        document.body.classList.toggle("dark-mode");
    };

    // Inicializa tabela
    atualizarTabela();

});
