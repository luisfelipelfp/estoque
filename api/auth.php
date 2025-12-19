<?php
// =======================================
// api/auth.php
// Middleware de autenticação + timeout
// Compatível PHP 8.2+ / 8.5
// =======================================

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

// ---------------------------------------
// LOG
// ---------------------------------------
initLog('auth');

// ---------------------------------------
// HEADERS
// ---------------------------------------
header('Content-Type: application/json; charset=utf-8');

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
// TIMEOUT DE SESSÃO
// ---------------------------------------
$SESSION_TIMEOUT = 1800; // 30 minutos

if (isset($_SESSION['LAST_ACTIVITY'])) {

    $inatividade = time() - (int) $_SESSION['LAST_ACTIVITY'];

    if ($inatividade > $SESSION_TIMEOUT) {

        logWarning('auth', 'Sessão expirada por inatividade', [
            'tempo_inativo' => $inatividade,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
            'agent'         => $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido'
        ]);

        session_unset();
        session_destroy();

        json_response(
            false,
            'Sessão expirada por inatividade.',
            null,
            440
        );
    }
}

// Atualiza atividade
$_SESSION['LAST_ACTIVITY'] = time();

// ---------------------------------------
// AUTENTICAÇÃO
// ---------------------------------------
if (
    !isset($_SESSION['usuario']) ||
    !is_array($_SESSION['usuario']) ||
    !isset($_SESSION['usuario']['id'])
) {

    logWarning('auth', 'Acesso negado: usuário não autenticado', [
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
        'agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido'
    ]);

    json_response(
        false,
        'Usuário não autenticado.',
        null,
        401
    );
}

// ---------------------------------------
// USUÁRIO AUTENTICADO
// ---------------------------------------
$usuario = $_SESSION['usuario'];

logInfo('auth', 'Usuário autenticado', [
    'id'    => $usuario['id']    ?? null,
    'email' => $usuario['email'] ?? null,
    'nivel' => $usuario['nivel'] ?? null
]);

// ---------------------------------------
// (Opcional) Exemplo de verificação de nível
// ---------------------------------------
/*
if (($usuario['nivel'] ?? '') !== 'admin') {

    logWarning('auth', 'Acesso negado: nível insuficiente', [
        'id'    => $usuario['id'] ?? null,
        'nivel' => $usuario['nivel'] ?? null
    ]);

    json_response(false, 'Acesso restrito.', null, 403);
}
*/

// A partir daqui, o endpoint protegido continua normalmente
