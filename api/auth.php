<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

initLog('auth');

$SESSION_TIMEOUT = 1800; // 30min

if (session_status() === PHP_SESSION_NONE) {
    // Mantém compatível com seu ambiente local (nginx força https).
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,  // ✅ se estiver em https, cookie Secure
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// =======================
// TIMEOUT DE SESSÃO
// =======================
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - (int)$_SESSION['LAST_ACTIVITY']) > $SESSION_TIMEOUT) {

        logWarning('auth', 'Sessão expirada', [
            'ultimo_acesso' => (int)$_SESSION['LAST_ACTIVITY']
        ]);

        session_unset();
        session_destroy();

        json_response(false, 'Sessão expirada. Faça login novamente.', null, 401);
        exit;
    }
}

$_SESSION['LAST_ACTIVITY'] = time();

// =======================
// AUTENTICAÇÃO
// =======================
if (
    empty($_SESSION['usuario']) ||
    !is_array($_SESSION['usuario']) ||
    empty($_SESSION['usuario']['id'])
) {
    logWarning('auth', 'Usuário não autenticado');
    json_response(false, 'Usuário não autenticado.', null, 401);
    exit;
}