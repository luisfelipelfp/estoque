const modal = document.getElementById('modal');
const modalTitulo = document.getElementById('modal-titulo');
const modalConteudo = document.getElementById('modal-conteudo');
const tabelaProdutos = document.getElementById('tabelaProdutos').querySelector('tbody');
const btnTema = document.getElementById('btnTema');

// Fecha modal ao clicar fora
window.onclick = function(event) {
    if(event.target == modal){
        modal.style.display = 'none';
        modalConteudo.innerHTML = '';
    }
}

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
        btn.onclick = () => executarAcao(acao,nomeInput.value,qtdInput.value);
        modalConteudo.append(nomeInput,qtdInput,btn);

    } else if(acao === 'entrada' || acao === 'saida' || acao === 'remover'){
        const select = document.createElement('select');
        const res = await fetch('api/actions.php', {
            method:'POST',
            body: JSON.stringify({acao:'listar'})
        });
        const produtos = await res.json();
        produtos.forEach(p => {
            const option = document.createElement('option');
            option.value = p.nome;
            option.textContent = p.nome;
            select.appendChild(option);
        });
        modalConteudo.appendChild(select);
        if(acao !== 'remover'){
            const qtdInput = document.createElement('input');
            qtdInput.type='number';
            qtdInput.placeholder='Quantidade';
            modalConteudo.appendChild(qtdInput);
        }
        const btn = document.createElement('button');
        btn.textContent = acao === 'remover' ? 'Remover' : 'Confirmar';
        btn.onclick = () => {
            if(acao==='remover') executarAcao(acao,select.value,0);
            else executarAcao(acao,select.value,modalConteudo.querySelector('input[type="number"]').value);
        }
        modalConteudo.appendChild(btn);

    } else if(acao === 'relatorio'){
        const inicio = document.createElement('input');
        inicio.type='date';
        const fim = document.createElement('input');
        fim.type='date';
        const btn = document.createElement('button');
        btn.textContent = 'Gerar Relatório';
        btn.onclick = () => gerarRelatorio(inicio.value,fim.value);
        modalConteudo.append(inicio,fim,btn);
    }

    // Botão fechar
    const btnFechar = document.createElement('button');
    btnFechar.textContent='Fechar';
    btnFechar.className='fechar';
    btnFechar.onclick = ()=>{
        modal.style.display='none';
        modalConteudo.innerHTML='';
    }
    modalConteudo.appendChild(btnFechar);
}

async function executarAcao(acao,nome,qtd){
    await fetch('api/actions.php',{
        method:'POST',
        body:JSON.stringify({acao,nome,qtd})
    });
    modal.style.display='none';
    modalConteudo.innerHTML='';
    atualizarTabela();
}

async function gerarRelatorio(inicio,fim){
    const res = await fetch('api/actions.php',{
        method:'POST',
        body:JSON.stringify({acao:'relatorio',inicio,fim})
    });
    const dados = await res.json();
    let conteudo = 'Produto | Tipo | Quantidade | Data\n';
    dados.forEach(l => {
        conteudo += `${l.nome} | ${l.tipo} | ${l.quantidade} | ${l.data}\n`;
    });
    alert(conteudo);
    modal.style.display='none';
    modalConteudo.innerHTML='';
}

// Tema
function aplicarTema(tema){
    if(tema==='dark'){
        document.body.classList.add('dark');
        btnTema.textContent='☀️ Light Mode';
    } else {
        document.body.classList.remove('dark');
        btnTema.textContent='🌙 Dark Mode';
    }
    localStorage.setItem('tema',tema);
}

window.onload = ()=>{
    atualizarTabela();
    const temaSalvo = localStorage.getItem('tema')||'light';
    aplicarTema(temaSalvo);
}

btnTema.onclick = ()=>{
    const temaAtual = document.body.classList.contains('dark')?'dark':'light';
    aplicarTema(temaAtual==='dark'?'light':'dark');
}
