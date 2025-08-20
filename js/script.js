// script.js

// ===============================
// Função para listar produtos
// ===============================
async function listarProdutos() {
    try {
        const response = await fetch('api/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'listar' })
        });
        const produtos = await response.json();

        const tabela = document.querySelector('#tabelaProdutos tbody');
        tabela.innerHTML = '';

        if (produtos.length === 0) {
            tabela.innerHTML = '<tr><td colspan="4">Nenhum produto encontrado</td></tr>';
            return;
        }

        produtos.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${p.id}</td>
                <td>${p.nome}</td>
                <td>${p.quantidade}</td>
                <td>
                    <button class="btn btn-danger btn-sm" onclick="removerProduto(${p.id})">Remover</button>
                </td>
            `;
            tabela.appendChild(tr);
        });
    } catch (error) {
        console.error('Erro ao listar produtos:', error);
    }
}

// ===============================
// Função para adicionar produto
// ===============================
async function adicionarProduto() {
    const nome = document.getElementById('nomeProduto').value.trim();
    const quantidade = parseInt(document.getElementById('quantidadeProduto').value);

    if (!nome || isNaN(quantidade)) {
        alert('Preencha todos os campos!');
        return;
    }

    try {
        const response = await fetch('api/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'adicionar', nome, quantidade })
        });
        const result = await response.json();

        if (result.sucesso) {
            document.getElementById('nomeProduto').value = '';
            document.getElementById('quantidadeProduto').value = '';
            listarProdutos();
            listarMovimentacoes();
        } else {
            alert(result.mensagem || 'Erro ao adicionar produto');
        }
    } catch (error) {
        console.error('Erro ao adicionar produto:', error);
    }
}

// ===============================
// Função para remover produto
// ===============================
async function removerProduto(id) {
    if (!confirm('Tem certeza que deseja remover este produto?')) return;

    try {
        const response = await fetch('api/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'remover', id })
        });
        const result = await response.json();

        if (result.sucesso) {
            listarProdutos();
            listarMovimentacoes();
        } else {
            alert(result.mensagem || 'Erro ao remover produto');
        }
    } catch (error) {
        console.error('Erro ao remover produto:', error);
    }
}

// ===============================
// Função para listar movimentações
// ===============================
async function listarMovimentacoes(filtros = {}) {
    try {
        const response = await fetch('api/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'relatorio', ...filtros })
        });
        const movimentacoes = await response.json();

        const tabela = document.querySelector('#tabelaMovimentacoes tbody');
        tabela.innerHTML = '';

        if (movimentacoes.length === 0) {
            tabela.innerHTML = '<tr><td colspan="5">Nenhuma movimentação encontrada</td></tr>';
            return;
        }

        movimentacoes.forEach(m => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${m.id}</td>
                <td>${m.produto_nome || '(removido)'}</td>
                <td>${m.tipo}</td>
                <td>${m.quantidade}</td>
                <td>${m.data}</td>
            `;
            tabela.appendChild(tr);
        });
    } catch (error) {
        console.error('Erro ao listar movimentações:', error);
    }
}

// ===============================
// Função para aplicar filtros no relatório
// ===============================
function filtrarRelatorio() {
    const dataInicio = document.getElementById('filtroDataInicio').value;
    const dataFim = document.getElementById('filtroDataFim').value;
    const tipo = document.getElementById('filtroTipo').value;

    listarMovimentacoes({ dataInicio, dataFim, tipo });
}

// ===============================
// Inicialização ao carregar página
// ===============================
document.addEventListener('DOMContentLoaded', () => {
    listarProdutos();
    listarMovimentacoes();

    // Botão adicionar produto
    document.getElementById('btnAdicionar').addEventListener('click', adicionarProduto);

    // Botão aplicar filtro no relatório
    document.getElementById('btnFiltrar').addEventListener('click', filtrarRelatorio);
});
