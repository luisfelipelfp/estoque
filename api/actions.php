<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Conexão com MariaDB
$host = "192.168.15.100"; 
$user = "root"; 
$pass = "#Shakka01"; 
$db   = "estoque";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(["erro" => "Falha na conexão: " . $conn->connect_error]));
}

// Captura dados
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) $input = $_POST;

$acao = $input['acao'] ?? '';

if ($acao == 'cadastrar') {
    $nome = $conn->real_escape_string($input['nome']);
    $quantidade = (int)$input['quantidade'];

    // Verificar duplicado
    $check = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
    if ($check->num_rows > 0) {
        echo json_encode(["erro" => "Produto já cadastrado"]);
        exit;
    }

    $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome',$quantidade)");
    $conn->query("INSERT INTO movimentacoes (produto_id, nome, tipo, quantidade, data) 
                  VALUES (LAST_INSERT_ID(), '$nome','entrada',$quantidade,NOW())");

    echo json_encode(["sucesso" => "Produto cadastrado"]);

} elseif ($acao == 'entrada' || $acao == 'saida') {
    $produto_id = (int)$input['produto_id'];
    $quantidade = (int)$input['quantidade'];
    $tipo = $acao;

    $res = $conn->query("SELECT quantidade, nome FROM produtos WHERE id=$produto_id");
    if ($res->num_rows == 0) {
        echo json_encode(["erro" => "Produto não encontrado"]);
        exit;
    }
    $row = $res->fetch_assoc();
    $nome = $row['nome'];
    $estoque = (int)$row['quantidade'];

    if ($tipo == 'saida' && $quantidade > $estoque) {
        echo json_encode(["erro" => "Estoque insuficiente"]);
        exit;
    }

    $novo = ($tipo == 'entrada') ? $estoque + $quantidade : $estoque - $quantidade;
    $conn->query("UPDATE produtos SET quantidade=$novo WHERE id=$produto_id");

    $conn->query("INSERT INTO movimentacoes (produto_id, nome, tipo, quantidade, data) 
                  VALUES ($produto_id,'$nome','$tipo',$quantidade,NOW())");

    echo json_encode(["sucesso" => "Movimentação registrada"]);

} elseif ($acao == 'remover') {
    $produto_id = (int)$input['produto_id'];
    $conn->query("DELETE FROM produtos WHERE id=$produto_id");
    echo json_encode(["sucesso" => "Produto removido"]);

} elseif ($acao == 'listar') {
    $res = $conn->query("SELECT * FROM produtos ORDER BY nome ASC");
    $produtos = [];
    while ($row = $res->fetch_assoc()) {
        $produtos[] = $row;
    }
    echo json_encode($produtos);

} elseif ($acao == 'relatorio') {
    $inicio = $conn->real_escape_string($input['inicio']);
    $fim    = $conn->real_escape_string($input['fim']);

    if (empty($inicio) || empty($fim)) {
        echo json_encode(["erro" => "Datas inválidas"]);
        exit;
    }

    $res = $conn->query("SELECT nome, tipo, quantidade, 
                         DATE_FORMAT(data,'%Y-%m-%d %H:%i:%s') as data 
                         FROM movimentacoes 
                         WHERE data BETWEEN '$inicio 00:00:00' AND '$fim 23:59:59'
                         ORDER BY data ASC");

    $movs = [];
    while ($row = $res->fetch_assoc()) {
        $movs[] = $row;
    }
    echo json_encode($movs);

} else {
    echo json_encode(["erro" => "Ação inválida"]);
}

$conn->close();
?>
