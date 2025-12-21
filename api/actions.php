<?php
/**
 * api/actions.php
 * Roteador central da API
 * Compatível com PHP 8.2+ / 8.5
 */

declare(strict_types=1);

// =====================================================
// Sessão
// =====================================================
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false, // true em HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================================
// Dependências base
// =====================================================
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

initLog('actions');

// =====================================================
// Headers
// =====================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://192.168.15.100');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// =====================================================
// Helpers
// =====================================================
function read_body(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        return $json;
    }

    return $_POST ?? [];
}

function auditoria(array $usuario, string $acao, array $dados = []): void
{
    logInfo('actions', 'Auditoria', [
        'acao' => $acao,
        'usuario' => [
            'id'   => $usuario['id']   ?? null,
            'nome' => $usuario['nome'] ?? 'anon'
        ],
        'dados' => $dados
    ]);
}

// =====================================================
// Includes da API
// =====================================================
require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/movimentacoes.php';
require_once __DIR__ . '/relatorios.php';

// =====================================================
// Bootstrap
// =====================================================
$conn = db();
$acao = $_REQUEST['acao'] ?? '';
$body = read_body();

// =====================================================
// Autenticação obrigatória
// =====================================================
require_once __DIR__ . '/auth.php';

$usuario = $_SESSION['usuario'] ?? [];
auditoria($usuario, $acao, $body ?: $_GET);

// =====================================================
// Execução
// =====================================================
try {

    if (ob_get_level() > 0) {
        ob_clean();
    }

    switch ($acao) {

        // ================= PRODUTOS =================
        case 'listar_produtos': {

            $res = produtos_listar($conn);

            json_response(
                $res['sucesso'] ?? false,
                $res['mensagem'] ?? '',
                $res['dados'] ?? null
            );
            break;
        }

        case 'adicionar_produto': {

            $nome = trim($body['nome'] ?? '');
            $qtd  = (int)($body['quantidade'] ?? 0);

            if ($nome === '') {
                json_response(false, 'O nome do produto não pode estar vazio.');
            }

            $res = produtos_adicionar(
                $conn,
                $nome,
                $qtd,
                $usuario['id'] ?? null
            );

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? null
            );
            break;
        }

        case 'remover_produto': {

            $produto_id = (int)($body['produto_id'] ?? $body['id'] ?? 0);

            $res = produtos_remover(
                $conn,
                $produto_id,
                $usuario['id'] ?? null
            );

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? null
            );
            break;
        }

        // ================= MOVIMENTAÇÕES =================
        case 'listar_movimentacoes': {

            $res = mov_listar($conn, $_GET);

            json_response(
                $res['sucesso'] ?? true,
                $res['mensagem'] ?? '',
                $res['dados'] ?? $res
            );
            break;
        }

        case 'registrar_movimentacao': {

            $produto_id = (int)($body['produto_id'] ?? 0);
            $tipo       = $body['tipo'] ?? '';
            $quantidade = (int)($body['quantidade'] ?? 0);

            if (
                $produto_id <= 0 ||
                $quantidade <= 0 ||
                !in_array($tipo, ['entrada', 'saida', 'remocao'], true)
            ) {
                json_response(false, 'Dados inválidos para movimentação.');
            }

            $res = mov_registrar(
                $conn,
                $produto_id,
                $tipo,
                $quantidade,
                $usuario['id']
            );

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? null
            );
            break;
        }

        // ================= USUÁRIOS =================
        case 'listar_usuarios': {

            $res = $conn->query('SELECT id, nome FROM usuarios ORDER BY nome');
            $dados = [];

            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $dados[] = $r;
                }
            }

            json_response(true, '', $dados);
            break;
        }

        // ================= RELATÓRIOS =================
        case 'relatorio_movimentacoes': {

            $filtros = array_merge($_GET, $body);
            $res = relatorio($conn, $filtros);

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? null
            );
            break;
        }

        default:
            json_response(false, 'Ação inválida ou não informada.');
    }

} catch (Throwable $e) {

    logError('actions', 'Erro global', [
        'arquivo' => $e->getFile(),
        'linha'   => $e->getLine(),
        'erro'    => $e->getMessage()
    ]);

    json_response(false, 'Erro interno no servidor.');
}
