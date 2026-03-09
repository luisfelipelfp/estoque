<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

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

require_once __DIR__ . '/fornecedores.php';
require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/movimentacoes.php';
require_once __DIR__ . '/relatorios.php';

initLog('actions');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function set_cors_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [
        'http://192.168.15.100',
        'https://192.168.15.100',
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

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function require_auth(): array
{
    if (empty($_SESSION['usuario']) || !is_array($_SESSION['usuario'])) {
        json_response(false, 'Usuário não autenticado.', null, 401);
    }

    return $_SESSION['usuario'];
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
        $stmt->bind_param('i', $fornecedorId);
        $stmt->execute();
        $fornecedorDb = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fornecedorDb) {
            throw new InvalidArgumentException('Fornecedor informado não existe.');
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
    $acao = (string)($_GET['acao'] ?? $_POST['acao'] ?? '');
    $body = read_body();

    if ($acao === '' && isset($body['acao'])) {
        $acao = (string)$body['acao'];
    }

    logInfo('actions', 'Requisição recebida', [
        'acao'   => $acao,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'get'    => $_GET,
        'post'   => $_POST,
        'body'   => $body
    ]);

    switch ($acao) {
        case 'login': {
            $login = trim((string)($body['login'] ?? $body['email'] ?? $body['usuario'] ?? ''));
            $senha = (string)($body['senha'] ?? $body['password'] ?? '');

            if ($login === '' || $senha === '') {
                json_response(false, 'Informe login e senha.', null, 400);
            }

            $stmt = $conn->prepare("
                SELECT id, nome, email, senha, nivel
                FROM usuarios
                WHERE email = ? OR nome = ?
                LIMIT 1
            ");
            $stmt->bind_param('ss', $login, $login);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$user) {
                json_response(false, 'Usuário ou senha inválidos.', null, 401);
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
            ];
            $_SESSION['LAST_ACTIVITY'] = time();

            json_response(true, 'OK', ['usuario' => $_SESSION['usuario']]);
        }

        case 'usuario_atual': {
            $u = require_auth();
            json_response(true, 'OK', ['usuario' => $u]);
        }

        case 'logout': {
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
            json_response(true, 'OK', null);
        }

        case 'obter_home': {
            require_auth();
            $dados = home_obter_conteudo($conn);
            json_response(true, 'OK', $dados);
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
            $qtd  = (int)($body['quantidade'] ?? 0);

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            $res = produtos_adicionar($conn, $nome, $qtd, 0, (int)$usuario['id']);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'criar_produto': {
            $usuario = require_auth();

            $nome = trim((string)($body['nome'] ?? ''));
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

            try {
                $fornecedores = normalizar_fornecedores_payload($conn, $body['fornecedores'] ?? []);
            } catch (InvalidArgumentException $e) {
                json_response(false, $e->getMessage(), null, 400);
            }

            $res = produtos_adicionar(
                $conn,
                $nome,
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
            $tipo       = (string)($body['tipo'] ?? '');
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

    json_response(false, 'Erro interno no servidor.', null, 500);
}   