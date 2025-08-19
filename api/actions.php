<?php
$servername = "localhost";
$username = "root";
$password = "#Shakka01";
$dbname = "estoque";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexão falhou: " . $conn->connect_error);
}

$action = $_GET['action'] ?? '';

if ($action == 'adicionar') {
    $nome = $_POST['nome'];
    $quantidade = (int) $_POST['quantidade'];

    $sql = "INSERT INTO produtos (nome, quantidade) VALUES ('$nome', $quantidade)";
    if ($conn->query($sql) === TRUE) {
        // registrar movimentação
        $produto_id = $conn->insert_id;
        $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo) VALUES ($produto_id, $quantidade, 'entrada')");
        echo "Produto adicionado com sucesso";
    } else {
        echo "Erro: " . $conn->error;
    }
}

elseif ($action == 'entrada' || $action == 'saida') {
    $produto_id = (int) $_POST['id'];
    $quantidade = (int) $_POST['quantidade'];

    // Buscar produto atual
    $res = $conn->query("SELECT quantidade FROM produtos WHERE id = $produto_id");
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $quantidadeAtual = $row['quantidade'];

        if ($action == 'entrada') {
            $novaQuantidade = $quantidadeAtual + $quantidade;
            $tipo = 'entrada';
        } else {
            $novaQuantidade = $quantidadeAtual - $quantidade;
            if ($novaQuantidade < 0) $novaQuantidade = 0;
            $tipo = 'saida';
        }

        $conn->query("UPDATE produtos SET quantidade = $novaQuantidade WHERE id = $produto_id");
        $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo) VALUES ($produto_id, $quantidade, '$tipo')");

        echo "Movimentação registrada com sucesso";
    } else {
        echo "Produto não encontrado";
    }
}

elseif ($action == 'remover') {
    $produto_id = (int) $_POST['id'];

    // registrar movimentação antes de remover
    $res = $conn->query("SELECT quantidade FROM produtos WHERE id = $produto_id");
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $quantidade = $row['quantidade'];

        // movimentação de saída total
        if ($quantidade > 0) {
            $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo) VALUES ($produto_id, $quantidade, 'saida')");
        }
    }

    // remove produto
    $conn->query("DELETE FROM produtos WHERE id = $produto_id");

    echo "Produto removido com sucesso";
}

elseif ($action == 'listar') {
    $result = $conn->query("SELECT * FROM produtos ORDER BY nome ASC");
    $produtos = [];
    while ($row = $result->fetch_assoc()) {
        $produtos[] = $row;
    }
    echo json_encode($produtos);
}

elseif ($action == 'relatorio') {
    // ✅ Agora pegando só da tabela movimentacoes
    $sql = "SELECT id, produto_nome, tipo, quantidade, data 
            FROM movimentacoes
            ORDER BY data DESC";
    $result = $conn->query($sql);

    $movimentacoes = [];
    while ($row = $result->fetch_assoc()) {
        $movimentacoes[] = $row;
    }
    echo json_encode($movimentacoes);
}

$conn->close();
