<?php
// =======================================
// api/actions.php — Roteador central
// =======================================

// Sessão
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);
if (session_status() === PHP_SESSION_NONE) session_start();

// Utils
require_once __DIR__ . "/utils.php";

// Headers + CORS
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Logs
ini_set("display_errors", 0);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");

// Funções auxiliares
function read_body() {
    $body = file_get_contents("php://input");
    $json = json_decode($body, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($json)) ? $json : ($_POST ?? []);
}

function auditoria_log($usuario, $acao, $dados = []) {
    $file = __DIR__ . "/debug.log";
    $time = date("Y-m-d H:i:s");
    $uid = $usuario["id"] ?? "anon";
    $nome = $usuario["nome"] ?? "desconhecido";
    $linha = "[AUDITORIA][$time][user:$uid|$nome] ação='$acao' dados=" . json_encode($dados, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($file, $linha, FILE_APPEND);
}

// Dependências
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/relatorios.php";
require_once __DIR__ . "/produtos.php";

$conn = db();
$acao = $_REQUEST["acao"] ?? "";
$body = read_body();

// Login / Logout
if ($acao === "login")  { require __DIR__ . "/login.php"; exit; }
if ($acao === "logout") { require __DIR__ . "/logout.php"; exit; }

// Autenticação obrigatória
require_once __DIR__ . "/auth.php";
$usuario = $_SESSION["usuario"] ?? [];
auditoria_log($usuario, $acao, $body ?: $_GET);

try {
    switch ($acao) {

        // ------------------------
        // Produtos
        // ------------------------
        case "listar_produtos":
            // 🔧 Ajuste: formato compatível com relatorios.js
            $dados = produtos_listar($conn);
            echo json_encode([
                "sucesso" => true,
                "dados" => $dados
            ], JSON_UNESCAPED_UNICODE);
            break;

        case "adicionar_produto":
            $nome = trim($body["nome"] ?? "");
            $qtd  = (int)($body["quantidade"] ?? 0);
            echo json_encode(produtos_adicionar($conn, $nome, $qtd, $usuario["id"] ?? null));
            break;

        case "remover_produto":
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);
            echo json_encode(produtos_remover($conn, $produto_id, $usuario["id"] ?? null));
            break;

        // ------------------------
        // Movimentações
        // ------------------------
        case "listar_movimentacoes":
            echo json_encode(mov_listar($conn, $_GET));
            break;

        case "registrar_movimentacao":
            $produto_id = (int)($body["produto_id"] ?? 0);
            $tipo = $body["tipo"] ?? "";
            $quantidade = (int)($body["quantidade"] ?? 0);
            if ($produto_id <= 0 || $quantidade <= 0 || !in_array($tipo, ["entrada", "saida", "remocao"])) {
                echo json_encode(resposta(false, "Dados inválidos para movimentação."));
            } else {
                echo json_encode(mov_registrar($conn, $produto_id, $tipo, $quantidade, $usuario["id"] ?? null));
            }
            break;

        // ------------------------
        // Usuários
        // ------------------------
        case "listar_usuarios":
            $sql = "SELECT id, nome FROM usuarios ORDER BY nome";
            $res = $conn->query($sql);
            $dados = [];
            while ($r = $res->fetch_assoc()) $dados[] = $r;
            echo json_encode([
                "sucesso" => true,
                "dados" => $dados
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ------------------------
        // Relatórios
        // ------------------------
        case "relatorio_movimentacoes":
            $filtros = array_merge($_GET, $body);
            echo json_encode(relatorio($conn, $filtros));
            break;

        // ------------------------
        // Exportação
        // ------------------------
        case "exportar_relatorio":
            require_once __DIR__ . "/exportar.php";
            echo json_encode(exportar_relatorio($conn, $_GET));
            break;

        default:
            echo json_encode(resposta(false, "Ação inválida ou não informada."));
    }

} catch (Throwable $e) {
    error_log("Erro global: " . $e->getMessage());
    echo json_encode(resposta(false, "Erro interno no servidor."));
}
