<?php
// api/buscar.php
header('Content-Type: application/json');
require_once 'db.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '') {
    http_response_code(400);
    echo json_encode(["error" => "Parâmetro q obrigatório"]);
    exit;
}

if (ctype_digit($q)) {
    $id = intval($q);
    $stmt = $mysqli->prepare("SELECT id, nome, quantidade FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);
} else {
    $stmt = $mysqli->prepare("SELECT id, nome, quantidade FROM produtos WHERE nome = ?");
    $stmt->bind_param("s", $q);
}
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
echo json_encode($res ? $res : null);
