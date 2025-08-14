<?php
// api/db.php
// Ajuste credenciais conforme necessário (em produção use variáveis de ambiente)
$DB_HOST = "192.168.15.100";
$DB_USER = "root";
$DB_PASS = "#Shakka01";
$DB_NAME = "estoque_db";

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(["error" => "Falha na conexão com o banco: " . $mysqli->connect_error]);
    exit;
}
$mysqli->set_charset("utf8mb4");
