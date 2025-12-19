<?php
// =======================================
// api/login.php
// Login do sistema
// Compatível PHP 8.2+ / 8.5
// =======================================

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

// ---------------------------------------
// LOG
// ---------------------------------------
initLog('login');

// ---------------------------------------
// HEADERS + CORS
// ---------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://192.168.15.100');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---------------------------------------
// MÉTODO
// ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logWarning('login', 'Método HTTP inválido', [
        'metodo' => $_SERVER['REQUEST_METHOD']
    ]);

    json_response(false, 'Método inválido.', null, 405);
}

// ---------------------------------------
// SESSÃO (PHP 8.5 SAFE)
// ---------------------------------------
if (session_status() === PHP_SESSION_NONE) {

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false, // true se HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// ---------------------------------------
// ENTRADA (JSON ou FORM)
// ---------------------------------------
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    $input = [];
}

$login = trim(
    $input['login']
    ?? $input['email']
    ?? $_POST['login']
    ?? $_POST['email']
    ?? ''
);

$senha = $input['senha'] ?? $_POST['senha'] ?? '';

if ($login === '' || $senha === '') {

    logWarning('login', 'Campos obrigatórios ausentes');

    json_response(false, 'Preencha login e senha.', null, 400);
}

logInfo('login', 'Tentativa de login', [
    'login' => $login,
    'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
    'agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido'
]);

// ---------------------------------------
// CONEXÃO
// ---------------------------------------
$conn = db();

// ---------------------------------------
// BUSCA USUÁRIO
// ---------------------------------------
try {

    if (filter_var($login, FILTER_VALIDATE_EMAIL)) {

        $stmt = $conn->prepare(
            'SELECT id, nome, email, senha, nivel
             FROM usuarios
             WHERE email = ?
             LIMIT 1'
        );

    } else {

        $stmt = $conn->prepare(
            'SELECT id, nome, email, senha, nivel
             FROM usuarios
             WHERE nome = ?
             LIMIT 1'
        );
    }

    $stmt->bind_param('s', $login);
    $stmt->execute();

    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

} catch (Throwable $e) {

    logError('login', 'Erro ao consultar usuário', [
        'erro'   => $e->getMessage(),
        'arquivo'=> $e->getFile(),
        'linha'  => $e->getLine()
    ]);

    json_response(false, 'Erro interno.', null, 500);
}

// ---------------------------------------
// VALIDA SENHA
// ---------------------------------------
if (!$usuario || !password_verify($senha, $usuario['senha'])) {

    logWarning('login', 'Falha de autenticação', [
        'login' => $login,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
    ]);

    json_response(false, 'Usuário/e-mail ou senha inválidos.', null, 401);
}

// ---------------------------------------
// LOGIN OK
// ---------------------------------------
unset($usuario['senha']);

session_regenerate_id(true);

$_SESSION['usuario'] = $usuario;
$_SESSION['LAST_ACTIVITY'] = time();

logInfo('login', 'Login realizado com sucesso', [
    'usuario_id' => $usuario['id'],
    'nivel'      => $usuario['nivel']
]);

json_response(true, 'Login realizado com sucesso.', [
    'usuario' => $usuario
], 200);
