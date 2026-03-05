<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// =====================================================
// SESSÃO (com Secure automático se estiver em HTTPS)
// =====================================================
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,   // ✅ se estiver em https, cookie Secure
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
// HEADERS / CORS
// =====================================================
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
        // fallback (mesma origem)
        header('Access-Control-Allow-Origin: https://192.168.15.100');
    }
}
set_cors_origin();

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

function coluna_existe_local(mysqli $conn, string $tabela, string $coluna): bool
{
    static $cache = [];
    $db = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '';
    $key = $db . '|' . $tabela . '|' . $coluna;

    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }

    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$key] = $ok;
    return $ok;
}

/**
 * Chama produtos_adicionar sem quebrar versões antigas:
 * - (conn, nome, qtd, usuario_id)
 * - (conn, nome, qtd, usuario_id, preco_custo)
 * - (conn, nome, qtd, usuario_id, preco_custo, preco_venda)
 */
function call_produtos_adicionar(
    mysqli $conn,
    string $nome,
    int $quantidade,
    ?int $usuario_id,
    ?float $preco_custo,
    ?float $preco_venda
): array {
    if (!function_exists('produtos_adicionar')) {
        return ['sucesso' => false, 'mensagem' => 'Função produtos_adicionar não encontrada.'];
    }

    try {
        $rf = new ReflectionFunction('produtos_adicionar');
        $n  = $rf->getNumberOfParameters();

        if ($n <= 4) {
            return produtos_adicionar($conn, $nome, $quantidade, $usuario_id);
        }
        if ($n === 5) {
            return produtos_adicionar($conn, $nome, $quantidade, $usuario_id, $preco_custo);
        }
        return produtos_adicionar($conn, $nome, $quantidade, $usuario_id, $preco_custo, $preco_venda);

    } catch (Throwable $e) {
        logError('actions', 'Falha ao chamar produtos_adicionar via Reflection', [
            'erro' => $e->getMessage(),
            'arquivo' => $e->getFile(),
            'linha' => $e->getLine(),
        ]);
        return ['sucesso' => false, 'mensagem' => 'Erro interno ao adicionar produto.'];
    }
}

function session_touch(): void
{
    // ✅ ajuda a evitar "sessão morre do nada"
    $_SESSION['LAST_ACTIVITY'] = time();
}

// =====================================================
// LOGIN HELPERS (tabela usuarios pode variar)
// =====================================================
function usuario_login(mysqli $conn, string $login, string $senha): array
{
    $login = trim($login);
    if ($login === '' || $senha === '') {
        return ['sucesso' => false, 'mensagem' => 'Informe login e senha.', 'dados' => null];
    }

    // Detecta colunas comuns
    $hasEmail     = coluna_existe_local($conn, 'usuarios', 'email');
    $hasUsuario   = coluna_existe_local($conn, 'usuarios', 'usuario');
    $hasLoginCol  = coluna_existe_local($conn, 'usuarios', 'login');
    $hasNome      = coluna_existe_local($conn, 'usuarios', 'nome');
    $hasNivel     = coluna_existe_local($conn, 'usuarios', 'nivel');
    $hasTipo      = coluna_existe_local($conn, 'usuarios', 'tipo');
    $hasAtivo     = coluna_existe_local($conn, 'usuarios', 'ativo');

    // senha pode ser hash
    $hasSenhaHash = coluna_existe_local($conn, 'usuarios', 'senha_hash');
    $hasSenhaCol  = coluna_existe_local($conn, 'usuarios', 'senha');

    // Monta WHERE flexível
    $conds = [];
    $types = '';
    $params = [];

    if ($hasEmail) {
        $conds[] = 'email = ?';
        $types .= 's';
        $params[] = $login;
    }
    if ($hasUsuario) {
        $conds[] = 'usuario = ?';
        $types .= 's';
        $params[] = $login;
    }
    if ($hasLoginCol) {
        $conds[] = 'login = ?';
        $types .= 's';
        $params[] = $login;
    }

    if (!$conds) {
        return ['sucesso' => false, 'mensagem' => 'Tabela usuarios sem colunas de login (email/usuario/login).', 'dados' => null];
    }

    // Select com fallback
    $selNome  = $hasNome ? 'nome' : ($hasUsuario ? 'usuario' : ($hasLoginCol ? 'login' : "'Usuario'"));
    $selNivel = $hasNivel ? 'nivel' : ($hasTipo ? 'tipo' : "'usuario'");

    $cols = [
        'id',
        "{$selNome} AS nome",
        "{$selNivel} AS nivel",
    ];

    if ($hasSenhaHash) $cols[] = 'senha_hash';
    if ($hasSenhaCol)  $cols[] = 'senha';
    if ($hasAtivo)     $cols[] = 'ativo';

    $where = '(' . implode(' OR ', $conds) . ')';
    if ($hasAtivo) {
        $where .= ' AND ativo = 1';
    }

    $sql = "SELECT " . implode(',', $cols) . " FROM usuarios WHERE {$where} LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$u) {
        return ['sucesso' => false, 'mensagem' => 'Usuário ou senha inválidos.', 'dados' => null];
    }

    // Validação da senha
    $ok = false;

    if ($hasSenhaHash && !empty($u['senha_hash'])) {
        $ok = password_verify($senha, (string)$u['senha_hash']);
    } elseif ($hasSenhaCol && array_key_exists('senha', $u)) {
        // fallback (não recomendado) — mas não quebra seu projeto se estiver assim
        $ok = hash_equals((string)$u['senha'], (string)$senha);
    } else {
        return ['sucesso' => false, 'mensagem' => 'Usuário sem coluna de senha (senha_hash/senha).', 'dados' => null];
    }

    if (!$ok) {
        return ['sucesso' => false, 'mensagem' => 'Usuário ou senha inválidos.', 'dados' => null];
    }

    $usuario = [
        'id'    => (int)$u['id'],
        'nome'  => (string)$u['nome'],
        'nivel' => (string)$u['nivel'],
    ];

    // salva sessão
    $_SESSION['usuario'] = $usuario;
    session_touch();

    return ['sucesso' => true, 'mensagem' => 'Login realizado com sucesso.', 'dados' => ['usuario' => $usuario]];
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

        // ================= AUTH =================

        case 'login': {
            $login = (string)($body['login'] ?? '');
            $senha = (string)($body['senha'] ?? '');

            $res = usuario_login($conn, $login, $senha);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null, ($res['sucesso'] ?? false) ? 200 : 401);
            exit;
        }

        case 'usuario_atual': {
            if (!empty($_SESSION['usuario']) && is_array($_SESSION['usuario'])) {
                session_touch();
                json_response(true, 'OK', ['usuario' => $_SESSION['usuario']]);
                exit;
            }
            json_response(false, 'Usuário não autenticado.', null, 401);
            exit;
        }

        case 'logout': {
            session_unset();
            session_destroy();

            // garante remover cookie
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }

            json_response(true, 'Logout realizado com sucesso.', null);
            exit;
        }

        // ================= PRODUTOS =================

        case 'listar_produtos': {
            require_auth();
            session_touch();

            $res = produtos_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
            exit;
        }

        case 'adicionar_produto': {
            $usuario = require_auth();
            session_touch();

            $nome = trim((string)($body['nome'] ?? ''));
            $qtd  = (int)($body['quantidade'] ?? 0);

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.');
                exit;
            }

            // mantém compatível com versões antigas do produtos_adicionar()
            $res = call_produtos_adicionar($conn, $nome, $qtd, (int)$usuario['id'], null, null);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'criar_produto': {
            $usuario = require_auth();
            session_touch();

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

            $res = call_produtos_adicionar($conn, $nome, $qtd, (int)$usuario['id'], $preco_custo, $preco_venda);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'remover_produto': {
            $usuario = require_auth();
            session_touch();

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
            session_touch();

            $q = trim((string)($_GET['q'] ?? $body['q'] ?? ''));
            $limit = (int)($_GET['limit'] ?? $body['limit'] ?? 10);
            $limit = max(1, min(25, $limit));

            $res = produtos_buscar($conn, $q, $limit);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
            exit;
        }

        case 'produto_resumo': {
            require_auth();
            session_touch();

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
            session_touch();

            $res = mov_listar($conn, $_GET);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
            exit;
        }

        case 'registrar_movimentacao': {
            $usuario = require_auth();
            session_touch();

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

            // ✅ fallback: se não mandou valor_unitario, usa preco_custo como valor_unitario
            if ($valor_unitario === null && $preco_custo !== null) {
                $valor_unitario = $preco_custo;
            }

            // ⚠️ precisa bater com sua mov_registrar(...)
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
            session_touch();

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