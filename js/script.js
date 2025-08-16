let graficoMov = null;

document.addEventListener("DOMContentLoaded", ()=>{ listarProdutos(); });

async function listarProdutos(){
    const res = await fetch("api/actions.php?acao=listar_produtos");
    const dados = await res.json();
    const tbody = document.querySelector("#tabelaProdutos tbody");
    tbody.innerHTML = "";
    const movSelect = document.getElementById("movProduto");
    const relSelect = document.getElementById("relProduto");
    movSelect.innerHTML = "<option value=''>Selecione</option>";
    relSelect.innerHTML = "<option value=''>Todos</option>";

    dados.forEach(p=>{
        tbody.innerHTML += `<tr>
            <td>${p.id}</td><td>${p.nome}</td><td>${p.quantidade}</td>
            <td><button onclick="excluirProduto(${p.id})">Excluir</button></td>
        </tr>`;
        movSelect.innerHTML += `<option value="${p.id}">${p.nome}</option>`;
        relSelect.innerHTML += `<option value="${p.id}">${p.nome}</option>`;
    });
}

async function cadastrarProduto(){
    const nome = document.getElementById("produtoNome").value.trim();
    if(!nome) return alert("Digite o nome do produto");
    await fetch(`api/actions.php?acao=cadastrar_produto&nome=${encodeURIComponent(nome)}`);
    document.getElementById("produtoNome").value = "";
    listarProdutos();
}

async function excluirProduto(id){
    if(!confirm("Deseja realmente excluir?")) return;
    await fetch(`api/actions.php?acao=excluir_produto&id=${id}`);
    listarProdutos();
}

async function registrarMovimentacao(){
    const produto = document.getElementById("movProduto").value;
    const tipo = document.getElementById("movTipo").value;
    const quantidade = document.getElementById("movQuantidade").value;
    if(!produto || !quantidade) return alert("Selecione produto e quantidade");
    await fetch(`api/actions.php?acao=movimentacao&produto_id=${produto}&tipo=${tipo}&quantidade=${quantidade}`);
    document.getElementById("movQuantidade").value = "";
    listarProdutos();
}

async function gerarRelatorio(){
    const dataInicio = document.getElementById("relDataInicio").value;
    const dataFim = document.getElementById("relDataFim").value;
    const produtoId = document.getElementById("relProduto").value;
    if(!dataInicio || !dataFim) return alert("Escolha o intervalo de datas");

    let url = `api/actions.php?acao=relatorio_intervalo&data_inicio=${dataInicio}&data_fim=${dataFim}`;
    if(produtoId) url += `&produto_id=${produtoId}`;
    const res = await fetch(url);
    const dados = await res.json();

    // Atualiza tabela
    const tbody = document.querySelector("#tabelaRelatorio tbody");
    tbody.innerHTML = "";

    let labels = [], entradas = [], saidas = [];
    dados.forEach(r=>{
        tbody.innerHTML += `<tr class="${r.tipo}">
            <td>${r.id}</td><td>${r.nome}</td><td>${r.tipo}</td><td>${r.quantidade}</td><td>${r.data}</td>
        </tr>`;
        const dataFormat = r.data.split(" ")[0];
        if(!labels.includes(dataFormat)) labels.push(dataFormat);
        if(r.tipo==='entrada') entradas.push({data:dataFormat,quantidade:r.quantidade});
        else saidas.push({data:dataFormat,quantidade:r.quantidade});
    });

    const entradasPorDia = labels.map(l=>entradas.filter(e=>e.data===l).reduce((s,v)=>s+v.quantidade,0));
    const saidasPorDia = labels.map(l=>saidas.filter(s=>s.data===l).reduce((s,v)=>s+v.quantidade,0));

    const ctx = document.getElementById("graficoMov").getContext("2d");
    if(graficoMov) graficoMov.destroy();
    graficoMov = new Chart(ctx,{
        type:'bar',
        data:{
            labels: labels,
            datasets:[
                { label:'Entradas', data:entradasPorDia, backgroundColor:'#28a745' },
                { label:'Saídas', data:saidasPorDia, backgroundColor:'#dc3545' }
            ]
        },
        options:{
            responsive:true,
            plugins:{
                legend:{position:'top'},
                title:{display:true,text:'Movimentações por Dia'}
            },
            scales:{y:{beginAtZero:true}}
        }
    });
}
