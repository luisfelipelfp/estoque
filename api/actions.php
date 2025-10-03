<?php
// =======================================
// api/actions.php
// Roteador central de ações
// =======================================

// Configura sessão
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

// Utils (resposta, etc.)
require_once __DIR__ . "/utils.php";

// Headers padrão + CORS
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100"); // ajuste em prod
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Pré-flight
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// 🔧 DEBUG / LOG
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
error_reporting(E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");

// Leitura do corpo JSON ou POST
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

// Dependências
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/relatorios.php";
require_once __DIR__ . "/produtos.php";

// Conexão
$conn = db();

// Identificação da ação
$acao = $_REQUEST["acao"] ?? "";
$body = read_body();

// ============================
// Login e Logout não exigem auth
// ============================
if ($acao === "login") {
    require __DIR__ . "/login.php";
    exit;
}
if ($acao === "logout") {
    require __DIR__ . "/logout.php";
    exit;
}

// ============================
// Middleware de autenticação
// ============================
require_once __DIR__ . "/auth.php"; 
$usuario = $_SESSION["usuario"] ?? null;
$usuario_id    = $usuario["id"]    ?? null;
$usuario_nivel = $usuario["nivel"] ?? null;

// Log de auditoria
auditoria_log($usuario, $acao, $body ?: $_GET);

try {
    switch ($acao) {
        // ============================
        // Produtos
        // ============================
        case "listar_produtos":
            echo json_encode(resposta(true, "", produtos_listar($conn)));
            break;

        case "adicionar_produto":
            $nome  = trim($body["nome"] ?? "");
            $qtd   = (int)($body["quantidade"] ?? 0);
            echo json_encode(produtos_adicionar($conn, $nome, $qtd, $usuario_id));
            break;

        case "remover_produto":
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);

            if ($produto_id <= 0) {
                echo json_encode(resposta(false, "ID inválido."));
            } else {
                echo json_encode(produtos_remover($conn, $produto_id, $usuario_id));
            }
            break;

        // ============================
        // Movimentações
        // ============================
        case "listar_movimentacoes":
            echo json_encode(mov_listar($conn, $_GET));
            break;

        case "registrar_movimentacao":
            $produto_id = (int)($body["produto_id"] ?? 0);
            $tipo       = $body["tipo"] ?? "";
            $quantidade = (int)($body["quantidade"] ?? 0);

            if ($produto_id <= 0 || $quantidade <= 0 || !in_array($tipo, ["entrada", "saida", "remocao"])) {
                echo json_encode(resposta(false, "Dados inválidos para movimentação."));
            } else {
                echo json_encode(mov_registrar($conn, $produto_id, $tipo, $quantidade, $usuario_id));
            }
            break;

        // ============================
        // Relatórios
        // ============================
        case "relatorio_movimentacoes":
            $filtros = array_merge($_GET, $body);
            echo json_encode(relatorio($conn, $filtros));
            break;

        // ============================
        // Default
        // ============================
        default:
            echo json_encode(resposta(false, "Ação inválida ou não informada."));
    }
} catch (Throwable $e) {
    error_log("Erro global: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(resposta(false, "Erro interno no servidor."));
}
