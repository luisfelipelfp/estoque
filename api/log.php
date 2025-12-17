<?php
// api/log.php

declare(strict_types=1);

// Ativa log e define arquivo
ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/debug.log");

// Cabeçalho JSON
header("Content-Type: application/json; charset=utf-8");

// Lê o corpo da requisição
$body = file_get_contents("php://input");

// Decodifica JSON da requisição
$data = json_decode($body, true);

// Garante que sempre seja um array
if (!is_array($data)) {
    $data = ["raw" => $body];
}

// Monta mensagem
$mensagem = "[JS] " . json_encode($data, JSON_UNESCAPED_UNICODE);

// Grava no log
error_log($mensagem);

// Resposta
echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);
