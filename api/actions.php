<?php
// =======================================
// api/actions.php
// Roteador de ações
// =======================================

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

require_once __DIR__ . "/utils.php";

// Headers padrão + CORS
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// 🔧 DEBUG PHP
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
error_reporting(E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");

function read_body() {
    $body = file_get_contents("php://input");
    $data = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        return $data;
    }
    return $_POST ?? [];
}

// Função de log de auditoria
function auditoria_log($usuario, $acao, $dados = []) {
    $logFile = __DIR__ . "/debug.log";
    $data = date("Y-m-d H:i:s");
    $uid  = $usuario["id"] ?? "anon";
    $nome = $usuario["nome"] ?? "desconhecido";
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
    $linha = "[AUDITORIA][$data][user:$uid|$nome] ação='$acao' dados=$json\n";
    file_put_contents($logFile, $linha, FILE_APPEND);
}

// Conexão e dependências
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/relatorios.php";
require_once __DIR__ . "/produtos.php";

// Middleware de autenticação
require_once __DIR__ . "/auth.php"; 
$usuario_id    = $usuario["id"]    ?? null;
$usuario_nivel = $usuario["nivel"] ?? null;

// Ação
$acao = $_REQUEST["acao"] ?? "";
$body = read_body();

// Log de auditoria
auditoria_log($usuario, $acao, $body ?: $_GET);

try {
    switch ($acao) {
        // ... (restante do switch sem alterações, só usa resposta() de utils.php)
    }
} catch (Throwable $e) {
    error_log("Erro global: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(resposta(false, "Erro interno no servidor."));
}
