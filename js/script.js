const modal = document.getElementById('modal');
const modalTitulo = document.getElementById('modal-titulo');
const modalConteudo = document.getElementById('modal-conteudo');
const tabelaProdutos = document.getElementById('tabelaProdutos').querySelector('tbody');

// Fecha modal ao clicar fora
window.onclick = function(event) {
    if(event.target == modal){
        modal.style.display = 'none';
        modalConteudo.innerHTML = '';
    }
}

async function abrirModal(acao){
    modal.style.display = 'flex';
    modalTitulo.textContent = acao.charAt(0).toUpperCase() + acao.slice(1);
    modalConteudo.innerHTML = '';

    if(acao === 'cadastrar'){
        const nomeInput = document.createElement('input');
        nomeInput.placeholder = 'Nome do produto';
        const qtdInput = document.createElement('input');
        qtdInput.type = 'number';
        qtdInput.placeholder = 'Quantidade';
        const btn = document.createElement('button');
        btn.textContent = 'Confirmar';
        btn.onclick = () => executarAcao(acao, nomeInput.value, qtdInput.value);
        modalConteudo.append(nomeInput,qtdInput,btn);

    } else if(acao === 'entrada' || acao === 'saida'){
        const select = document.createElement('select');
        select.id = 'selectProduto';
        const res = await fetch('api/actions.php', {
            method: 'POST',
            body: JSON.stringify({acao:'listar'})
        });
        const produtos = await res.json();
        produtos.forEach(p => {
            const option = document.createElement('option');
            option.value = p.nome;
            option.textContent = p.nome;
            select.appendChild(option);
        });
        const qtdInput = document.createElement('input');
        qtdInput.type = 'number';
        qtdInput.placeholder = 'Quantidade';
        const btn = document.createElement('button');
        btn.textContent = 'Confirmar';
        btn.onclick = () => executarAcao(acao, select.value, qtdInput.value);
        modalConteudo.append(select,qtdInput,btn);

    } else if(acao === 'remover'){
        const select = document.createElement('select');
        select.id = 'selectProduto';
        const res = await fetch('api/actions.php', {
            method: 'POST',
            body: JSON.stringify({acao:'listar'})
        });
        const produtos = await res.json();
        produtos.forEach(p => {
            const option = document.createElement('option');
            option.value = p.nome;
            option.textContent = p.nome;
            select.appendChild(option);
        });
        const btn = document.createElement('button');
        btn.textContent = 'Remover';
        btn.onclick = () => executarAcao('remover', select.value, 0);
        modalConteudo.append(select,btn);

    } else if(acao === 'relatorio'){
        const inicio = document.createElement('input');
        inicio.type = 'date';
        const fim = document.createElement('input');
        fim.type = 'date';
        const btn = document.createElement('button');
        btn.textContent = 'Gerar Relatório';
        btn.onclick = () => gerarRelatorio(inicio.value,fim.value);
        modalConteudo.append(inicio,fim,btn);
    }

    // Botão fechar
    const btnFechar = document.createElement('button');
    btnFechar.textContent = 'Fechar';
    btnFechar.className = 'fechar';
    btnFechar.onclick = () => {
        modal.style.display = 'none';
        modalConteudo.innerHTML = '';
    }
    modalConteudo.appendChild(btnFechar);
}

// Atualiza tabela principal
async function atualizarTabela(){
    const res = await fetch('api/actions.php', {
        method:'POST',
        body: JSON.stringify({acao:'listar'})
    });
    const produtos = await res.json();
    tabelaProdutos.innerHTML = '';
    produtos.forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${p.nome}</td><td>${p.quantidade}</td>`;
        tabelaProdutos.appendChild(tr);
    });
}

// Funções fictícias (executarAcao, gerarRelatorio)
async function executarAcao(acao,nome,qtd){ 
    await fetch('api/actions.php', {
        method:'POST',
        body: JSON.stringify({acao,nome,qtd})
    });
    atualizarTabela();
    modal.style.display = 'none';
    modalConteudo.innerHTML = '';
}

async function gerarRelatorio(inicio,fim){
    const res = await fetch('api/actions.php', {
        method:'POST',
        body: JSON.stringify({acao:'relatorio',inicio,fim})
    });
    const rel = await res.json();
    let html = `<table><tr><th>Produto</th><th>Quantidade</th><th>Tipo</th><th>Data</th></tr>`;
    rel.forEach(r => {
        html += `<tr><td>${r.nome}</td><td>${r.quantidade}</td><td>${r.tipo}</td><td>${r.data}</td></tr>`;
    });
    html += '</table>';
    modalConteudo.innerHTML = html;
}
  
// Atualiza tabela ao carregar página
window.onload = atualizarTabela;
