<?php
/**
 * api/usuario.php
 * Verificação de usuário logado
 * Compatível com PHP 8.2+ / 8.5
 */

declare(strict_types=1);

// =====================================================
// Sessão
// =====================================================
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false, // altere para true se usar HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================================
// Dependências
// =====================================================
require_once __DIR__ . '/log.php';

// Inicializa log
initLog('usuario');

// =====================================================
// Headers
// =====================================================
header('Content-Type: application/json; charset=utf-8');

// =====================================================
// Função de resposta
// =====================================================
function resposta(bool $logado, ?array $usuario = null): array
{
    return [
        'sucesso' => $logado,
        'usuario' => $usuario
    ];
}

// =====================================================
// Verificação de sessão
// =====================================================
if (!empty($_SESSION['usuario']) && is_array($_SESSION['usuario'])) {

    logInfo('usuario', 'Usuário autenticado', [
        'id'   => $_SESSION['usuario']['id']   ?? null,
        'nome' => $_SESSION['usuario']['nome'] ?? null
    ]);

    echo json_encode(
        resposta(true, $_SESSION['usuario']),
        JSON_UNESCAPED_UNICODE
    );

} else {

    logInfo('usuario', 'Nenhum usuário logado');

    echo json_encode(
        resposta(false, null),
        JSON_UNESCAPED_UNICODE
    );
}
