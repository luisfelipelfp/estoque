<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const SESSION_TIMEOUT_SECONDS = 7200;

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

function str_contains_safe(string $haystack, string $needle): bool
{
    if (function_exists('str_contains')) {
        return str_contains($haystack, $needle);
    }

    return strpos($haystack, $needle) !== false;
}

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
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    $raw = file_get_contents('php://input');

    if (str_contains_safe($contentType, 'application/json')) {

        $json = json_decode($raw, true);

        if (is_array($json)) {
            $cache = $json;
            return $cache;
        }
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

        if (is_array($parsed)) {
            $cache = $parsed;
            return $cache;
        }
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
    $maskedKeys = ['senha','password','token','access_token','refresh_token'];

    $sanitized = $data;

    array_walk_recursive($sanitized, function (&$value, $key) use ($maskedKeys) {

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
    $publicActions = ['login','logout'];

    if (in_array($acao, $publicActions, true)) {
        return;
    }

    if (!empty($_SESSION['usuario']) && isset($_SESSION['LAST_ACTIVITY'])) {

        $elapsed = time() - (int)$_SESSION['LAST_ACTIVITY'];

        if ($elapsed > SESSION_TIMEOUT_SECONDS) {

            destroy_user_session();

            json_response(false,'Sessão expirada. Faça login novamente.',null,401);
        }
    }

    if (!empty($_SESSION['usuario'])) {
        $_SESSION['LAST_ACTIVITY'] = time();
    }
}

function require_auth(): array
{
    if (empty($_SESSION['usuario']) || !is_array($_SESSION['usuario'])) {

        json_response(false,'Usuário não autenticado.',null,401);
    }

    $_SESSION['LAST_ACTIVITY'] = time();

    return $_SESSION['usuario'];
}

try {

    $conn = db();

    $body = read_body();

    $acao = get_action($body);

    if ($acao === '') {
        json_response(false,'Ação não informada.',null,400);
    }

    apply_session_timeout($acao);

    logInfo('actions','Requisição recebida',[
        'acao'=>$acao,
        'method'=>$_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'get'=>sanitize_for_log($_GET),
        'post'=>sanitize_for_log($_POST),
        'body'=>sanitize_for_log($body)
    ]);

    switch ($acao) {

        case 'login':

            $loginOriginal = trim((string)($body['login'] ?? $body['email'] ?? $body['usuario'] ?? ''));
            $senha = (string)($body['senha'] ?? $body['password'] ?? '');

            if ($loginOriginal === '' || $senha === '') {

                json_response(false,'Informe login e senha.',null,400);
            }

            $login = lower_text($loginOriginal);

            $stmt = $conn->prepare("
                SELECT
                    id,
                    nome,
                    email,
                    senha,
                    LOWER(TRIM(nivel)) AS nivel,
                    COALESCE(ativo,1) AS ativo
                FROM usuarios
                WHERE LOWER(email)=? OR LOWER(nome)=?
                LIMIT 1
            ");

            if (!$stmt) {
                json_response(false,'Erro ao processar login.',null,500);
            }

            $stmt->bind_param('ss',$login,$login);
            $stmt->execute();

            $user = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if (!$user) {
                json_response(false,'Usuário ou senha inválidos.',null,401);
            }

            if ((int)$user['ativo'] !== 1) {
                json_response(false,'Usuário inativo.',null,403);
            }

            if (!password_verify($senha,$user['senha'])) {
                json_response(false,'Usuário ou senha inválidos.',null,401);
            }

            session_regenerate_id(true);

            $_SESSION['usuario']=[
                'id'=>(int)$user['id'],
                'nome'=>$user['nome'],
                'email'=>$user['email'],
                'nivel'=>$user['nivel'],
                'ativo'=>(int)$user['ativo']
            ];

            $_SESSION['LAST_ACTIVITY']=time();

            json_response(true,'OK',['usuario'=>$_SESSION['usuario']]);

        case 'logout':

            destroy_user_session();

            json_response(true,'OK',null);

        default:

            json_response(false,'Ação inválida.',null,400);
    }

}
catch(Throwable $e){

    logError('actions','Erro fatal',[
        'arquivo'=>$e->getFile(),
        'linha'=>$e->getLine(),
        'erro'=>$e->getMessage()
    ]);

    json_response(false,'Erro interno no servidor.',null,500);
}