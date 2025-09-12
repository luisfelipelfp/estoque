<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

// 📂 Caminho do log
$logFile = __DIR__ . "/debug.log";
function debug_log($msg) {
    global $logFile;
    $data = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$data] logout.php -> $msg\n", FILE_APPEND);
}

function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

// 🔒 encerra a sessão
debug_log("Iniciando logout...");
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

debug_log("Sessão destruída com sucesso");
echo json_encode(resposta(true, "Logout realizado."));
