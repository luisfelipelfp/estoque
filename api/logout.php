<?php
// =======================================
// Logout do sistema — Compatível PHP 8.5
// =======================================

declare(strict_types=1);

ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/debug.log");

require_once __DIR__ . "/utils.php";

// -------------------------------------------------------------------
// Verifica se extensão de sessão está carregada
// -------------------------------------------------------------------
if (!function_exists("session_status")) {
    error_log("[ERRO] Extensão de sessão não carregada em logout.php");
    http_response_code(500);
    echo json_encode(["ok" => false, "mensagem" => "Erro interno de sessão"]);
    exit;
}

// -------------------------------------------------------------------
// Inicializa sessão com parâmetros (PHP 8.5 exige ordem correta)
// -------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {

    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "domain"   => "",
        "secure"   => false,      // true se estiver usando HTTPS
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_start();
}

// -------------------------------------------------------------------
// CORS
// -------------------------------------------------------------------
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

debug_log("Iniciando logout...", "logout.php");

// -------------------------------------------------------------------
// Limpa sessão
// -------------------------------------------------------------------
$_SESSION = [];

// -------------------------------------------------------------------
// Remove cookie de sessão de forma compatível com PHP 8.5
// -------------------------------------------------------------------
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        [
            "expires"  => time() - 3600,
            "path"     => $params["path"],
            "domain"   => $params["domain"],
            "secure"   => $params["secure"],
            "httponly" => $params["httponly"],
            "samesite" => $params["samesite"] ?? "Lax"
        ]
    );
}

// -------------------------------------------------------------------
// Destrói sessão
// -------------------------------------------------------------------
session_destroy();

debug_log("logout_ok", "logout.php");

json_response(true, "Logout realizado com sucesso.", null, 200);
