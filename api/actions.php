<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Configuração do banco
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "estoque";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    echo json_encode(["sucesso" => false, "erro" => "Falha na conexão com o banco"]);
    exit;
}

// Recebe dados JSON
$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "";

$response = ["sucesso" => false, "erro" => "Ação inválida"];

// ===================== PRODUTOS =====================

// Listar produtos
if ($action === "listarProdutos") {
    $result = $conn->query("SELECT * FROM produtos ORDER BY id ASC");
    $produtos = [];
    while ($row = $result->fetch_assoc()) {
        $produtos[] = $row;
    }
    $response = ["sucesso" => true, "dados" => $produtos];
}

// Adicionar produto
if ($action === "adicionarProduto") {
    $nome = $conn->real_escape_string($input["nome"]);
    if (!$nome) {
        $response = ["sucesso" => false, "erro" => "Nome do produto é obrigatório"];
    } else {
        $sql = "INSERT INTO produtos (nome, quantidade) VALUES ('$nome', 0)";
        if ($conn->query($sql)) {
            $response = ["sucesso" => true];
        } else {
            $response = ["sucesso" => false, "erro" => "Erro ao adicionar produto (já existe?)"];
        }
    }
}

// Entrada de produto
if ($action === "entradaProduto") {
    $id = (int)$input["id"];
    $quantidade = (int)$input["quantidade"];
    if ($id && $quantidade > 0) {
        $conn->query("UPDATE produtos SET quantidade = quantidade + $quantidade WHERE id = $id");
        $conn->query("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data) 
                      SELECT id, nome, 'entrada', $quantidade, NOW() FROM produtos WHERE id = $id");
        $response = ["sucesso" => true];
    } else {
        $response = ["sucesso" => false, "erro" => "Dados inválidos para entrada"];
    }
}

// Saída de produto
if ($action === "saidaProduto") {
    $id = (int)$input["id"];
    $quantidade = (int)$input["quantidade"];
    if ($id && $quantidade > 0) {
        $check = $conn->query("SELECT quantidade, nome FROM produtos WHERE id = $id")->fetch_assoc();
        if ($check && $check["quantidade"] >= $quantidade) {
            $conn->query("UPDATE produtos SET quantidade = quantidade - $quantidade WHERE id = $id");
            $nome = $conn->real_escape_string($check["nome"]);
            $conn->query("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data) 
                          VALUES ($id, '$nome', 'saida', $quantidade, NOW())");
            $response = ["sucesso" => true];
        } else {
            $response = ["sucesso" => false, "erro" => "Quantidade insuficiente em estoque"];
        }
    }
}

// Remover produto
if ($action === "removerProduto") {
    $id = (int)$input["id"];
    if ($id) {
        $conn->query("DELETE FROM produtos WHERE id = $id");
        $response = ["sucesso" => true];
    }
}

// ===================== MOVIMENTAÇÕES =====================

// Listar movimentações
if ($action === "listarMovimentacoes") {
    $result = $conn->query("SELECT * FROM movimentacoes ORDER BY data DESC");
    $movs = [];
    while ($row = $result->fetch_assoc()) {
        $movs[] = $row;
    }
    $response = ["sucesso" => true, "dados" => $movs];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
$conn->close();
