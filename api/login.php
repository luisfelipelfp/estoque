<?php
// =======================================
// api/login.php
// Login do sistema
// Compatível PHP 8.2+ / 8.5
// =======================================

declare(strict_types=1);

// ---------------------------------------
// SESSÃO (ANTES DE QUALQUER OUTPUT)
// ---------------------------------------
if (session_status() === PHP_SESSION_NONE) {

    // PHP 8.x seguro (SameSite via php.ini)
    session_set_cookie_params(
        0,      // lifetime
        '/',    // path
        '',     // domain
        false,  // secure (true se HTTPS)
        true    // httponly
    );

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

    @ob_clean();
    json_response(false, 'Método inválido.', null, 405);
    exit;
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

    @ob_clean();
    json_response(false, 'Preencha login e senha.', null, 400);
    exit;
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

    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    $stmt->bind_param('s', $login);
    $stmt->execute();

    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

} catch (Throwable $e) {

    logError('login', 'Erro ao consultar usuário', [
        'erro'    => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha'   => $e->getLine()
    ]);

    @ob_clean();
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
        'login' => $login,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
    ]);

    @ob_clean();
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
    'usuario_id' => $usuario['id'],
    'nivel'      => $usuario['nivel']
]);

@ob_clean();
json_response(true, 'Login realizado com sucesso.', [
    'usuario' => $usuario
], 200);
exit;
