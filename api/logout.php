<?php
// =======================================
// Logout do sistema
// =======================================

// Sessão e configuração do cookie
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/",
    "domain"   => "",        
    "secure"   => false,     
    "httponly" => true,
    "samesite" => "Lax"
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Headers padrão + CORS
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100"); 
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

function debug_log($msg) {
    $data = date("Y-m-d H:i:s");
    file_put_contents(__DIR__ . "/debug.log", "[$data] logout.php -> $msg\n", FILE_APPEND);
}

function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

// Logout
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
echo json_encode(resposta(true, "Logout realizado com sucesso."));
