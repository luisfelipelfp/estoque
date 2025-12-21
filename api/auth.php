<?php
// api/auth.php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

initLog('auth');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$SESSION_TIMEOUT = 1800;

// Timeout
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $inatividade = time() - (int) $_SESSION['LAST_ACTIVITY'];

    if ($inatividade > $SESSION_TIMEOUT) {

        logWarning('auth', 'Sessão expirada', [
            'tempo' => $inatividade
        ]);

        session_unset();
        session_destroy();

        throw new RuntimeException('Sessão expirada');
    }
}

$_SESSION['LAST_ACTIVITY'] = time();

// Autenticação
if (
    !isset($_SESSION['usuario']) ||
    !is_array($_SESSION['usuario']) ||
    !isset($_SESSION['usuario']['id'])
) {
    logWarning('auth', 'Usuário não autenticado');
    throw new RuntimeException('Usuário não autenticado');
}
