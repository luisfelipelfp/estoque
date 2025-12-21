<?php
// api/auth.php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

initLog('auth');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$SESSION_TIMEOUT = 1800; // 30 minutos

// ================= TIMEOUT =================
if (isset($_SESSION['LAST_ACTIVITY'])) {

    $inatividade = time() - (int) $_SESSION['LAST_ACTIVITY'];

    if ($inatividade > $SESSION_TIMEOUT) {

        logWarning('auth', 'Sessão expirada', [
            'tempo' => $inatividade
        ]);

        session_unset();
        session_destroy();

        json_response(false, 'Sessão expirada. Faça login novamente.', null, 401);
    }
}

$_SESSION['LAST_ACTIVITY'] = time();

// ================= AUTENTICAÇÃO =================
if (
    empty($_SESSION['usuario']) ||
    !is_array($_SESSION['usuario']) ||
    empty($_SESSION['usuario']['id'])
) {
    logWarning('auth', 'Usuário não autenticado');

    json_response(false, 'Usuário não autenticado.', null, 401);
}
