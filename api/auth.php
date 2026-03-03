<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$SESSION_TIMEOUT = 1800;

// =====================================================
// TIMEOUT DE SESSÃO
// =====================================================
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > $SESSION_TIMEOUT) {

        logWarning('auth', 'Sessão expirada', [
            'ultimo_acesso' => $_SESSION['LAST_ACTIVITY']
        ]);

        session_unset();
        session_destroy();

        json_response(false, 'Sessão expirada. Faça login novamente.', null, 401);
        exit;
    }
}

$_SESSION['LAST_ACTIVITY'] = time();

// =====================================================
// AUTENTICAÇÃO
// =====================================================
if (
    empty($_SESSION['usuario']) ||
    !is_array($_SESSION['usuario']) ||
    empty($_SESSION['usuario']['id'])
) {

    logWarning('auth', 'Usuário não autenticado', [
        'session' => $_SESSION
    ]);

    json_response(false, 'Usuário não autenticado.', null, 401);
    exit;
}
