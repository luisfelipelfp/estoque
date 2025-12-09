<?php
// =======================================
// Login do sistema — Compatível PHP 8.5
// =======================================

declare(strict_types=1);

// Ativar logs
ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/debug.log");

// ---------------------------------------
// SESSÃO — PHP 8.5 exige que cookie params
// sejam aplicados *APÓS* garantir que a
// extensão 'session' está carregada
// ---------------------------------------
if (!function_exists("session_status")) {
    error_log("[ERRO] Extensão de sessão não está carregada!");
    http_response_code(500);
    echo json_encode(["ok" => false, "mensagem" => "Erro interno de sessão"]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {

    // Configura parâmetros do cookie antes de iniciar a sessão
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "domain"   => "",
        "secure"   => false,     // Coloque true se usar HTTPS
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_start();
}

// ---------------------------------------
require_once __DIR__ . "/utils.php";
require_once __DIR__ . "/db.php";
$conn = db();

// ---------------------------------------
// CORS
// ---------------------------------------
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ---------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    debug_log(["erro" => "Método inválido"], "login.php");
    json_response(false, "Método inválido.", null, 405);
}

// ---------------------------------------
// Captura dados JSON ou POST
// ---------------------------------------
$input = json_decode(file_get_contents("php://input"), true);

$login = trim($input["login"] ?? $input["email"] ?? $_POST["login"] ?? $_POST["email"] ?? "");
$senha = $input["senha"] ?? $_POST["senha"] ?? "";

if ($login === "" || $senha === "") {
    json_response(false, "Preencha login e senha.", null, 400);
}

debug_log(["login_recebido" => $login], "login.php");

// ---------------------------------------
// Busca usuário
// ---------------------------------------
if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $stmt = $conn->prepare(
        "SELECT id, nome, email, senha, nivel FROM usuarios WHERE email = ? LIMIT 1"
    );
} else {
    $stmt = $conn->prepare(
        "SELECT id, nome, email, senha, nivel FROM usuarios WHERE nome = ? LIMIT 1"
    );
}

$stmt->bind_param("s", $login);
$stmt->execute();

$res = $stmt->get_result();
$usuario = $res->fetch_assoc();

// ---------------------------------------
// Verificação de senha
// ---------------------------------------
if ($usuario && password_verify($senha, $usuario["senha"])) {

    unset($usuario["senha"]); // Remover hash da resposta

    // Previne Session Fixation
    session_regenerate_id(true);

    $_SESSION["usuario"] = $usuario;
    $_SESSION["LAST_ACTIVITY"] = time();

    debug_log(["status" => "login_ok", "usuario_id" => $usuario["id"]], "login.php");

    json_response(true, "Login realizado com sucesso.", ["usuario" => $usuario], 200);
}

// Falha de login
debug_log(["falha_login" => $login], "login.php");
json_response(false, "Usuário/e-mail ou senha inválidos.", null, 401);
