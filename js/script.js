const modal = document.getElementById('modal');
const modalTitulo = document.getElementById('modalTitulo');
const modalConteudo = document.getElementById('modalConteudo');
const tabela = document.querySelector('#tabelaProdutos tbody');

async function atualizarTabela(){
    const res = await fetch('api/actions.php', {
        method:'POST',
        body: JSON.stringify({acao:'listar'})
    });
    const produtos = await res.json();
    tabela.innerHTML = '';
    produtos.forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${p.id}</td><td>${p.nome}</td><td>${p.quantidade}</td>`;
        tabela.appendChild(tr);
    });
}

// Modal
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
        const res = await fetch('api/actions.php',{
            method:'POST',
            body:JSON.stringify({acao:'listar'})
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
        const res = await fetch('api/actions.php',{
            method:'POST',
            body:JSON.stringify({acao:'listar'})
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
}

function fecharModal(){
    modal.style.display = 'none';
}

async function executarAcao(acao,nome,quantidade){
    const res = await fetch('api/actions.php',{
        method:'POST',
        body: JSON.stringify({acao,nome,quantidade})
    });
    const data = await res.json();
    alert(data.mensagem);
    fecharModal();
    atualizarTabela();
}

async function gerarRelatorio(inicio,fim){
    const res = await fetch('api/actions.php',{
        method:'POST',
        body: JSON.stringify({acao:'relatorio',inicio,fim})
    });
    const dados = await res.json();
    let html = '<table><tr><th>Produto</th><th>Tipo</th><th>Quantidade</th><th>Data</th></tr>';
    dados.forEach(d => {
        html += `<tr><td>${d.produto}</td><td>${d.tipo}</td><td>${d.quantidade}</td><td>${d.data}</td></tr>`;
    });
    html += '</table>';
    modalConteudo.innerHTML = html;
}

window.onload = atualizarTabela;
