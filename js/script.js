// ===============================
// Função para listar produtos
// ===============================
async function listarProdutos() {
    try {
        const response = await fetch('../api/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ acao: 'listar' })
        });

        if (!response.ok) throw new Error("Erro HTTP " + response.status);
        let produtos;
        try {
            produtos = await response.json();
        } catch (e) {
            console.error("Resposta não é JSON:", await response.text());
            return;
        }

        const tabela = document.querySelector('#tabelaProdutos tbody');
        if (!tabela) return;
        tabela.innerHTML = '';

        if (!produtos || produtos.length === 0) {
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
    const nomeEl = document.getElementById('nomeProduto');
    const qtdEl = document.getElementById('quantidadeProduto');

    if (!nomeEl || !qtdEl) return;

    const nome = nomeEl.value.trim();
    const quantidade = parseInt(qtdEl.value);

    if (!nome || isNaN(quantidade)) {
        alert('Preencha todos os campos!');
        return;
    }

    try {
        const response = await fetch('../api/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ acao: 'adicionar', nome, quantidade })
        });

        let result;
        try {
            result = await response.json();
        } catch (e) {
            console.error("Resposta não é JSON:", await response.text());
            return;
        }

        if (result.sucesso) {
            nomeEl.value = '';
            qtdEl.value = '';
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
        const response = await fetch('../api/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ acao: 'remover', id })
        });

        let result;
        try {
            result = await response.json();
        } catch (e) {
            console.error("Resposta não é JSON:", await response.text());
            return;
        }

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
        const response = await fetch('../api/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ acao: 'relatorio', ...filtros })
        });

        if (!response.ok) throw new Error("Erro HTTP " + response.status);
        let movimentacoes;
        try {
            movimentacoes = await response.json();
        } catch (e) {
            console.error("Resposta não é JSON:", await response.text());
            return;
        }

        const tabela = document.querySelector('#tabelaMovimentacoes tbody');
        if (!tabela) return;
        tabela.innerHTML = '';

        if (!movimentacoes || movimentacoes.length === 0) {
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
    const dataInicio = document.getElementById('filtroDataInicio')?.value || "";
    const dataFim = document.getElementById('filtroDataFim')?.value || "";
    const tipo = document.getElementById('filtroTipo')?.value || "";

    listarMovimentacoes({ dataInicio, dataFim, tipo });
}

// ===============================
// Inicialização ao carregar página
// ===============================
document.addEventListener('DOMContentLoaded', () => {
    listarProdutos();
    listarMovimentacoes();

    const btnAdicionar = document.getElementById('btnAdicionar');
    if (btnAdicionar) {
        btnAdicionar.addEventListener('click', adicionarProduto);
    }

    const btnFiltrar = document.getElementById('btnFiltrar');
    if (btnFiltrar) {
        btnFiltrar.addEventListener('click', filtrarRelatorio);
    }
});
