<?php
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["sucesso" => false]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(["sucesso" => false]);
    exit;
}

$logDir  = __DIR__ . "/../logs_api";
$logFile = $logDir . "/frontend.log";

if (!is_dir($logDir)) {
    mkdir($logDir, 2775, true);
}

$registro = [
    "hora" => date("Y-m-d H:i:s"),
    "ip" => $_SERVER['REMOTE_ADDR'] ?? "desconhecido",
    "agent" => $_SERVER['HTTP_USER_AGENT'] ?? "desconhecido",
    "dados" => $input
];

file_put_contents(
    $logFile,
    json_encode($registro, JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

echo json_encode(["sucesso" => true]);
