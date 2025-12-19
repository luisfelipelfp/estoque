<?php
// =======================================
// api/logout.php
// Logout do sistema
// Compatível PHP 8.2+ / 8.5
// =======================================

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

// ---------------------------------------
// LOG
// ---------------------------------------
initLog('logout');

// ---------------------------------------
// HEADERS / CORS
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

    logWarning('logout', 'Método HTTP inválido', [
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
// LOG DE INÍCIO
// ---------------------------------------
logInfo('logout', 'Iniciando logout', [
    'usuario_id' => $_SESSION['usuario']['id'] ?? null,
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
    'agent'      => $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido'
]);

// ---------------------------------------
// LIMPA DADOS DA SESSÃO
// ---------------------------------------
$_SESSION = [];

// ---------------------------------------
// REMOVE COOKIE DE SESSÃO
// ---------------------------------------
if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 3600,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]
    );
}

// ---------------------------------------
// DESTROI SESSÃO
// ---------------------------------------
session_destroy();

// ---------------------------------------
// LOG DE SUCESSO
// ---------------------------------------
logInfo('logout', 'Logout realizado com sucesso', [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
]);

// ---------------------------------------
// RESPOSTA
// ---------------------------------------
json_response(true, 'Logout realizado com sucesso.', null, 200);
