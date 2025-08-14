<?php
// api/saida.php
header('Content-Type: application/json');
require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? intval($input['id']) : 0;
$quantidade = isset($input['quantidade']) ? intval($input['quantidade']) : 0;

if ($id <= 0 || $quantidade <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "ID e quantidade válidos são obrigatórios"]);
    exit;
}

// Checar quantidade atual
$stmt = $mysqli->prepare("SELECT quantidade FROM produtos WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if (!$res) {
    http_response_code(404);
    echo json_encode(["error" => "Produto não encontrado"]);
    exit;
}
$qtd_atual = intval($res['quantidade']);
if ($quantidade > $qtd_atual) {
    http_response_code(400);
    echo json_encode(["error" => "Quantidade a remover maior que o estoque"]);
    exit;
}

// Atualiza
$stmt = $mysqli->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
$stmt->bind_param("ii", $quantidade, $id);
$stmt->execute();
echo json_encode(["success" => true]);
