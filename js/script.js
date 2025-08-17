function abrirModal(acao) {
    const modal = document.getElementById('modal');
    const titulo = document.getElementById('modal-titulo');
    const conteudo = document.getElementById('modal-conteudo');

    conteudo.innerHTML = '';
    
    if (acao === 'cadastrar') {
        titulo.textContent = 'Cadastrar Produto';
        conteudo.innerHTML = `
            <input type="text" id="nomeProduto" placeholder="Nome do produto">
            <input type="number" id="qtdProduto" placeholder="Quantidade">
            <button onclick="cadastrarProduto()">Cadastrar</button>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
    } else if (acao === 'entrada') {
        titulo.textContent = 'Entrada de Produto';
        conteudo.innerHTML = `
            <input type="text" id="nomeProduto" placeholder="Nome do produto">
            <input type="number" id="qtdProduto" placeholder="Quantidade">
            <button onclick="entradaProduto()">Registrar Entrada</button>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
    } else if (acao === 'saida') {
        titulo.textContent = 'Saída de Produto';
        conteudo.innerHTML = `
            <input type="text" id="nomeProduto" placeholder="Nome do produto">
            <input type="number" id="qtdProduto" placeholder="Quantidade">
            <button onclick="saidaProduto()">Registrar Saída</button>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
    } else if (acao === 'remover') {
        titulo.textContent = 'Remover Produto';
        conteudo.innerHTML = `
            <input type="text" id="nomeProduto" placeholder="Nome do produto">
            <button onclick="removerProduto()">Remover</button>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
    } else if (acao === 'relatorio') {
        titulo.textContent = 'Relatório de Movimentações';
        conteudo.innerHTML = `
            <form id="formRelatorio">
                <input type="date" id="inicio" required>
                <input type="date" id="fim" required>
                <button type="submit">Gerar Relatório</button>
            </form>
            <div id="resultadoRelatorio"></div>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;

        document.getElementById('formRelatorio').addEventListener('submit', function(e) {
            e.preventDefault();
            gerarRelatorio();
        });
    }

    modal.style.display = 'flex';
}

function fecharModal() {
    const modal = document.getElementById('modal');
    modal.style.display = 'none';
}

function atualizarTabela() {
    fetch('actions.php', {
        method: 'POST',
        body: JSON.stringify({ acao: 'listar' })
    })
    .then(res => res.json())
    .then(data => {
        const tbody = document.querySelector('#tabelaProdutos tbody');
        tbody.innerHTML = '';
        data.forEach(prod => {
            tbody.innerHTML += `<tr><td>${prod.nome}</td><td>${prod.quantidade}</td></tr>`;
        });
    });
}

// Funções de ações
function cadastrarProduto() {
    const nome = document.getElementById('nomeProduto').value;
    const qtd = document.getElementById('qtdProduto').value;

    fetch('actions.php', {
        method: 'POST',
        body: JSON.stringify({ acao: 'cadastrar', nome, qtd })
    })
    .then(res => res.json())
    .then(data => {
        if(data.sucesso) {
            atualizarTabela();
            fecharModal();
        } else alert(data.erro);
    });
}

function entradaProduto() {
    const nome = document.getElementById('nomeProduto').value;
    const qtd = document.getElementById('qtdProduto').value;

    fetch('actions.php', {
        method: 'POST',
        body: JSON.stringify({ acao: 'entrada', nome, qtd })
    })
    .then(res => res.json())
    .then(data => {
        if(data.sucesso) {
            atualizarTabela();
            fecharModal();
        } else alert(data.erro);
    });
}

function saidaProduto() {
    const nome = document.getElementById('nomeProduto').value;
    const qtd = document.getElementById('qtdProduto').value;

    fetch('actions.php', {
        method: 'POST',
        body: JSON.stringify({ acao: 'saida', nome, qtd })
    })
    .then(res => res.json())
    .then(data => {
        if(data.sucesso) {
            atualizarTabela();
            fecharModal();
        } else alert(data.erro);
    });
}

function removerProduto() {
    const nome = document.getElementById('nomeProduto').value;

    fetch('actions.php', {
        method: 'POST',
        body: JSON.stringify({ acao: 'remover', nome })
    })
    .then(res => res.json())
    .then(data => {
        if(data.sucesso) {
            atualizarTabela();
            fecharModal();
        } else alert(data.erro);
    });
}

// Geração do relatório com scroll
function gerarRelatorio() {
    const inicio = document.getElementById('inicio').value;
    const fim = document.getElementById('fim').value;

    fetch('actions.php', {
        method: 'POST',
        body: JSON.stringify({ acao: 'relatorio', inicio, fim })
    })
    .then(res => res.json())
    .then(data => {
        let tabelaHTML = `
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Produto</th>
                        <th>Quantidade</th>
                        <th>Tipo</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
        `;
        data.forEach(item => {
            tabelaHTML += `<tr>
                <td>${item.id}</td>
                <td>${item.produto_id}</td>
                <td>${item.quantidade}</td>
                <td>${item.tipo}</td>
                <td>${item.data}</td>
            </tr>`;
        });
        tabelaHTML += `</tbody></table>`;

        // Aqui adicionamos o container que aplica o scroll
        document.getElementById('resultadoRelatorio').innerHTML = `
            <div class="relatorio-container">
                ${tabelaHTML}
            </div>
        `;
    });
}

// Inicializa a tabela ao carregar
window.onload = atualizarTabela;
