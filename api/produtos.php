<?php
// =======================================
// produtos.php — Roteador central (corrigido e compatível com PHP 8.5)
// =======================================

// ----------------------
// Configuração de sessão
// ----------------------
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

// ----------------------
// Cabeçalhos gerais
// ----------------------
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

ini_set("display_errors", 0);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");

// =======================================
// Funções auxiliares
// =======================================
function read_body() {
    $body = file_get_contents("php://input");
    $json = json_decode($body, true);

    return (json_last_error() === JSON_ERROR_NONE && is_array($json))
        ? $json
        : ($_POST ?? []);
}

function auditoria_log($usuario, $acao, $dados = []) {
    $file = __DIR__ . "/debug.log";
    $time = date("Y-m-d H:i:s");
    $uid  = $usuario["id"]   ?? "anon";
    $nome = $usuario["nome"] ?? "desconhecido";

    $linha = "[AUDITORIA][$time][user:$uid|$nome] ação='$acao' dados="
        . json_encode($dados, JSON_UNESCAPED_UNICODE) . "\n";

    file_put_contents($file, $linha, FILE_APPEND);
}

// =======================================
// Dependências obrigatórias
// =======================================
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/relatorios.php";
require_once __DIR__ . "/produtos_funcoes.php"; // <<< renomeado para evitar incluir o próprio arquivo

$conn  = db();
$acao  = $_REQUEST["acao"] ?? "";
$body  = read_body();

// Rotas de login/logout antes de exigir autenticação
if ($acao === "login")  { require __DIR__ . "/login.php"; exit; }
if ($acao === "logout") { require __DIR__ . "/logout.php"; exit; }

// Autenticação obrigatória
require_once __DIR__ . "/auth.php";

$usuario = $_SESSION["usuario"] ?? [];
auditoria_log($usuario, $acao, $body ?: $_GET);

try {
    ob_clean();

    switch ($acao) {

        case "listar_produtos":
            $res = produtos_listar($conn);
            json_response($res["sucesso"], $res["mensagem"], ["produtos" => $res["dados"]]);
            break;

        case "adicionar_produto":
            $nome = trim($body["nome"] ?? "");
            $qtd  = (int)($body["quantidade"] ?? 0);

            if ($nome === "") {
                json_response(false, "O nome do produto não pode estar vazio.");
            }

            $res = produtos_adicionar($conn, $nome, $qtd, $usuario["id"] ?? null);
            json_response($res["sucesso"], $res["mensagem"], $res["dados"] ?? null);
            break;

        case "remover_produto":
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);
            $res = produtos_remover($conn, $produto_id, $usuario["id"] ?? null);
            json_response($res["sucesso"], $res["mensagem"], $res["dados"] ?? null);
            break;

        case "listar_movimentacoes":
            $res = mov_listar($conn, $_GET);
            json_response($res["sucesso"], $res["mensagem"], $res["dados"]);
            break;

        case "registrar_movimentacao":
            $produto_id = (int)($body["produto_id"] ?? 0);
            $tipo       = $body["tipo"] ?? "";
            $quantidade = (int)($body["quantidade"] ?? 0);

            if ($produto_id <= 0 || $quantidade <= 0 || !in_array($tipo, ["entrada", "saida", "remocao"])) {
                json_response(false, "Dados inválidos para movimentação.");
            }

            $res = mov_registrar($conn, $produto_id, $tipo, $quantidade, $usuario["id"] ?? null);
            json_response($res["sucesso"], $res["mensagem"], $res["dados"] ?? null);
            break;

        case "listar_usuarios":
            $sql = "SELECT id, nome FROM usuarios ORDER BY nome";
            $res = $conn->query($sql);
            $lista = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            json_response(true, "Usuários listados com sucesso.", $lista);
            break;

        case "relatorio_movimentacoes":
            $filtros = array_merge($_GET, $body);
            $res = relatorio($conn, $filtros);
            json_response($res["sucesso"], $res["mensagem"], $res["dados"] ?? []);
            break;

        case "exportar_relatorio":
            require_once __DIR__ . "/exportar.php";
            $res = exportar_relatorio($conn, $_GET);
            json_response($res["sucesso"], $res["mensagem"], $res["dados"] ?? null);
            break;

        default:
            json_response(false, "Ação inválida ou não informada.");
    }

} catch (Throwable $e) {
    error_log("Erro global em produtos.php: " . $e->getMessage() . " Linha: " . $e->getLine());
    json_response(false, "Erro interno no servidor.");
}
