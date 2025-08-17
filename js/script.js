document.addEventListener('DOMContentLoaded', function() {

    function fetchAPI(data) {
        return fetch('api/actions.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams(data)
        }).then(res => res.json());
    }

    // Modal helper
    function abrirModal(id) { document.getElementById(id).style.display = 'flex'; }
    function fecharModal(id) { document.getElementById(id).style.display = 'none'; }

    // Preenche tabela de produtos
    function listarProdutos() {
        fetchAPI({acao: 'listar_produtos'}).then(res => {
            if(res.sucesso) {
                let tbody = document.querySelector('#tabelaProdutos tbody');
                tbody.innerHTML = '';
                res.produtos.forEach(p => {
                    let tr = document.createElement('tr');
                    tr.innerHTML = `<td>${p.id}</td><td>${p.nome}</td><td>${p.quantidade}</td>`;
                    tbody.appendChild(tr);
                });
            }
        });
    }

    listarProdutos(); // inicial

    // Abrir modais
    document.getElementById('btnCadastrar').onclick = () => abrirModal('modalCadastro');
    document.getElementById('btnEntrada').onclick = () => abrirModal('modalEntrada');
    document.getElementById('btnSaida').onclick = () => abrirModal('modalSaida');
    document.getElementById('btnRelatorio').onclick = () => abrirModal('modalRelatorio');

    // Fechar modais
    document.querySelectorAll('.fecharModal').forEach(btn => btn.onclick = e => {
        btn.closest('.modal').style.display = 'none';
    });

    // Cadastrar produto
    document.getElementById('salvarCadastro').onclick = () => {
        let nome = document.getElementById('nomeProduto').value;
        let quantidade = document.getElementById('quantidadeProduto').value;
        fetchAPI({acao:'cadastrar_produto', nome, quantidade}).then(res=>{
            alert(res.mensagem);
            if(res.sucesso) { fecharModal('modalCadastro'); listarProdutos(); }
        });
    };

    // Entrada produto
    document.getElementById('salvarEntrada').onclick = () => {
        let nome = document.getElementById('nomeEntrada').value;
        let quantidade = document.getElementById('quantidadeEntrada').value;
        fetchAPI({acao:'entrada_produto', nome, quantidade}).then(res=>{
            alert(res.mensagem);
            if(res.sucesso) { fecharModal('modalEntrada'); listarProdutos(); }
        });
    };

    // Saída produto
    document.getElementById('salvarSaida').onclick = () => {
        let nome = document.getElementById('nomeSaida').value;
        let quantidade = document.getElementById('quantidadeSaida').value;
        fetchAPI({acao:'saida_produto', nome, quantidade}).then(res=>{
            alert(res.mensagem);
            if(res.sucesso) { fecharModal('modalSaida'); listarProdutos(); }
        });
    };

    // Relatório
    document.getElementById('gerarRelatorio').onclick = () => {
        let dataInicio = document.getElementById('dataInicio').value;
        let dataFim = document.getElementById('dataFim').value;
        fetchAPI({acao:'relatorio', dataInicio, dataFim}).then(res=>{
            if(res.sucesso) {
                let tbody = document.querySelector('#tabelaRelatorio tbody');
                tbody.innerHTML = '';
                res.movimentacoes.forEach(m=>{
                    let tr = document.createElement('tr');
                    tr.innerHTML = `<td>${m.nome}</td><td>${m.tipo}</td><td>${m.quantidade}</td><td>${m.data}</td>`;
                    tbody.appendChild(tr);
                });
            } else { alert(res.mensagem); }
        });
    };

});
