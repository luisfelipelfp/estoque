<?php
// =======================================
// api/login.php
// Login do sistema
// Compatível PHP 8.2+
// =======================================

declare(strict_types=1);

// ---------------------------------------
// CONFIGURAÇÃO DE ERROS
// ---------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------------------------------------
// SESSÃO
// ---------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // true se HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// ---------------------------------------
// DEPENDÊNCIAS
// ---------------------------------------
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

// ---------------------------------------
// LOG
// ---------------------------------------
initLog('login');

// ---------------------------------------
// HEADER
// ---------------------------------------
header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------
// MÉTODO
// ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logWarning('login', 'Método inválido', [
        'metodo' => $_SERVER['REQUEST_METHOD']
    ]);
    json_response(false, 'Método inválido.', null, 405);
    exit;
}

// ---------------------------------------
// ENTRADA (JSON ou FORM)
// ---------------------------------------
$input = json_decode(file_get_contents('php://input'), true);
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

$senha = (string) ($input['senha'] ?? $_POST['senha'] ?? '');

if ($login === '' || $senha === '') {
    logWarning('login', 'Campos obrigatórios ausentes');
    json_response(false, 'Preencha login e senha.', null, 400);
    exit;
}

logInfo('login', 'Tentativa de login', [
    'login' => $login,
    'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
]);

// ---------------------------------------
// CONEXÃO COM BANCO
// ---------------------------------------
try {
    $conn = db();
} catch (Throwable $e) {
    logError('login', 'Erro de conexão', [
        'erro' => $e->getMessage()
    ]);
    json_response(false, 'Erro interno.', null, 500);
    exit;
}

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
    logError('login', 'Erro ao buscar usuário', [
        'erro' => $e->getMessage()
    ]);
    json_response(false, 'Erro interno.', null, 500);
    exit;
}

// ---------------------------------------
// VALIDA SENHA
// ---------------------------------------
if (
    !$usuario ||
    empty($usuario['senha']) ||
    !password_verify($senha, $usuario['senha'])
) {
    logWarning('login', 'Falha de autenticação', [
        'login' => $login
    ]);
    json_response(false, 'Usuário/e-mail ou senha inválidos.', null, 401);
    exit;
}

// ---------------------------------------
// LOGIN OK
// ---------------------------------------
unset($usuario['senha']);

session_regenerate_id(true);
$_SESSION['usuario'] = $usuario;
$_SESSION['LAST_ACTIVITY'] = time();

logInfo('login', 'Login realizado com sucesso', [
    'usuario_id' => $usuario['id']
]);

json_response(true, 'Login realizado com sucesso.', [
    'usuario' => $usuario
], 200);
exit;
