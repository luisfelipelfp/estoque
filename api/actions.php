<?php
/**
 * api/actions.php
 * Roteador central da API
 * Compatível PHP 8.2+
 */

declare(strict_types=1);

// =====================================================
// ERROS
// =====================================================
error_reporting(E_ALL);
ini_set('display_errors', '0');

// =====================================================
// SESSÃO
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
// DEPENDÊNCIAS
// =====================================================
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/movimentacoes.php';
require_once __DIR__ . '/relatorios.php';

initLog('actions');

// =====================================================
// HEADERS
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
// HELPERS
// =====================================================
function read_body(): array
{
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

function require_auth(): array
{
    if (empty($_SESSION['usuario']) || !is_array($_SESSION['usuario'])) {
        json_response(false, 'Usuário não autenticado.', null, 401);
        exit;
    }
    return $_SESSION['usuario'];
}

// =====================================================
// EXECUÇÃO
// =====================================================
try {

    $conn = db();
    $acao = $_REQUEST['acao'] ?? '';
    $body = read_body();

    logInfo('actions', 'Requisição recebida', [
        'acao' => $acao,
        'body' => $body,
        'get'  => $_GET
    ]);

    switch ($acao) {

        // ================= PRODUTOS =================

        case 'listar_produtos': {
            require_auth();
            $res = produtos_listar($conn);
            json_response(
                $res['sucesso'],
                $res['mensagem'] ?? '',
                $res['dados'] ?? []
            );
            exit;
        }

        case 'adicionar_produto': {
            $usuario = require_auth();

            $nome = trim($body['nome'] ?? '');
            $qtd  = (int)($body['quantidade'] ?? 0);

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.');
                exit;
            }

            $res = produtos_adicionar($conn, $nome, $qtd, $usuario['id']);
            json_response($res['sucesso'], $res['mensagem'], $res['dados'] ?? null);
            exit;
        }

        case 'remover_produto': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.');
                exit;
            }

            $res = produtos_remover($conn, $produto_id, $usuario['id']);
            json_response($res['sucesso'], $res['mensagem'], $res['dados'] ?? null);
            exit;
        }

        // ================= MOVIMENTAÇÕES =================

        case 'listar_movimentacoes': {
            require_auth();
            $res = mov_listar($conn, $_GET);
            json_response(
                $res['sucesso'] ?? true,
                $res['mensagem'] ?? '',
                $res['dados'] ?? []
            );
            exit;
        }

        case 'registrar_movimentacao': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            $tipo       = $body['tipo'] ?? '';
            $quantidade = (int)($body['quantidade'] ?? 0);

            if (
                $produto_id <= 0 ||
                $quantidade <= 0 ||
                !in_array($tipo, ['entrada', 'saida'], true)
            ) {
                json_response(false, 'Dados inválidos.');
                exit;
            }

            $res = mov_registrar(
                $conn,
                $produto_id,
                $tipo,
                $quantidade,
                $usuario['id']
            );

            json_response($res['sucesso'], $res['mensagem'], $res['dados'] ?? null);
            exit;
        }

        // ================= RELATÓRIOS =================

        case 'relatorio_movimentacoes': {
            require_auth();
            $res = relatorio($conn, array_merge($_GET, $body));
            json_response($res['sucesso'], $res['mensagem'], $res['dados'] ?? null);
            exit;
        }

        default:
            json_response(false, 'Ação inválida.');
            exit;
    }

} catch (Throwable $e) {

    logError('actions', 'Erro fatal', [
        'arquivo' => $e->getFile(),
        'linha'   => $e->getLine(),
        'erro'    => $e->getMessage()
    ]);

    json_response(false, 'Erro interno no servidor.', null, 500);
    exit;
}
