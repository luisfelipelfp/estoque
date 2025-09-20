<?php
// =======================================
// Sessão e configuração
// =======================================
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/",
    "domain"   => "",        // usa o domínio atual (ajuste se necessário)
    "secure"   => false,     // true se usar HTTPS
    "httponly" => true,
    "samesite" => "Lax"
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =======================================
// Headers padrão + CORS
// =======================================
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100"); // ajuste se acessar de outro host
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Se for uma pré-verificação (CORS preflight)
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =======================================
// Utilitários
// =======================================
$logFile = __DIR__ . "/debug.log";

function debug_log($msg) {
    global $logFile;
    $data = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$data] logout.php -> $msg\n", FILE_APPEND);
}

function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

// =======================================
// Logout
// =======================================
debug_log("Iniciando logout...");

// Limpa todas as variáveis da sessão
$_SESSION = [];

// Remove cookie de sessão se existir
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destrói sessão
session_destroy();

debug_log("Sessão destruída com sucesso");

echo json_encode(resposta(true, "Logout realizado com sucesso."));
