<?php
/**
 * api/actions.php
 * Roteador central da API
 */

declare(strict_types=1);

// =====================================================
// Sessão
// =====================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// =====================================================
// Dependências
// =====================================================
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

initLog('actions');

// =====================================================
// Headers
// =====================================================
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
    return is_array($json) ? $json : ($_POST ?? []);
}

function auditoria(array $usuario, string $acao, array $dados = []): void
{
    logInfo('actions', 'Auditoria', [
        'acao' => $acao,
        'usuario' => [
            'id'   => $usuario['id'] ?? null,
            'nome' => $usuario['nome'] ?? 'anon'
        ],
        'dados' => $dados
    ]);
}

// =====================================================
// Includes de domínio
// =====================================================
require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/movimentacoes.php';
require_once __DIR__ . '/relatorios.php';

// =====================================================
// Execução protegida
// =====================================================
try {

    // Limpa TODOS os buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $conn = db();
    $acao = $_REQUEST['acao'] ?? '';
    $body = read_body();

    // 🔐 Auth dentro do try
    require_once __DIR__ . '/auth.php';

    $usuario = $_SESSION['usuario'] ?? [];
    auditoria($usuario, $acao, $body ?: $_GET);

    switch ($acao) {

        // ================= PRODUTOS =================
        case 'listar_produtos':
            $res = produtos_listar($conn);
            json_response(
                $res['sucesso'],
                $res['mensagem'] ?? '',
                $res['dados'] ?? []
            );
            break;

        case 'adicionar_produto':
            $nome = trim($body['nome'] ?? '');
            $qtd  = (int)($body['quantidade'] ?? 0);

            if ($nome === '') {
                json_response(false, 'O nome do produto não pode estar vazio.');
            }

            $res = produtos_adicionar($conn, $nome, $qtd, $usuario['id'] ?? null);
            json_response($res['sucesso'], $res['mensagem'], $res['dados'] ?? null);
            break;

        case 'remover_produto':
            $produto_id = (int)($body['produto_id'] ?? $body['id'] ?? 0);
            $res = produtos_remover($conn, $produto_id, $usuario['id'] ?? null);
            json_response($res['sucesso'], $res['mensagem'], $res['dados'] ?? null);
            break;

        // ================= MOVIMENTAÇÕES =================
        case 'listar_movimentacoes':
            $res = mov_listar($conn, $_GET);
            json_response($res['sucesso'] ?? true, $res['mensagem'] ?? '', $res['dados'] ?? []);
            break;

        case 'registrar_movimentacao':
            $produto_id = (int)($body['produto_id'] ?? 0);
            $tipo       = $body['tipo'] ?? '';
            $quantidade = (int)($body['quantidade'] ?? 0);

            if ($produto_id <= 0 || $quantidade <= 0 || !in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
                json_response(false, 'Dados inválidos para movimentação.');
            }

            $res = mov_registrar($conn, $produto_id, $tipo, $quantidade, $usuario['id']);
            json_response($res['sucesso'], $res['mensagem'], $res['dados'] ?? null);
            break;

        // ================= RELATÓRIOS =================
        case 'relatorio_movimentacoes':
            $res = relatorio($conn, array_merge($_GET, $body));
            json_response($res['sucesso'], $res['mensagem'], $res['dados'] ?? null);
            break;

        default:
            json_response(false, 'Ação inválida ou não informada.');
    }

} catch (Throwable $e) {

    logError(
        'actions',
        'Erro fatal',
        $e->getFile(),
        $e->getLine(),
        $e->getMessage()
    );

    json_response(false, 'Erro interno no servidor.', null, 500);
}
