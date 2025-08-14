<?php
// api/entrada.php
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

$stmt = $mysqli->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
$stmt->bind_param("ii", $quantidade, $id);
$stmt->execute();
if ($stmt->affected_rows === 0) {
    http_response_code(404);
    echo json_encode(["error" => "Produto não encontrado"]);
    exit;
}
echo json_encode(["success" => true]);
