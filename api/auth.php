<?php
// =======================================
// api/auth.php
// Middleware de autenticação
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

// Headers padrão + CORS
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// 🔒 Verifica se usuário está logado
if (!isset($_SESSION["usuario"])) {
    debug_log("Acesso negado -> usuário não autenticado.", "auth.php");
    echo json_encode(resposta(false, "Usuário não autenticado"));
    exit;
}

// 🔑 Usuário autenticado
$usuario = $_SESSION["usuario"];
debug_log("Usuário autenticado: " . json_encode([
    "id" => $usuario["id"],
    "email" => $usuario["email"],
    "nivel" => $usuario["nivel"]
]), "auth.php");
