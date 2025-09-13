<?php
// api/log.php
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");
header("Content-Type: application/json; charset=utf-8");

$body = file_get_contents("php://input");
$data = json_decode($body, true) ?? [];

$mensagem = "[JS] " . json_encode($data, JSON_UNESCAPED_UNICODE);
error_log($mensagem);

echo json_encode(["ok" => true]);
