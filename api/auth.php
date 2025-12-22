<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$SESSION_TIMEOUT = 1800;

// Timeout
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > $SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        throw new RuntimeException('Sessão expirada');
    }
}

$_SESSION['LAST_ACTIVITY'] = time();

// Auth
if (
    empty($_SESSION['usuario']) ||
    !is_array($_SESSION['usuario']) ||
    empty($_SESSION['usuario']['id'])
) {
    throw new RuntimeException('Usuário não autenticado');
}
