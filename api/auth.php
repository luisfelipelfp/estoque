<?php
// =======================================
// api/auth.php
// Middleware de autenticação
// =======================================

// Sessão e configuração do cookie
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

// Headers padrão + CORS
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100"); // ajuste se acessar de outro host
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Se for uma pré-verificação (CORS preflight)
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Funções utilitárias
$logFile = __DIR__ . "/debug.log";

function debug_log($msg) {
    global $logFile;
    $data = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$data] auth.php -> $msg\n", FILE_APPEND);
}

function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

// 🔒 Verifica se usuário está logado
if (!isset($_SESSION["usuario"])) {
    debug_log("Acesso negado -> usuário não autenticado.");
    echo json_encode(resposta(false, "Usuário não autenticado"));
    exit;
}

// 🔑 Usuário autenticado → disponibiliza em $usuario
$usuario = $_SESSION["usuario"];
debug_log("Usuário autenticado: " . json_encode(["id" => $usuario["id"], "email" => $usuario["email"], "nivel" => $usuario["nivel"]]));
