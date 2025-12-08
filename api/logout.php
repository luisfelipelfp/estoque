<?php
// =======================================
// Logout do sistema
// =======================================

session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/utils.php";

// CORS
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

$_SESSION = [];

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
            "samesite" => "Lax"
        ]
    );
}

session_destroy();

debug_log("logout_ok", "logout.php");

json_response(true, "Logout realizado com sucesso.", null, 200);
                                