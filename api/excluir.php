<?php
// api/excluir.php
header('Content-Type: application/json');
require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? intval($input['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "ID válido obrigatório"]);
    exit;
}

$stmt = $mysqli->prepare("DELETE FROM produtos WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
if ($stmt->affected_rows === 0) {
    http_response_code(404);
    echo json_encode(["error" => "Produto não encontrado"]);
    exit;
}
echo json_encode(["success" => true]);
