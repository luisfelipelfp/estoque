// Função para abrir modal
function abrirModal(tipo){
    const modal = document.getElementById('modal');
    const titulo = document.getElementById('modal-titulo');
    const conteudo = document.getElementById('modal-conteudo');
    conteudo.innerHTML = ''; // limpa conteúdo antes de carregar

    if(tipo === 'cadastrar'){
        titulo.innerText = 'Cadastrar Produto';
        conteudo.innerHTML = `
            <input type="text" id="nomeProduto" placeholder="Nome do Produto">
            <input type="number" id="quantidadeProduto" placeholder="Quantidade">
            <button onclick="cadastrarProduto()">Salvar</button>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
    }

    else if(tipo === 'entrada'){
        titulo.innerText = 'Entrada de Produto';
        conteudo.innerHTML = `
            <input type="text" id="nomeEntrada" placeholder="Nome do Produto">
            <input type="number" id="quantidadeEntrada" placeholder="Quantidade">
            <button onclick="entradaProduto()">Salvar</button>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
    }

    else if(tipo === 'saida'){
        titulo.innerText = 'Saída de Produto';
        conteudo.innerHTML = `
            <input type="text" id="nomeSaida" placeholder="Nome do Produto">
            <input type="number" id="quantidadeSaida" placeholder="Quantidade">
            <button onclick="saidaProduto()">Salvar</button>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
    }

    else if(tipo === 'remover'){
        titulo.innerText = 'Remover Produto';
        conteudo.innerHTML = `
            <input type="text" id="nomeRemover" placeholder="Nome do Produto">
            <button onclick="removerProduto()">Remover</button>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
    }

    else if(tipo === 'relatorio'){
        titulo.innerText = 'Relatório de Movimentações';
        conteudo.innerHTML = `
            <div class="relatorio-container">
                <table id="tabela-relatorio">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Produto</th>
                            <th>Tipo</th>
                            <th>Quantidade</th>
                        </tr>
                    </thead>
                    <tbody id="corpo-relatorio">
                        <!-- Movimentações serão carregadas aqui -->
                    </tbody>
                </table>
            </div>
            <button class="fechar" onclick="fecharModal()">Fechar</button>
        `;
        carregarRelatorio();
    }

    modal.style.display = 'flex';
}

// Função para fechar modal
function fecharModal(){
    document.getElementById('modal').style.display = 'none';
}

// Função para carregar produtos na tabela principal
function carregarProdutos(){
    let produtos = JSON.parse(localStorage.getItem('produtos')) || [];
    const corpoTabela = document.querySelector('#tabelaProdutos tbody');
    corpoTabela.innerHTML = '';

    produtos.forEach(produto => {
        let tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${produto.nome}</td>
            <td>${produto.quantidade}</td>
        `;
        corpoTabela.appendChild(tr);
    });
}

// Cadastrar produto
function cadastrarProduto(){
    let nome = document.getElementById('nomeProduto').value;
    let quantidade = parseInt(document.getElementById('quantidadeProduto').value);

    if(!nome || quantidade <= 0) return alert("Preencha corretamente!");

    let produtos = JSON.parse(localStorage.getItem('produtos')) || [];
    produtos.push({ nome, quantidade });
    localStorage.setItem('produtos', JSON.stringify(produtos));

    carregarProdutos();
    fecharModal();
}

// Entrada de produto
function entradaProduto(){
    let nome = document.getElementById('nomeEntrada').value;
    let quantidade = parseInt(document.getElementById('quantidadeEntrada').value);

    if(!nome || quantidade <= 0) return alert("Preencha corretamente!");

    let produtos = JSON.parse(localStorage.getItem('produtos')) || [];
    let produto = produtos.find(p => p.nome === nome);

    if(produto){
        produto.quantidade += quantidade;
    } else {
        produtos.push({ nome, quantidade });
    }

    localStorage.setItem('produtos', JSON.stringify(produtos));

    registrarMovimentacao(nome, "Entrada", quantidade);
    carregarProdutos();
    fecharModal();
}

// Saída de produto
function saidaProduto(){
    let nome = document.getElementById('nomeSaida').value;
    let quantidade = parseInt(document.getElementById('quantidadeSaida').value);

    if(!nome || quantidade <= 0) return alert("Preencha corretamente!");

    let produtos = JSON.parse(localStorage.getItem('produtos')) || [];
    let produto = produtos.find(p => p.nome === nome);

    if(produto && produto.quantidade >= quantidade){
        produto.quantidade -= quantidade;
    } else {
        return alert("Produto não encontrado ou quantidade insuficiente!");
    }

    localStorage.setItem('produtos', JSON.stringify(produtos));

    registrarMovimentacao(nome, "Saída", quantidade);
    carregarProdutos();
    fecharModal();
}

// Remover produto
function removerProduto(){
    let nome = document.getElementById('nomeRemover').value;

    let produtos = JSON.parse(localStorage.getItem('produtos')) || [];
    produtos = produtos.filter(p => p.nome !== nome);

    localStorage.setItem('produtos', JSON.stringify(produtos));

    carregarProdutos();
    fecharModal();
}

// Registrar movimentação
function registrarMovimentacao(produto, tipo, quantidade){
    let movimentacoes = JSON.parse(localStorage.getItem('movimentacoes')) || [];
    let data = new Date().toLocaleString();

    movimentacoes.push({ data, produto, tipo, quantidade });
    localStorage.setItem('movimentacoes', JSON.stringify(movimentacoes));
}

// Carregar relatório
function carregarRelatorio(){
    let movimentacoes = JSON.parse(localStorage.getItem('movimentacoes')) || [];
    const corpoRelatorio = document.getElementById('corpo-relatorio');
    corpoRelatorio.innerHTML = '';

    movimentacoes.forEach(m => {
        let tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${m.data}</td>
            <td>${m.produto}</td>
            <td>${m.tipo}</td>
            <td>${m.quantidade}</td>
        `;
        corpoRelatorio.appendChild(tr);
    });
}

// Ao carregar a página
window.onload = carregarProdutos;
