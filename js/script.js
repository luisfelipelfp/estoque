const modal = document.getElementById('modal');
const modalTitulo = document.getElementById('modal-titulo');
const modalConteudo = document.getElementById('modal-conteudo');
const tabela = document.getElementById('tabelaProdutos').querySelector('tbody');

function abrirModal(acao) {
    modal.style.display = 'flex';
    modalTitulo.textContent = acao.charAt(0).toUpperCase() + acao.slice(1);
    modalConteudo.innerHTML = '';

    if(acao === 'cadastrar' || acao === 'entrada' || acao === 'saida' || acao === 'remover'){
        const nomeInput = document.createElement('input');
        nomeInput.placeholder = 'Nome do produto';
        const qtdInput = document.createElement('input');
        qtdInput.type = 'number';
        qtdInput.placeholder = 'Quantidade';
        if(acao==='remover') qtdInput.style.display = 'none';

        const btn = document.createElement('button');
        btn.textContent = 'Confirmar';
        btn.onclick = () => executarAcao(acao, nomeInput.value, qtdInput.value);

        modalConteudo.append(nomeInput, qtdInput, btn);
    } else if(acao === 'relatorio'){
        const inicio = document.createElement('input');
        inicio.type = 'date';
        const fim = document.createElement('input');
        fim.type = 'date';
        const btn = document.createElement('button');
        btn.textContent = 'Gerar Relatório';
        btn.onclick = () => gerarRelatorio(inicio.value, fim.value);
        modalConteudo.append(inicio,fim,btn);
    }
}

function fecharModal() {
    modal.style.display = 'none';
}

async function executarAcao(acao, nome, qtd){
    const data = {acao, nome, quantidade: qtd};
    const res = await fetch('api/actions.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });
    const json = await res.json();
    if(json.erro) alert(json.erro);
    else {
        alert('Operação realizada com sucesso!');
        fecharModal();
        listarProdutos();
    }
}

async function listarProdutos(){
    const res = await fetch('api/actions.php', {
        method: 'POST',
        body: JSON.stringify({acao:'listar'})
    });
    const produtos = await res.json();
    tabela.innerHTML = '';
    produtos.forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${p.id}</td><td>${p.nome}</td><td>${p.quantidade}</td>
        <td><button onclick="removerProduto('${p.nome}')">Remover</button></td>`;
        tabela.appendChild(tr);
    });
}

async function removerProduto(nome){
    if(!confirm('Remover '+nome+'?')) return;
    await executarAcao('remover', nome, 0);
}

async function gerarRelatorio(inicio,fim){
    const res = await fetch('api/actions.php',{
        method:'POST',
        body:JSON.stringify({acao:'relatorio', inicio, fim})
    });
    const dados = await res.json();
    modalConteudo.innerHTML = '<table><tr><th>Produto</th><th>Tipo</th><th>Qtd</th><th>Data</th></tr></table>';
    const tbody = modalConteudo.querySelector('table');
    dados.forEach(d=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${d.produto_nome}</td><td>${d.tipo}</td><td>${d.quantidade}</td><td>${d.data}</td>`;
        tbody.appendChild(tr);
    });
}

// Inicializa tabela
listarProdutos();
