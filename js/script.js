// ==========================
// Variáveis globais
// ==========================
const modal = document.getElementById('modal');
const modalTitulo = document.getElementById('modalTitulo');
const modalBody = document.getElementById('modalBody');
const modalBtn = document.getElementById('modalBtn');
const tabelaProdutos = document.getElementById('tabelaProdutos').getElementsByTagName('tbody')[0];

let acaoAtual = '';

// ==========================
// Abrir modal
// ==========================
function abrirModal(acao) {
    acaoAtual = acao;
    modal.style.display = 'block';
    modalBody.innerHTML = '';

    switch(acao) {
        case 'cadastrar':
            modalTitulo.innerText = 'Cadastrar Produto';
            modalBody.innerHTML = `
                <input type="text" id="produtoNome" placeholder="Nome do Produto">
                <input type="number" id="produtoQtd" placeholder="Quantidade Inicial">
            `;
            modalBtn.innerText = 'Cadastrar';
            break;

        case 'entrada':
            modalTitulo.innerText = 'Registrar Entrada';
            modalBody.innerHTML = `
                <select id="produtoSelectEntrada"></select>
                <input type="number" id="produtoQtdEntrada" placeholder="Quantidade">
            `;
            preencherProdutosSelect('produtoSelectEntrada');
            modalBtn.innerText = 'Registrar Entrada';
            break;

        case 'saida':
            modalTitulo.innerText = 'Registrar Saída';
            modalBody.innerHTML = `
                <select id="produtoSelectSaida"></select>
                <input type="number" id="produtoQtdSaida" placeholder="Quantidade">
            `;
            preencherProdutosSelect('produtoSelectSaida');
            modalBtn.innerText = 'Registrar Saída';
            break;

        case 'relatorio':
            modalTitulo.innerText = 'Relatório de Movimentações';
            modalBody.innerHTML = `
                <input type="date" id="dataInicio">
                <input type="date" id="dataFim">
                <div id="relatorioResultado" style="margin-top:10px;"></div>
            `;
            modalBtn.innerText = 'Gerar Relatório';
            break;
    }
}

// ==========================
// Fechar modal
// ==========================
function fecharModal() {
    modal.style.display = 'none';
}

// Fechar modal clicando fora
window.onclick = function(event) {
    if (event.target === modal) {
        fecharModal();
    }
}

// ==========================
// Botão do modal
// ==========================
modalBtn.onclick = function() {
    switch(acaoAtual) {
        case 'cadastrar':
            cadastrarProduto();
            break;
        case 'entrada':
            registrarEntrada();
            break;
        case 'saida':
            registrarSaida();
            break;
        case 'relatorio':
            gerarRelatorio();
            break;
    }
}

// ==========================
// Funções da API
// ==========================
function atualizarTabela() {
    fetch('api/actions.php?acao=listar_produtos')
        .then(res => res.json())
        .then(data => {
            tabelaProdutos.innerHTML = '';
            data.forEach(produto => {
                const row = tabelaProdutos.insertRow();
                row.insertCell(0).innerText = produto.id;
                row.insertCell(1).innerText = produto.nome;
                row.insertCell(2).innerText = produto.quantidade;
                const cellAcoes = row.insertCell(3);
                const btnExcluir = document.createElement('button');
                btnExcluir.innerText = 'Excluir';
                btnExcluir.onclick = () => excluirProduto(produto.id);
                cellAcoes.appendChild(btnExcluir);
            });
        });
}

// Preencher select com produtos
function preencherProdutosSelect(selectId) {
    const select = document.getElementById(selectId);
    fetch('api/actions.php?acao=listar_produtos')
        .then(res => res.json())
        .then(data => {
            select.innerHTML = '';
            data.forEach(prod => {
                const option = document.createElement('option');
                option.value = prod.id;
                option.text = prod.nome;
                select.add(option);
            });
        });
}

// ==========================
// Funções CRUD
// ==========================
function cadastrarProduto() {
    const nome = document.getElementById('produtoNome').value;
    const qtd = document.getElementById('produtoQtd').value;

    fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `acao=cadastrar_produto&nome=${encodeURIComponent(nome)}&quantidade=${encodeURIComponent(qtd)}`
    })
    .then(res => res.json())
    .then(resp => {
        alert(resp.mensagem);
        fecharModal();
        atualizarTabela();
    });
}

function registrarEntrada() {
    const produtoId = document.getElementById('produtoSelectEntrada').value;
    const qtd = document.getElementById('produtoQtdEntrada').value;

    fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `acao=movimentacao&tipo=entrada&produto_id=${produtoId}&quantidade=${qtd}`
    })
    .then(res => res.json())
    .then(resp => {
        alert(resp.mensagem);
        fecharModal();
        atualizarTabela();
    });
}

function registrarSaida() {
    const produtoId = document.getElementById('produtoSelectSaida').value;
    const qtd = document.getElementById('produtoQtdSaida').value;

    fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `acao=movimentacao&tipo=saida&produto_id=${produtoId}&quantidade=${qtd}`
    })
    .then(res => res.json())
    .then(resp => {
        alert(resp.mensagem);
        fecharModal();
        atualizarTabela();
    });
}

function excluirProduto(id) {
    if (!confirm('Deseja realmente excluir este produto?')) return;

    fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `acao=excluir_produto&id=${id}`
    })
    .then(res => res.json())
    .then(resp => {
        alert(resp.mensagem);
        atualizarTabela();
    });
}

function gerarRelatorio() {
    const inicio = document.getElementById('dataInicio').value;
    const fim = document.getElementById('dataFim').value;
    const resultadoDiv = document.getElementById('relatorioResultado');

    fetch(`api/actions.php?acao=relatorio_intervalo&inicio=${inicio}&fim=${fim}`)
        .then(res => res.json())
        .then(data => {
            if(data.length === 0) {
                resultadoDiv.innerHTML = '<p>Nenhuma movimentação encontrada.</p>';
                return;
            }

            let html = '<table><thead><tr><th>Produto</th><th>Tipo</th><th>Quantidade</th><th>Data</th></tr></thead><tbody>';
            data.forEach(mov => {
                html += `<tr>
                            <td>${mov.nome}</td>
                            <td>${mov.tipo}</td>
                            <td>${mov.quantidade}</td>
                            <td>${mov.data}</td>
                         </tr>`;
            });
            html += '</tbody></table>';
            resultadoDiv.innerHTML = html;
        });
}

// ==========================
// Inicialização
// ==========================
window.onload = atualizarTabela;
