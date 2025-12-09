<?php
// api/usuario.php — Compatível com PHP 8.5

// Sessão segura
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/",
    "secure"   => false,      // alterar para true se usar HTTPS
    "httponly" => true,
    "samesite" => "Lax"
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");

// 📂 Caminho do log
$logFile = __DIR__ . "/debug.log";

function debug_log(string $msg): void {
    global $logFile;
    $data = date("Y-m-d H:i:s");
    @file_put_contents($logFile, "[$data] usuario.php -> $msg\n", FILE_APPEND);
}

function resposta(bool $logado, ?array $usuario = null): array {
    return [
        "sucesso" => $logado,
        "usuario" => $usuario
    ];
}

// 🔍 Verifica login
if (!empty($_SESSION["usuario"]) && is_array($_SESSION["usuario"])) {
    debug_log("Usuário logado: " . json_encode($_SESSION["usuario"], JSON_UNESCAPED_UNICODE));
    echo json_encode(resposta(true, $_SESSION["usuario"]), JSON_UNESCAPED_UNICODE);
} else {
    debug_log("Nenhum usuário logado");
    echo json_encode(resposta(false, null), JSON_UNESCAPED_UNICODE);
}
