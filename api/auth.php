<?php
// =======================================
// api/auth.php
// Middleware de autenticação + timeout
// Compatível com PHP 8.4/8.5
// =======================================

require_once __DIR__ . "/utils.php";

// Garantir que a sessão esteja ativa
if (session_status() === PHP_SESSION_NONE) {

    // PHP 8.4+ NÃO aceita mais array aqui
    session_set_cookie_params(
        0,      // lifetime
        "/",    // path
        "",     // domain
        false,  // secure (mude para true em HTTPS)
        true    // httponly
    );

    session_start();
}

// Timeout de 30 minutos
$SESSION_TIMEOUT = 1800;

// Verifica timeout de inatividade
if (isset($_SESSION["LAST_ACTIVITY"])) {
    $inativo = time() - $_SESSION["LAST_ACTIVITY"];

    if ($inativo > $SESSION_TIMEOUT) {

        debug_log([
            "mensagem"      => "Sessão expirada por inatividade",
            "inatividade"   => $inativo
        ], "auth.php");

        session_unset();
        session_destroy();

        http_response_code(440);
        echo json_encode(["ok" => false, "mensagem" => "Sessão expirada por inatividade."]);
        exit;
    }
}

// Atualiza atividade da sessão
$_SESSION["LAST_ACTIVITY"] = time();

// Verifica se o usuário está logado
if (!isset($_SESSION["usuario"])) {
    debug_log("Acesso negado -> usuário não autenticado.", "auth.php");
    http_response_code(401);
    echo json_encode(["ok" => false, "mensagem" => "Usuário não autenticado"]);
    exit;
}

// Dados do usuário autenticado
$usuario = $_SESSION["usuario"];

debug_log([
    "mensagem" => "Usuário autenticado",
    "dados" => [
        "id"    => $usuario["id"]    ?? null,
        "email" => $usuario["email"] ?? null,
        "nivel" => $usuario["nivel"] ?? null
    ]
], "auth.php");
