<?php
// api/log_js.php

header("Content-Type: application/json; charset=utf-8");

// 🔒 Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["sucesso" => false, "mensagem" => "Método não permitido"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(["sucesso" => false, "mensagem" => "JSON inválido"]);
    exit;
}

$logDir  = __DIR__ . "/../public/logs_api";
$logFile = $logDir . "/frontend.log";

if (!is_dir($logDir)) {
    mkdir($logDir, 2775, true);
}

$data = date("Y-m-d H:i:s");

$registro = [
    "hora"   => $data,
    "ip"     => $_SERVER['REMOTE_ADDR'] ?? "desconhecido",
    "agent"  => $_SERVER['HTTP_USER_AGENT'] ?? "desconhecido",
    "origem" => $input['origem'] ?? "frontend",
    "mensagem" => $input['mensagem'] ?? "",
    "arquivo"  => $input['arquivo'] ?? null,
    "linha"    => $input['linha'] ?? null,
    "coluna"   => $input['coluna'] ?? null,
    "stack"    => $input['stack'] ?? null
];

$linhaLog = sprintf(
    "[%s] [JS] %s | Dados: %s\n",
    $data,
    strtoupper($registro['origem']),
    json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

file_put_contents($logFile, $linhaLog, FILE_APPEND | LOCK_EX);

echo json_encode(["sucesso" => true]);
