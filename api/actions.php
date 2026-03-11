<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const SESSION_TIMEOUT_SECONDS = 7200; // 2 horas

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auditoria.php';

require_once __DIR__ . '/fornecedores.php';
require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/movimentacoes.php';
require_once __DIR__ . '/relatorios.php';
require_once __DIR__ . '/usuarios.php';

initLog('actions');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

register_shutdown_function(function (): void {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    logError('actions', 'Fatal shutdown', [
        'type'    => $error['type'] ?? null,
        'message' => $error['message'] ?? '',
        'file'    => $error['file'] ?? '',
        'line'    => $error['line'] ?? 0,
    ]);

    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro fatal no servidor.',
        'debug' => [
            'message' => (string)($error['message'] ?? ''),
            'file'    => (string)($error['file'] ?? ''),
            'line'    => (int)($error['line'] ?? 0),
            'type'    => (int)($error['type'] ?? 0),
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

function set_cors_origin(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

    $allowed = [
        'http://192.168.15.100',
        'https://192.168.15.100',
        'http://localhost',
        'http://127.0.0.1',
    ];

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        return;
    }

    header('Access-Control-Allow-Origin: https://192.168.15.100');
}
set_cors_origin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function lower_text(string $value): string
{
    $value = trim($value);

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function read_body(): array
{
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    $raw = file_get_contents('php://input');

    if (str_contains($contentType, 'application/json')) {
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    return [];
}

function get_action(array $body): string
{
    $acao = $_GET['acao'] ?? $_POST['acao'] ?? $body['acao'] ?? '';
    return trim((string)$acao);
}

function sanitize_for_log(array $data): array
{
    $maskedKeys = [
        'senha',
        'password',
        'token',
        'access_token',
        'refresh_token'
    ];

    $sanitized = $data;

    array_walk_recursive($sanitized, function (&$value, $key) use ($maskedKeys): void {
        if (in_array((string)$key, $maskedKeys, true)) {
            $value = '***';
        }
    });

    return $sanitized;
}

function destroy_user_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

function apply_session_timeout(string $acao): void
{
    $publicActions = [
        'login',
        'logout',
    ];

    if (in_array($acao, $publicActions, true)) {
        return;
    }

    if (!empty($_SESSION['usuario']) && isset($_SESSION['LAST_ACTIVITY'])) {
        $elapsed = time() - (int)$_SESSION['LAST_ACTIVITY'];

        if ($elapsed > SESSION_TIMEOUT_SECONDS) {
            destroy_user_session();
            json_response(false, 'Sessão expirada. Faça login novamente.', null, 401);
        }
    }

    if (!empty($_SESSION['usuario'])) {
        $_SESSION['LAST_ACTIVITY'] = time();
    }
}

function require_auth(): array
{
    if (empty($_SESSION['usuario']) || !is_array($_SESSION['usuario'])) {
        json_response(false, 'Usuário não autenticado.', null, 401);
    }

    $_SESSION['LAST_ACTIVITY'] = time();

    return $_SESSION['usuario'];
}

function normalizar_login(string $login): string
{
    return lower_text($login);
}

function normalizar_fornecedores_payload(mysqli $conn, mixed $fornecedoresRaw): array
{
    if (!is_array($fornecedoresRaw) || empty($fornecedoresRaw)) {
        return [];
    }

    $normalizados = [];
    $idsUsados = [];

    foreach ($fornecedoresRaw as $item) {
        if (!is_array($item)) {
            continue;
        }

        $fornecedorId = (int)($item['fornecedor_id'] ?? 0);

        if ($fornecedorId <= 0) {
            continue;
        }

        if (in_array($fornecedorId, $idsUsados, true)) {
            throw new InvalidArgumentException('O mesmo fornecedor não pode ser adicionado mais de uma vez para o mesmo produto.');
        }

        $stmt = $conn->prepare("
            SELECT id, nome, ativo
            FROM fornecedores
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new RuntimeException('Erro ao validar fornecedor.');
        }

        $stmt->bind_param('i', $fornecedorId);
        $stmt->execute();
        $fornecedorDb = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fornecedorDb) {
            throw new InvalidArgumentException('Fornecedor informado não existe.');
        }

        if ((int)($fornecedorDb['ativo'] ?? 1) !== 1) {
            throw new InvalidArgumentException('Fornecedor informado está inativo.');
        }

        $precoCusto = isset($item['preco_custo']) && $item['preco_custo'] !== ''
            ? (float)$item['preco_custo']
            : 0.0;

        $precoVenda = isset($item['preco_venda']) && $item['preco_venda'] !== ''
            ? (float)$item['preco_venda']
            : 0.0;

        if ($precoCusto < 0 || $precoVenda < 0) {
            throw new InvalidArgumentException('Os preços dos fornecedores devem ser maiores ou iguais a zero.');
        }

        $normalizados[] = [
            'fornecedor_id' => $fornecedorId,
            'nome'          => (string)$fornecedorDb['nome'],
            'codigo'        => trim((string)($item['codigo'] ?? '')),
            'preco_custo'   => $precoCusto,
            'preco_venda'   => $precoVenda,
            'observacao'    => trim((string)($item['observacao'] ?? '')),
            'principal'     => !empty($item['principal']) ? 1 : 0,
        ];

        $idsUsados[] = $fornecedorId;
    }

    if (empty($normalizados)) {
        return [];
    }

    $temPrincipal = false;
    foreach ($normalizados as $f) {
        if ((int)$f['principal'] === 1) {
            $temPrincipal = true;
            break;
        }
    }

    if (!$temPrincipal) {
        $normalizados[0]['principal'] = 1;
    } else {
        $achou = false;
        foreach ($normalizados as $i => $f) {
            if ((int)$f['principal'] === 1) {
                if (!$achou) {
                    $achou = true;
                    $normalizados[$i]['principal'] = 1;
                } else {
                    $normalizados[$i]['principal'] = 0;
                }
            }
        }
    }

    return $normalizados;
}

function tabela_existe(mysqli $conn, string $nomeTabela): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $nomeTabela);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $ok;
}

function home_obter_conteudo(mysqli $conn): array
{
    $frase = ['texto' => '', 'autor' => ''];
    $imagem = ['caminho' => ''];

    if (tabela_existe($conn, 'frases_home')) {
        $sqlFrase = "
            SELECT frase, autor
            FROM frases_home
            WHERE ativo = 1
            ORDER BY RAND()
            LIMIT 1
        ";

        $resFrase = $conn->query($sqlFrase);
        if ($resFrase instanceof mysqli_result) {
            $fraseRow = $resFrase->fetch_assoc();
            $resFrase->free();

            if (is_array($fraseRow)) {
                $frase['texto'] = (string)($fraseRow['frase'] ?? '');
                $frase['autor'] = (string)($fraseRow['autor'] ?? '');
            }
        }
    }

    if (tabela_existe($conn, 'imagens_home')) {
        $sqlImagem = "
            SELECT caminho
            FROM imagens_home
            WHERE ativo = 1
            ORDER BY RAND()
            LIMIT 1
        ";

        $resImagem = $conn->query($sqlImagem);
        if ($resImagem instanceof mysqli_result) {
            $imagemRow = $resImagem->fetch_assoc();
            $resImagem->free();

            if (is_array($imagemRow)) {
                $imagem['caminho'] = (string)($imagemRow['caminho'] ?? '');
            }
        }
    }

    return [
        'frase'  => $frase,
        'imagem' => $imagem,
    ];
}

try {
    $conn = db();
    $body = read_body();
    $acao = get_action($body);

    apply_session_timeout($acao);

    logInfo('actions', 'Requisição recebida', [
        'acao'   => $acao,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'get'    => sanitize_for_log($_GET),
        'post'   => sanitize_for_log($_POST),
        'body'   => sanitize_for_log($body)
    ]);

    switch ($acao) {
        case 'login': {
            $loginOriginal = trim((string)($body['login'] ?? $body['email'] ?? $body['usuario'] ?? ''));
            $senha = (string)($body['senha'] ?? $body['password'] ?? '');

            if ($loginOriginal === '' || $senha === '') {
                json_response(false, 'Informe login e senha.', null, 400);
            }

            $login = normalizar_login($loginOriginal);

            $stmt = $conn->prepare("
                SELECT
                    id,
                    nome,
                    email,
                    senha,
                    LOWER(TRIM(nivel)) AS nivel,
                    COALESCE(ativo, 1) AS ativo
                FROM usuarios
                WHERE LOWER(email) = ? OR LOWER(nome) = ?
                LIMIT 1
            ");
            if (!$stmt) {
                json_response(false, 'Erro ao processar login.', null, 500);
            }

            $stmt->bind_param('ss', $login, $login);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$user) {
                json_response(false, 'Usuário ou senha inválidos.', null, 401);
            }

            if ((int)($user['ativo'] ?? 1) !== 1) {
                json_response(false, 'Usuário inativo. Procure um administrador.', null, 403);
            }

            $hash = (string)($user['senha'] ?? '');

            if ($hash === '' || !password_verify($senha, $hash)) {
                json_response(false, 'Usuário ou senha inválidos.', null, 401);
            }

            session_regenerate_id(true);

            $_SESSION['usuario'] = [
                'id'    => (int)$user['id'],
                'nome'  => (string)$user['nome'],
                'email' => (string)$user['email'],
                'nivel' => (string)$user['nivel'],
                'ativo' => (int)$user['ativo'],
            ];
            $_SESSION['LAST_ACTIVITY'] = time();

            json_response(true, 'OK', ['usuario' => $_SESSION['usuario']]);
        }

        case 'usuario_atual': {
            $u = require_auth();
            json_response(true, 'OK', ['usuario' => $u]);
        }

        case 'logout': {
            destroy_user_session();
            json_response(true, 'OK', null);
        }

        case 'obter_home': {
            require_auth();
            $dados = home_obter_conteudo($conn);
            json_response(true, 'OK', $dados);
        }

        case 'listar_usuarios': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $res = usuarios_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_usuario': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $usuarioId = (int)($_GET['usuario_id'] ?? $body['usuario_id'] ?? 0);
            if ($usuarioId <= 0) {
                json_response(false, 'Usuário inválido.', null, 400);
            }

            $res = usuario_obter($conn, $usuarioId);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'salvar_usuario': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $usuarioId = (int)($body['usuario_id'] ?? 0);
            $nome = trim((string)($body['nome'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $nivel = trim((string)($body['nivel'] ?? 'operador'));
            $ativo = (int)($body['ativo'] ?? 1);
            $senha = isset($body['senha']) ? (string)$body['senha'] : null;

            $res = usuario_salvar(
                $conn,
                $usuarioId,
                $nome,
                $email,
                $nivel,
                $senha,
                $ativo,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'alterar_status_usuario': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $usuarioId = (int)($body['usuario_id'] ?? 0);
            $ativo = (int)($body['ativo'] ?? -1);

            $res = usuario_alterar_status(
                $conn,
                $usuarioId,
                $ativo,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'excluir_usuario': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $usuarioId = (int)($body['usuario_id'] ?? 0);

            $res = usuario_excluir(
                $conn,
                $usuarioId,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_fornecedores': {
            require_auth();
            $res = fornecedores_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_fornecedor': {
            require_auth();

            $fornecedor_id = (int)($_GET['fornecedor_id'] ?? $body['fornecedor_id'] ?? 0);
            if ($fornecedor_id <= 0) {
                json_response(false, 'Fornecedor inválido.', null, 400);
            }

            $res = fornecedor_obter($conn, $fornecedor_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'salvar_fornecedor': {
            $usuario = require_auth();

            $fornecedor_id = (int)($body['fornecedor_id'] ?? 0);
            $nome = trim((string)($body['nome'] ?? ''));
            $cnpj = trim((string)($body['cnpj'] ?? ''));
            $telefone = trim((string)($body['telefone'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $ativo = (int)($body['ativo'] ?? 1);
            $observacao = trim((string)($body['observacao'] ?? ''));

            if ($nome === '') {
                json_response(false, 'Nome do fornecedor obrigatório.', null, 400);
            }

            if (!in_array($ativo, [0, 1], true)) {
                json_response(false, 'Status inválido.', null, 400);
            }

            $res = fornecedor_salvar(
                $conn,
                $fornecedor_id,
                $nome,
                $cnpj,
                $telefone,
                $email,
                $ativo,
                $observacao,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_produtos': {
            require_auth();
            $res = produtos_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_produto': {
            require_auth();

            $produto_id = (int)($_GET['produto_id'] ?? $body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produto_obter($conn, $produto_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'adicionar_produto': {
            $usuario = require_auth();

            $nome = trim((string)($body['nome'] ?? ''));
            $ncm  = isset($body['ncm']) ? trim((string)$body['ncm']) : null;
            $qtd  = (int)($body['quantidade'] ?? 0);

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            $res = produtos_adicionar(
                $conn,
                $nome,
                $ncm,
                $qtd,
                0,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'criar_produto': {
            $usuario = require_auth();

            $nome = trim((string)($body['nome'] ?? ''));
            $ncm = isset($body['ncm']) ? trim((string)$body['ncm']) : null;

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            $qtd = (int)($body['quantidade'] ?? 0);
            $estoque_minimo = (int)($body['estoque_minimo'] ?? 0);

            $preco_custo = (isset($body['preco_custo']) && $body['preco_custo'] !== '')
                ? (float)$body['preco_custo']
                : null;

            $preco_venda = (isset($body['preco_venda']) && $body['preco_venda'] !== '')
                ? (float)$body['preco_venda']
                : null;

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            if ($estoque_minimo < 0) {
                json_response(false, 'Estoque mínimo inválido.', null, 400);
            }

            if ($preco_custo !== null && $preco_custo < 0) {
                json_response(false, 'Preço de custo inválido.', null, 400);
            }

            if ($preco_venda !== null && $preco_venda < 0) {
                json_response(false, 'Preço de venda inválido.', null, 400);
            }

            try {
                $fornecedores = normalizar_fornecedores_payload($conn, $body['fornecedores'] ?? []);
            } catch (InvalidArgumentException $e) {
                json_response(false, $e->getMessage(), null, 400);
            }

            $res = produtos_adicionar(
                $conn,
                $nome,
                $ncm,
                $qtd,
                $estoque_minimo,
                (int)$usuario['id'],
                $preco_custo,
                $preco_venda,
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'atualizar_produto': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            $nome = trim((string)($body['nome'] ?? ''));
            $ncm = isset($body['ncm']) ? trim((string)$body['ncm']) : null;
            $qtd = (int)($body['quantidade'] ?? 0);
            $estoque_minimo = (int)($body['estoque_minimo'] ?? 0);

            $preco_custo = (isset($body['preco_custo']) && $body['preco_custo'] !== '')
                ? (float)$body['preco_custo']
                : 0.0;

            $preco_venda = (isset($body['preco_venda']) && $body['preco_venda'] !== '')
                ? (float)$body['preco_venda']
                : 0.0;

            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            if ($estoque_minimo < 0) {
                json_response(false, 'Estoque mínimo inválido.', null, 400);
            }

            if ($preco_custo < 0 || $preco_venda < 0) {
                json_response(false, 'Preços inválidos.', null, 400);
            }

            try {
                $fornecedores = normalizar_fornecedores_payload($conn, $body['fornecedores'] ?? []);
            } catch (InvalidArgumentException $e) {
                json_response(false, $e->getMessage(), null, 400);
            }

            $res = produtos_atualizar(
                $conn,
                $produto_id,
                $nome,
                $ncm,
                $qtd,
                $estoque_minimo,
                $preco_custo,
                $preco_venda,
                (int)$usuario['id'],
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'remover_produto': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produtos_remover($conn, $produto_id, (int)$usuario['id']);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'buscar_produtos': {
            require_auth();

            $q = trim((string)($_GET['q'] ?? $body['q'] ?? ''));
            $limit = (int)($_GET['limit'] ?? $body['limit'] ?? 10);
            $limit = max(1, min(25, $limit));

            $res = produtos_buscar($conn, $q, $limit);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'produto_resumo': {
            require_auth();

            $produto_id = (int)($_GET['produto_id'] ?? $body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produto_resumo($conn, $produto_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_movimentacoes': {
            require_auth();

            $filtros = array_merge($_GET, $body);
            $res = mov_listar($conn, $filtros);

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_movimentacao': {
            require_auth();

            $movimentacao_id = (int)($_GET['movimentacao_id'] ?? $body['movimentacao_id'] ?? 0);
            if ($movimentacao_id <= 0) {
                json_response(false, 'Movimentação inválida.', null, 400);
            }

            $res = mov_obter($conn, $movimentacao_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'registrar_movimentacao': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            $tipo       = trim((string)($body['tipo'] ?? ''));
            $quantidade = (int)($body['quantidade'] ?? 0);

            $fornecedor_id = isset($body['fornecedor_id']) && $body['fornecedor_id'] !== ''
                ? (int)$body['fornecedor_id']
                : null;

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
                json_response(false, 'Dados inválidos.', null, 400);
            }

            if (!in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
                json_response(false, 'Tipo inválido.', null, 400);
            }

            if ($preco_custo !== null && $preco_custo < 0) {
                json_response(false, 'Preço de custo inválido.', null, 400);
            }

            if ($valor_unitario !== null && $valor_unitario < 0) {
                json_response(false, 'Valor unitário inválido.', null, 400);
            }

            if ($tipo === 'entrada') {
                if ($fornecedor_id === null || $fornecedor_id <= 0) {
                    json_response(false, 'Na entrada é obrigatório informar o fornecedor.', null, 400);
                }

                if ($preco_custo === null || $preco_custo <= 0) {
                    json_response(false, 'Na entrada é obrigatório informar um preço de custo válido.', null, 400);
                }
            }

            if ($tipo !== 'entrada') {
                $fornecedor_id = null;
            }

            $res = mov_registrar(
                $conn,
                $produto_id,
                $tipo,
                $quantidade,
                (int)$usuario['id'],
                $preco_custo,
                $valor_unitario,
                $observacao,
                $fornecedor_id
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'estoque_atual':
        case 'relatorio_estoque':
        case 'relatorio_estoque_atual': {
            require_auth();

            $res = relatorio_estoque_atual($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'relatorio':
        case 'relatorios':
        case 'relatorio_movimentacoes': {
            require_auth();

            $filtros = array_merge($_GET, $body);
            $res = relatorio($conn, $filtros);

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        default:
            json_response(false, 'Ação inválida.', null, 400);
    }
} catch (Throwable $e) {
    logError('actions', 'Erro fatal', [
        'arquivo' => $e->getFile(),
        'linha'   => $e->getLine(),
        'erro'    => $e->getMessage()
    ]);

    json_response(false, 'Erro interno no servidor.', [
        'erro'    => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha'   => $e->getLine(),
    ], 500);
}