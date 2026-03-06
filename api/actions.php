<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// ======================
// SESSÃO
// ======================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // em HTTPS real, colocar true
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// ======================
// DEPENDÊNCIAS
// ======================
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/movimentacoes.php';
require_once __DIR__ . '/relatorios.php';

initLog('actions');

// ======================
// HEADERS
// ======================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function set_cors_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [
        'http://192.168.15.100',
        'https://192.168.15.100',
    ];

    if ($origin && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
    } else {
        header('Access-Control-Allow-Origin: https://192.168.15.100');
    }
}
set_cors_origin();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ======================
// HELPERS
// ======================
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

// ======================
// EXECUÇÃO
// ======================
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

        // ================= AUTH =================

        case 'login': {
            $login = trim((string)($body['login'] ?? ''));
            $senha = (string)($body['senha'] ?? '');

            if ($login === '' || $senha === '') {
                json_response(false, 'Informe login e senha.', null, 400);
                exit;
            }

            $stmt = $conn->prepare("
                SELECT id, nome, email, senha, nivel
                FROM usuarios
                WHERE email = ? OR nome = ?
                LIMIT 1
            ");
            $stmt->bind_param('ss', $login, $login);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                json_response(false, 'Usuário ou senha inválidos.', null, 401);
                exit;
            }

            $hash = (string)$user['senha'];
            if (!password_verify($senha, $hash)) {
                json_response(false, 'Usuário ou senha inválidos.', null, 401);
                exit;
            }

            $_SESSION['usuario'] = [
                'id'    => (int)$user['id'],
                'nome'  => (string)$user['nome'],
                'email' => (string)$user['email'],
                'nivel' => (string)$user['nivel'],
            ];
            $_SESSION['LAST_ACTIVITY'] = time();

            json_response(true, 'OK', ['usuario' => $_SESSION['usuario']]);
            exit;
        }

        case 'usuario_atual': {
            $u = require_auth();
            json_response(true, 'OK', ['usuario' => $u]);
            exit;
        }

        case 'logout': {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"] ?? '',
                    (bool)$params["secure"],
                    (bool)$params["httponly"]
                );
            }
            session_destroy();

            json_response(true, 'OK', null);
            exit;
        }

        // ================= PRODUTOS =================

        case 'listar_produtos': {
            require_auth();
            $res = produtos_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
            exit;
        }

        case 'obter_produto': {
            require_auth();

            $produto_id = (int)($_GET['produto_id'] ?? $body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
                exit;
            }

            $res = produto_obter($conn, $produto_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'adicionar_produto': {
            $usuario = require_auth();

            $nome = trim((string)($body['nome'] ?? ''));
            $qtd  = (int)($body['quantidade'] ?? 0);

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.');
                exit;
            }

            $res = produtos_adicionar($conn, $nome, $qtd, (int)$usuario['id']);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'criar_produto': {
            $usuario = require_auth();

            $nome = trim((string)($body['nome'] ?? ''));
            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.');
                exit;
            }

            $qtd = (int)($body['quantidade'] ?? 0);

            $preco_custo = (isset($body['preco_custo']) && $body['preco_custo'] !== '')
                ? (float)$body['preco_custo']
                : null;

            $preco_venda = (isset($body['preco_venda']) && $body['preco_venda'] !== '')
                ? (float)$body['preco_venda']
                : null;

            $fornecedores = isset($body['fornecedores']) && is_array($body['fornecedores'])
                ? $body['fornecedores']
                : [];

            $res = produtos_adicionar(
                $conn,
                $nome,
                $qtd,
                (int)$usuario['id'],
                $preco_custo,
                $preco_venda,
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'atualizar_produto': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            $nome = trim((string)($body['nome'] ?? ''));
            $qtd = (int)($body['quantidade'] ?? 0);

            $preco_custo = (isset($body['preco_custo']) && $body['preco_custo'] !== '')
                ? (float)$body['preco_custo']
                : 0.0;

            $preco_venda = (isset($body['preco_venda']) && $body['preco_venda'] !== '')
                ? (float)$body['preco_venda']
                : 0.0;

            $fornecedores = isset($body['fornecedores']) && is_array($body['fornecedores'])
                ? $body['fornecedores']
                : [];

            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
                exit;
            }

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
                exit;
            }

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
                exit;
            }

            if ($preco_custo < 0 || $preco_venda < 0) {
                json_response(false, 'Preços inválidos.', null, 400);
                exit;
            }

            $res = produtos_atualizar(
                $conn,
                $produto_id,
                $nome,
                $qtd,
                $preco_custo,
                $preco_venda,
                (int)$usuario['id'],
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'remover_produto': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.');
                exit;
            }

            $res = produtos_remover($conn, $produto_id, (int)$usuario['id']);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'buscar_produtos': {
            require_auth();

            $q = trim((string)($_GET['q'] ?? $body['q'] ?? ''));
            $limit = (int)($_GET['limit'] ?? $body['limit'] ?? 10);
            $limit = max(1, min(25, $limit));

            $res = produtos_buscar($conn, $q, $limit);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'produto_resumo': {
            require_auth();

            $produto_id = (int)($_GET['produto_id'] ?? $body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.');
                exit;
            }

            $res = produto_resumo($conn, $produto_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        // ================= MOVIMENTAÇÕES =================

        case 'listar_movimentacoes': {
            require_auth();

            $res = mov_listar($conn, $_GET);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
            exit;
        }

        case 'registrar_movimentacao': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            $tipo       = (string)($body['tipo'] ?? '');
            $quantidade = (int)($body['quantidade'] ?? 0);

            $preco_custo = isset($body['preco_custo']) && $body['preco_custo'] !== ''
                ? (float)$body['preco_custo']
                : null;

            $valor_unitario = isset($body['valor_unitario']) && $body['valor_unitario'] !== ''
                ? (float)$body['valor_unitario']
                : null;

            $observacao = isset($body['observacao']) && trim((string)$body['observacao']) !== ''
                ? trim((string)$body['observacao'])
                : null;

            if ($produto_id <= 0 || $quantidade <= 0) {
                json_response(false, 'Dados inválidos.');
                exit;
            }

            if (!in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
                json_response(false, 'Tipo inválido.');
                exit;
            }

            if ($valor_unitario === null && $preco_custo !== null) {
                $valor_unitario = $preco_custo;
            }

            $res = mov_registrar(
                $conn,
                $produto_id,
                $tipo,
                $quantidade,
                (int)$usuario['id'],
                $preco_custo,
                $valor_unitario,
                $observacao
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        // ================= RELATÓRIOS =================

        case 'relatorio':
        case 'relatorios':
        case 'relatorio_movimentacoes': {
            require_auth();

            $filtros = array_merge($_GET, $body);
            $res = relatorio($conn, $filtros);

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
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