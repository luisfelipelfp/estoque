<?php
header("Content-Type: application/json; charset=UTF-8");

$host = "192.168.15.100"; 
$user = "root";    
$pass = "#Shakka01";      
$db   = "estoque";        

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    echo json_encode(["erro" => "Falha na conexão: " . $conn->connect_error]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$acao = $input['acao'] ?? '';

if ($acao == 'listar') {
    $res = $conn->query("SELECT id, nome, quantidade FROM produtos ORDER BY nome ASC");
    $produtos = [];
    while ($row = $res->fetch_assoc()) {
        $produtos[] = $row;
    }
    echo json_encode($produtos);

} elseif ($acao == 'cadastrar') {
    $nome = $conn->real_escape_string($input['nome'] ?? '');
    $quantidade = (int)($input['quantidade'] ?? 0);

    if (empty($nome)) {
        echo json_encode(["erro" => "Nome do produto não pode ser vazio"]);
        exit;
    }

    // Evita duplicados
    $check = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
    if ($check->num_rows > 0) {
        echo json_encode(["erro" => "Produto já cadastrado"]);
        exit;
    }

    $sql = "INSERT INTO produtos (nome, quantidade) VALUES ('$nome', $quantidade)";
    if ($conn->query($sql)) {
        echo json_encode(["sucesso" => "Produto cadastrado"]);
    } else {
        echo json_encode(["erro" => "Erro: " . $conn->error]);
    }

} elseif ($acao == 'entrada' || $acao == 'saida') {
    $produto_id = isset($input['produto_id']) ? (int)$input['produto_id'] : 0;
    $nome       = $conn->real_escape_string($input['nome'] ?? '');
    $quantidade = (int)($input['quantidade'] ?? 0);
    $tipo = $acao;

    // Busca produto por ID ou nome
    if ($produto_id > 0) {
        $res = $conn->query("SELECT id, quantidade, nome FROM produtos WHERE id=$produto_id");
    } elseif (!empty($nome)) {
        $res = $conn->query("SELECT id, quantidade, nome FROM produtos WHERE nome='$nome'");
    } else {
        echo json_encode(["erro" => "Produto não informado"]);
        exit;
    }

    if ($res->num_rows == 0) {
        echo json_encode(["erro" => "Produto não encontrado"]);
        exit;
    }

    $row = $res->fetch_assoc();
    $produto_id = $row['id'];
    $estoque_atual = (int)$row['quantidade'];

    if ($tipo == 'entrada') {
        $novo_estoque = $estoque_atual + $quantidade;
    } else { // saída
        if ($estoque_atual < $quantidade) {
            echo json_encode(["erro" => "Quantidade em estoque insuficiente"]);
            exit;
        }
        $novo_estoque = $estoque_atual - $quantidade;
    }

    $conn->query("UPDATE produtos SET quantidade=$novo_estoque WHERE id=$produto_id");
    $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES ($produto_id, '$tipo', $quantidade)");

    echo json_encode(["sucesso" => "Movimentação registrada", "novo_estoque" => $novo_estoque]);

} elseif ($acao == 'remover') {
    $produto_id = (int)($input['produto_id'] ?? 0);
    if ($produto_id <= 0) {
        echo json_encode(["erro" => "ID do produto inválido"]);
        exit;
    }

    $conn->query("DELETE FROM produtos WHERE id=$produto_id");
    echo json_encode(["sucesso" => "Produto removido"]);

} elseif ($acao == 'relatorio') {
    $data_inicial = $conn->real_escape_string($input['data_inicial'] ?? '');
    $data_final   = $conn->real_escape_string($input['data_final'] ?? '');

    $sql = "SELECT m.id, p.nome, m.tipo, m.quantidade, m.data 
            FROM movimentacoes m 
            JOIN produtos p ON m.produto_id = p.id";

    if (!empty($data_inicial) && !empty($data_final)) {
        $sql .= " WHERE DATE(m.data) BETWEEN '$data_inicial' AND '$data_final'";
    }

    $sql .= " ORDER BY m.data DESC";

    $res = $conn->query($sql);
    $relatorio = [];
    while ($row = $res->fetch_assoc()) {
        $relatorio[] = $row;
    }
    echo json_encode($relatorio);

} else {
    echo json_encode(["erro" => "Ação inválida"]);
}

$conn->close();