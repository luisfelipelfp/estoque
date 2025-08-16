const modal = document.getElementById("modal");
const modalBody = document.getElementById("modal-body");

function abrirModal(acao) {
    modal.style.display = "flex";
    modalBody.innerHTML = "";

    if (acao === "cadastrar") {
        modalBody.innerHTML = `
            <h2>Cadastrar Produto</h2>
            <input type="text" id="nomeProduto" placeholder="Nome do produto">
            <input type="number" id="quantidadeProduto" placeholder="Quantidade inicial">
            <button onclick="cadastrarProduto()">Cadastrar</button>
        `;
    } else if (acao === "entrada") {
        carregarProdutos("entrada");
    } else if (acao === "saida") {
        carregarProdutos("saida");
    } else if (acao === "relatorio") {
        modalBody.innerHTML = `
            <h2>Relatório por Data</h2>
            <label>Data Inicial:</label>
            <input type="date" id="dataInicio">
            <label>Data Final:</label>
            <input type="date" id="dataFim">
            <button onclick="gerarRelatorio()">Gerar</button>
            <div id="resultadoRelatorio"></div>
        `;
    }
}

function fecharModal() {
    modal.style.display = "none";
}

// Função para cadastrar produto
function cadastrarProduto() {
    const nome = document.getElementById("nomeProduto").value;
    const quantidade = parseInt(document.getElementById("quantidadeProduto").value);

    if (!nome || isNaN(quantidade)) {
        alert("Preencha todos os campos corretamente!");
        return;
    }

    fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `acao=cadastrar_produto&nome=${encodeURIComponent(nome)}&quantidade=${quantidade}`
    })
    .then(res => res.json())
    .then(data => {
        alert(data.mensagem);
        if (data.sucesso) fecharModal();
    });
}

// Carregar produtos para entrada ou saída
function carregarProdutos(tipo) {
    fetch('api/actions.php?acao=listar_produtos')
        .then(res => res.json())
        .then(produtos => {
            let html = `<h2>${tipo === 'entrada' ? 'Entrada' : 'Saída'} de Produto</h2>`;
            html += `<select id="produtoSelect">`;
            produtos.forEach(p => html += `<option value="${p.id}">${p.nome} (Qtd: ${p.quantidade})</option>`);
            html += `</select>`;
            html += `<input type="number" id="quantidadeMov" placeholder="Quantidade">`;
            html += `<button onclick="registrarMovimentacao('${tipo}')">Confirmar</button>`;
            modalBody.innerHTML = html;
        });
}

// Registrar entrada ou saída
function registrarMovimentacao(tipo) {
    const produtoId = document.getElementById("produtoSelect").value;
    const quantidade = parseInt(document.getElementById("quantidadeMov").value);

    if (!produtoId || isNaN(quantidade) || quantidade <= 0) {
        alert("Quantidade inválida!");
        return;
    }

    fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `acao=movimentacao&produto_id=${produtoId}&tipo=${tipo}&quantidade=${quantidade}`
    })
    .then(res => res.json())
    .then(data => {
        alert(data.mensagem);
        if (data.sucesso) fecharModal();
    });
}

// Gerar relatório por data
function gerarRelatorio() {
    const inicio = document.getElementById("dataInicio").value;
    const fim = document.getElementById("dataFim").value;

    if (!inicio || !fim) {
        alert("Selecione as datas!");
        return;
    }

    fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `acao=relatorio_intervalo&inicio=${inicio}&fim=${fim}`
    })
    .then(res => res.json())
    .then(dados => {
        if (dados.length === 0) {
            document.getElementById("resultadoRelatorio").innerHTML = "<p>Nenhuma movimentação encontrada.</p>";
            return;
        }
        let tabela = `<table><tr><th>Produto</th><th>Tipo</th><th>Quantidade</th><th>Data</th></tr>`;
        dados.forEach(m => {
            tabela += `<tr>
                <td>${m.nome}</td>
                <td>${m.tipo}</td>
                <td>${m.quantidade}</td>
                <td>${m.data}</td>
            </tr>`;
        });
        tabela += "</table>";
        document.getElementById("resultadoRelatorio").innerHTML = tabela;
    });
}