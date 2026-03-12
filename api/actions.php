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

initLog('actions');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

function normalize_spaces(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return $value;
}

function read_body(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    $raw = file_get_contents('php://input');

    $isJson = function_exists('str_contains')
        ? str_contains($contentType, 'application/json')
        : strpos($contentType, 'application/json') !== false;

    if ($isJson) {
        $json = json_decode($raw, true);
        $cache = is_array($json) ? $json : [];
        return $cache;
    }

    if (!empty($_POST)) {
        $cache = $_POST;
        return $cache;
    }

    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $cache = $json;
            return $cache;
        }

        parse_str($raw, $parsed);
        $cache = is_array($parsed) ? $parsed : [];
        return $cache;
    }

    $cache = [];
    return $cache;
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

function normalizar_ncm_payload(?string $ncm): ?string
{
    $valor = preg_replace('/\D+/', '', (string)$ncm) ?? '';
    $valor = trim($valor);

    if ($valor === '') {
        return null;
    }

    return $valor;
}

function normalizar_fornecedores_payload(mysqli $conn, $fornecedoresRaw): array
{
    if (!is_array($fornecedoresRaw) || empty($fornecedoresRaw)) {
        return [];
    }

    $normalizados = [];
    $idsUsados = [];

    $stmt = $conn->prepare("
        SELECT id, nome, ativo
        FROM fornecedores
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao validar fornecedor.');
    }

    foreach ($fornecedoresRaw as $item) {
        if (!is_array($item)) {
            continue;
        }

        $fornecedorId = (int)($item['fornecedor_id'] ?? 0);

        if ($fornecedorId <= 0) {
            continue;
        }

        if (in_array($fornecedorId, $idsUsados, true)) {
            $stmt->close();
            throw new InvalidArgumentException('O mesmo fornecedor não pode ser adicionado mais de uma vez para o mesmo produto.');
        }

        $stmt->bind_param('i', $fornecedorId);
        $stmt->execute();
        $fornecedorDb = $stmt->get_result()->fetch_assoc();

        if (!$fornecedorDb) {
            $stmt->close();
            throw new InvalidArgumentException('Fornecedor informado não existe.');
        }

        if ((int)($fornecedorDb['ativo'] ?? 1) !== 1) {
            $stmt->close();
            throw new InvalidArgumentException('Fornecedor informado está inativo.');
        }

        $precoCusto = isset($item['preco_custo']) && $item['preco_custo'] !== ''
            ? (float)$item['preco_custo']
            : 0.0;

        $precoVenda = isset($item['preco_venda']) && $item['preco_venda'] !== ''
            ? (float)$item['preco_venda']
            : 0.0;

        if ($precoCusto < 0 || $precoVenda < 0) {
            $stmt->close();
            throw new InvalidArgumentException('Os preços dos fornecedores devem ser maiores ou iguais a zero.');
        }

        $normalizados[] = [
            'fornecedor_id' => $fornecedorId,
            'nome'          => (string)$fornecedorDb['nome'],
            'codigo'        => normalize_spaces((string)($item['codigo'] ?? '')),
            'preco_custo'   => $precoCusto,
            'preco_venda'   => $precoVenda,
            'observacao'    => normalize_spaces((string)($item['observacao'] ?? '')),
            'principal'     => !empty($item['principal']) ? 1 : 0,
        ];

        $idsUsados[] = $fornecedorId;
    }

    $stmt->close();

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
    $frase = [
        'texto' => '',
        'autor' => '',
    ];

    $imagem = [
        'caminho' => '',
    ];

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
            json_response(true, 'OK', ['usuario' => require_auth()]);
        }

        case 'logout': {
            destroy_user_session();
            json_response(true, 'OK', null);
        }

        case 'obter_home': {
            require_auth();
            json_response(true, 'OK', home_obter_conteudo($conn));
        }

        case 'listar_usuarios': {
            require_once __DIR__ . '/usuarios.php';
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $res = usuarios_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_usuario': {
            require_once __DIR__ . '/usuarios.php';
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
            require_once __DIR__ . '/usuarios.php';
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $res = usuario_salvar(
                $conn,
                (int)($body['usuario_id'] ?? 0),
                trim((string)($body['nome'] ?? '')),
                trim((string)($body['email'] ?? '')),
                trim((string)($body['nivel'] ?? 'operador')),
                isset($body['senha']) ? (string)$body['senha'] : null,
                (int)($body['ativo'] ?? 1),
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'alterar_status_usuario': {
            require_once __DIR__ . '/usuarios.php';
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $res = usuario_alterar_status(
                $conn,
                (int)($body['usuario_id'] ?? 0),
                (int)($body['ativo'] ?? -1),
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'excluir_usuario': {
            require_once __DIR__ . '/usuarios.php';
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $res = usuario_excluir(
                $conn,
                (int)($body['usuario_id'] ?? 0),
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_fornecedores': {
            require_once __DIR__ . '/fornecedores.php';
            require_auth();

            $res = fornecedores_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_fornecedor': {
            require_once __DIR__ . '/fornecedores.php';
            require_auth();

            $fornecedorId = (int)($_GET['fornecedor_id'] ?? $body['fornecedor_id'] ?? 0);
            if ($fornecedorId <= 0) {
                json_response(false, 'Fornecedor inválido.', null, 400);
            }

            $res = fornecedor_obter($conn, $fornecedorId);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'salvar_fornecedor': {
            require_once __DIR__ . '/fornecedores.php';
            $usuario = require_auth();

            $nome = trim((string)($body['nome'] ?? ''));
            $ativo = (int)($body['ativo'] ?? 1);

            if ($nome === '') {
                json_response(false, 'Nome do fornecedor obrigatório.', null, 400);
            }

            if (!in_array($ativo, [0, 1], true)) {
                json_response(false, 'Status inválido.', null, 400);
            }

            $res = fornecedor_salvar(
                $conn,
                (int)($body['fornecedor_id'] ?? 0),
                $nome,
                trim((string)($body['cnpj'] ?? '')),
                trim((string)($body['telefone'] ?? '')),
                trim((string)($body['email'] ?? '')),
                $ativo,
                trim((string)($body['observacao'] ?? '')),
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_produtos': {
            require_once __DIR__ . '/produtos.php';
            require_auth();

            $res = produtos_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_produto': {
            require_once __DIR__ . '/produtos.php';
            require_auth();

            $produtoId = (int)($_GET['produto_id'] ?? $body['produto_id'] ?? 0);
            if ($produtoId <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produto_obter($conn, $produtoId);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'adicionar_produto': {
            require_once __DIR__ . '/produtos.php';
            $usuario = require_auth();

            $nome = normalize_spaces((string)($body['nome'] ?? ''));
            $qtd  = (int)($body['quantidade'] ?? 0);
            $estoqueMinimo = (int)($body['estoque_minimo'] ?? 0);

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            if ($estoqueMinimo < 0) {
                json_response(false, 'Estoque mínimo inválido.', null, 400);
            }

            try {
                $fornecedores = normalizar_fornecedores_payload($conn, $body['fornecedores'] ?? []);
            } catch (InvalidArgumentException $e) {
                json_response(false, $e->getMessage(), null, 400);
            }

            $res = produtos_adicionar(
                $conn,
                $nome,
                isset($body['ncm']) ? normalizar_ncm_payload((string)$body['ncm']) : null,
                $qtd,
                $estoqueMinimo,
                (int)$usuario['id'],
                null,
                null,
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'criar_produto': {
            require_once __DIR__ . '/produtos.php';
            $usuario = require_auth();

            $nome = normalize_spaces((string)($body['nome'] ?? ''));
            $ncm = isset($body['ncm']) ? normalizar_ncm_payload((string)$body['ncm']) : null;
            $qtd = (int)($body['quantidade'] ?? 0);
            $estoqueMinimo = (int)($body['estoque_minimo'] ?? 0);

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            if ($estoqueMinimo < 0) {
                json_response(false, 'Estoque mínimo inválido.', null, 400);
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
                $estoqueMinimo,
                (int)$usuario['id'],
                null,
                null,
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'atualizar_produto': {
            require_once __DIR__ . '/produtos.php';
            $usuario = require_auth();

            $produtoId = (int)($body['produto_id'] ?? 0);
            $nome = normalize_spaces((string)($body['nome'] ?? ''));
            $ncm = isset($body['ncm']) ? normalizar_ncm_payload((string)$body['ncm']) : null;
            $qtd = (int)($body['quantidade'] ?? 0);
            $estoqueMinimo = (int)($body['estoque_minimo'] ?? 0);

            if ($produtoId <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            if ($estoqueMinimo < 0) {
                json_response(false, 'Estoque mínimo inválido.', null, 400);
            }

            try {
                $fornecedores = normalizar_fornecedores_payload($conn, $body['fornecedores'] ?? []);
            } catch (InvalidArgumentException $e) {
                json_response(false, $e->getMessage(), null, 400);
            }

            $res = produtos_atualizar(
                $conn,
                $produtoId,
                $nome,
                $ncm,
                $qtd,
                $estoqueMinimo,
                0.0,
                0.0,
                (int)$usuario['id'],
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'remover_produto': {
            require_once __DIR__ . '/produtos.php';
            $usuario = require_auth();

            $produtoId = (int)($body['produto_id'] ?? 0);
            if ($produtoId <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produtos_remover($conn, $produtoId, (int)$usuario['id']);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'buscar_produtos': {
            require_once __DIR__ . '/produtos.php';
            require_auth();

            $q = normalize_spaces((string)($_GET['q'] ?? $body['q'] ?? ''));
            $limit = (int)($_GET['limit'] ?? $body['limit'] ?? 10);
            $limit = max(1, min(25, $limit));

            $res = produtos_buscar($conn, $q, $limit);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'produto_resumo': {
            require_once __DIR__ . '/produtos.php';
            require_auth();

            $produtoId = (int)($_GET['produto_id'] ?? $body['produto_id'] ?? 0);
            if ($produtoId <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produto_resumo($conn, $produtoId);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_movimentacoes': {
            require_once __DIR__ . '/movimentacoes.php';
            require_auth();

            $filtros = array_merge($_GET, $body);
            $res = mov_listar($conn, $filtros);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_movimentacao': {
            require_once __DIR__ . '/movimentacoes.php';
            require_auth();

            if (!function_exists('mov_obter')) {
                json_response(false, 'Função mov_obter não está disponível no arquivo api/movimentacoes.php.', null, 500);
            }

            $movimentacaoId = (int)($_GET['movimentacao_id'] ?? $body['movimentacao_id'] ?? 0);
            if ($movimentacaoId <= 0) {
                json_response(false, 'Movimentação inválida.', null, 400);
            }

            $res = mov_obter($conn, $movimentacaoId);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'registrar_movimentacao': {
            require_once __DIR__ . '/movimentacoes.php';
            $usuario = require_auth();

            $produtoId = (int)($body['produto_id'] ?? 0);
            $tipo = trim((string)($body['tipo'] ?? ''));
            $quantidade = (int)($body['quantidade'] ?? 0);

            $fornecedorId = isset($body['fornecedor_id']) && $body['fornecedor_id'] !== ''
                ? (int)$body['fornecedor_id']
                : null;

            $precoCusto = isset($body['preco_custo']) && $body['preco_custo'] !== ''
                ? (float)$body['preco_custo']
                : null;

            $valorUnitario = isset($body['valor_unitario']) && $body['valor_unitario'] !== ''
                ? (float)$body['valor_unitario']
                : null;

            $observacao = isset($body['observacao']) && trim((string)$body['observacao']) !== ''
                ? trim((string)$body['observacao'])
                : null;

            if ($produtoId <= 0 || $quantidade <= 0) {
                json_response(false, 'Dados inválidos.', null, 400);
            }

            if (!in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
                json_response(false, 'Tipo inválido.', null, 400);
            }

            if ($precoCusto !== null && $precoCusto < 0) {
                json_response(false, 'Preço de custo inválido.', null, 400);
            }

            if ($valorUnitario !== null && $valorUnitario < 0) {
                json_response(false, 'Valor unitário inválido.', null, 400);
            }

            if ($tipo === 'entrada') {
                if ($fornecedorId === null || $fornecedorId <= 0) {
                    json_response(false, 'Na entrada é obrigatório informar o fornecedor.', null, 400);
                }

                if ($precoCusto === null || $precoCusto <= 0) {
                    json_response(false, 'Na entrada é obrigatório informar um preço de custo válido.', null, 400);
                }
            }

            if ($tipo !== 'entrada') {
                $fornecedorId = null;
            }

            $res = mov_registrar(
                $conn,
                $produtoId,
                $tipo,
                $quantidade,
                (int)$usuario['id'],
                $precoCusto,
                $valorUnitario,
                $observacao,
                $fornecedorId
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'estoque_atual':
        case 'relatorio_estoque':
        case 'relatorio_estoque_atual': {
            require_once __DIR__ . '/relatorios.php';
            require_auth();

            $res = relatorio_estoque_atual($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'relatorio':
        case 'relatorios':
        case 'relatorio_movimentacoes': {
            require_once __DIR__ . '/relatorios.php';
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

    json_response(false, 'Erro interno no servidor.', null, 500);
}