<?php
// =======================================
// api/actions.php
// Roteador de ações (produtos, usuários, relatórios...)
// =======================================

// Sessão e configuração do cookie
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/",
    "domain"   => "",        // usa o domínio atual
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

// 🔧 DEBUG PHP
ini_set('display_errors', 0); // ❌ não mostrar no navegador (quebra JSON)
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");

// Funções utilitárias
function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

function read_body() {
    $body = file_get_contents("php://input");
    $data = json_decode($body, true);

    // Se não veio JSON, retorna $_POST (caso FormData)
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

// 🔑 Middleware de autenticação
require_once __DIR__ . "/auth.php"; // já define $usuario se autenticado
$usuario_id    = $usuario["id"]    ?? null;
$usuario_nivel = $usuario["nivel"] ?? null;

// Ação pode vir de GET, POST ou JSON
$acao = $_REQUEST["acao"] ?? "";

// 🔍 Corpo pode vir como JSON ou FormData
$body = read_body();

// Sempre logar tentativas de ação
auditoria_log($usuario, $acao, $body ?: $_GET);

try {
    switch ($acao) {
        // ======================
        // PRODUTOS
        // ======================
        case "listar_produtos":
            $incluir_inativos = isset($_GET["inativos"]) && $_GET["inativos"] == "1";
            $produtos = produtos_listar(db(), $incluir_inativos);
            echo json_encode(resposta(true, "", $produtos));
            break;

        case "adicionar_produto":
            $nome = trim($body["nome"] ?? "");
            $quantidade = (int)($body["quantidade"] ?? 0);

            $res = produtos_adicionar(db(), $nome, $quantidade, $usuario_id);
            echo json_encode($res);
            break;

        case "remover_produto":
            if ($usuario_nivel !== "admin") {
                echo json_encode(resposta(false, "Ação permitida apenas para administradores."));
                break;
            }

            $id = (int)($body["id"] ?? $_GET["id"] ?? 0);
            if ($id <= 0) {
                echo json_encode(resposta(false, "ID inválido."));
                break;
            }

            $res = mov_remover(db(), $id, $usuario_id);
            echo json_encode($res);
            break;

        // ======================
        // USUÁRIOS
        // ======================
        case "listar_usuarios":
            $conn = db();
            $res = $conn->query("SELECT id, nome, email, nivel, criado_em FROM usuarios ORDER BY nome ASC");
            $usuarios = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    unset($row['senha']); // não expõe senha
                    $usuarios[] = $row;
                }
                $res->free();
            }
            echo json_encode(resposta(true, "", $usuarios));
            break;

        // ======================
        // MOVIMENTAÇÕES & RELATÓRIOS
        // ======================
        case "listar_movimentacoes":
        case "listar_relatorios":
            $filtros = [
                "produto_id"  => $_GET["produto_id"] ?? null,
                "tipo"        => $_GET["tipo"] ?? null,
                "usuario_id"  => $_GET["usuario_id"] ?? null,
                "usuario"     => $_GET["usuario"] ?? null,
                "data_inicio" => $_GET["data_inicio"] ?? ($_GET["data_ini"] ?? null),
                "data_fim"    => $_GET["data_fim"] ?? null,
                "pagina"      => (int)($_GET["pagina"] ?? 1),
                "limite"      => (int)($_GET["limite"] ?? 50),
            ];

            $rel = relatorio(db(), $filtros);
            echo json_encode($rel);
            break;

        case "registrar_movimentacao":
            $produto_id = (int)($body["produto_id"] ?? 0);
            $tipo = $body["tipo"] ?? "";
            $quantidade = (int)($body["quantidade"] ?? 0);

            $res = mov_registrar(db(), $produto_id, $tipo, $quantidade, $usuario_id);
            echo json_encode($res);
            break;

        // ======================
        // EXPORT (PLACEHOLDERS)
        // ======================
        case "export_relatorio_csv":
            echo json_encode(resposta(false, "Exportação CSV não implementada. (TODO)"));
            break;

        case "export_relatorio_pdf":
            echo json_encode(resposta(false, "Exportação PDF não implementada. (TODO)"));
            break;

        // ======================
        // LOG DE ERROS JS
        // ======================
        case "log_js_error":
            $mensagem = $body["mensagem"] ?? "Erro JS desconhecido";
            $arquivo  = $body["arquivo"] ?? "";
            $linha    = $body["linha"] ?? "";
            $coluna   = $body["coluna"] ?? "";
            $stack    = $body["stack"] ?? "";
            $origem   = $body["origem"] ?? "desconhecida";

            $log = "[JS ERROR][$origem] $mensagem em $arquivo:$linha:$coluna | Stack: $stack";
            error_log($log);

            echo json_encode(resposta(true, "Erro JS registrado no log."));
            break;

        // ======================
        // DEFAULT
        // ======================
        default:
            echo json_encode(resposta(false, "Ação inválida."));
            break;
    }

} catch (Throwable $e) {
    error_log("Erro global: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(resposta(false, "Erro interno no servidor."));
}
