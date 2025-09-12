<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

// 📂 Caminho do log
$logFile = __DIR__ . "/debug.log";
function debug_log($msg) {
    global $logFile;
    $data = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$data] usuario.php -> $msg\n", FILE_APPEND);
}

function resposta($logado, $usuario = null) {
    return [
        "sucesso" => $logado,
        "usuario" => $usuario
    ];
}

if (isset($_SESSION["usuario"])) {
    debug_log("Usuário logado: " . json_encode($_SESSION["usuario"]));
    echo json_encode(resposta(true, $_SESSION["usuario"]));
} else {
    debug_log("Nenhum usuário logado");
    echo json_encode(resposta(false, null));
}
