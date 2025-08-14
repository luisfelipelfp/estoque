<?php
// api/getProdutos.php
header('Content-Type: application/json');
require_once 'db.php';

$sql = "SELECT id, nome, quantidade FROM produtos ORDER BY id";
$stmt = $mysqli->prepare($sql);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Erro ao executar consulta"]);
    exit;
}
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
echo json_encode($rows);
