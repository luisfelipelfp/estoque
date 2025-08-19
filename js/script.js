const api = "actions.php";

async function enviarDados(dados){
    const resp = await fetch(api,{
        method:"POST",
        body:JSON.stringify(dados)
    });
    return await resp.json();
}

// Cadastrar
document.getElementById("form-cadastro").onsubmit = async e =>{
    e.preventDefault();
    const nome = document.getElementById("cad-nome").value;
    const qtd = document.getElementById("cad-qtd").value;
    const dados = await enviarDados({acao:"cadastrar",nome,qtd});
    if(dados.sucesso){
        alert("Produto cadastrado");
        listarProdutos();
    }else{
        alert(dados.erro);
    }
};

// Entrada / Saída
document.getElementById("form-movimentar").onsubmit = async e =>{
    e.preventDefault();
    const nome = document.getElementById("mov-nome").value;
    const qtd = document.getElementById("mov-qtd").value;
    const tipo = document.querySelector("input[name='tipo']:checked").value;
    const dados = await enviarDados({acao:tipo,nome,qtd});
    if(dados.sucesso){
        alert("Movimentação registrada");
        listarProdutos();
    }else{
        alert(dados.erro);
    }
};

// Remover produto
document.getElementById("form-remover").onsubmit = async e =>{
    e.preventDefault();
    const nome = document.getElementById("rem-nome").value;
    const dados = await enviarDados({acao:"remover",nome});
    if(dados.sucesso){
        alert("Produto removido");
        listarProdutos();
    }else{
        alert(dados.erro);
    }
};

// Listar produtos
async function listarProdutos(){
    const dados = await enviarDados({acao:"listar"});
    const tabela = document.querySelector("#tabela-produtos tbody");
    tabela.innerHTML = "";
    dados.forEach(p=>{
        tabela.innerHTML += `
            <tr>
                <td>${p.id}</td>
                <td>${p.nome}</td>
                <td>${p.quantidade}</td>
            </tr>
        `;
    });
}

// Relatório
document.getElementById("form-relatorio").onsubmit = async e =>{
    e.preventDefault();
    const inicio = document.getElementById("rel-inicio").value;
    const fim = document.getElementById("rel-fim").value;
    const dados = await enviarDados({acao:"relatorio",inicio,fim});
    const tabela = document.querySelector("#tabela-relatorio tbody");
    tabela.innerHTML = "";

    dados.forEach(r=>{
        let status = (r.nome && r.nome.trim() !== "") ? "Ativo" : "Removido";
        tabela.innerHTML += `
            <tr>
                <td>${r.id}</td>
                <td>${r.nome ?? "(Removido)"}</td>
                <td>${r.quantidade}</td>
                <td>${r.tipo}</td>
                <td>${r.data}</td>
                <td>${status}</td>
            </tr>
        `;
    });
}

// Inicializa
listarProdutos();
