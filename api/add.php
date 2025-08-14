<?php
// api/add.php
header('Content-Type: application/json');
require_once 'db.php';

// Espera JSON
$input = json_decode(file_get_contents('php://input'), true);
$nome = isset($input['nome']) ? trim($input['nome']) : '';
$quantidade = isset($input['quantidade']) ? intval($input['quantidade']) : 0;

if ($nome === '') {
    http_response_code(400);
    echo json_encode(["error" => "Nome obrigatório"]);
    exit;
}
if ($quantidade < 0) $quantidade = 0;

// Verifica duplicado
$stmt = $mysqli->prepare("SELECT COUNT(*) as c FROM produtos WHERE nome = ?");
$stmt->bind_param("s", $nome);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if ($res['c'] > 0) {
    http_response_code(409);
    echo json_encode(["error" => "Produto já cadastrado"]);
    exit;
}

// Insere
$stmt = $mysqli->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
$stmt->bind_param("si", $nome, $quantidade);
if ($stmt->execute()) {
    echo json_encode(["success" => true, "id" => $mysqli->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Erro ao inserir produto"]);
}
