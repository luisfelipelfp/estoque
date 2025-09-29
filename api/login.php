<?php
// =======================================
// Login do sistema
// =======================================

ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");

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
require_once __DIR__ . "/db.php";
$conn = db();

// Headers padrão + CORS
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Aceita apenas POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    debug_log("Método inválido: " . $_SERVER["REQUEST_METHOD"], "login.php");
    echo json_encode(resposta(false, "Método inválido."));
    exit;
}

// Captura entrada JSON ou POST
$input = json_decode(file_get_contents("php://input"), true);
if (is_array($input)) {
    $login = trim($input["login"] ?? $input["email"] ?? "");
    $senha = $input["senha"] ?? "";
} else {
    $login = trim($_POST["login"] ?? $_POST["email"] ?? "");
    $senha = $_POST["senha"] ?? "";
}

debug_log("Recebido login = '$login' | senha (len=" . strlen($senha) . ")", "login.php");

if ($login === "" || $senha === "") {
    echo json_encode(resposta(false, "Preencha login e senha."));
    exit;
}

// Consulta por email ou usuário
if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel FROM usuarios WHERE email = ? LIMIT 1");
    debug_log("Consultando por email", "login.php");
} else {
    $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel FROM usuarios WHERE nome = ? LIMIT 1");
    debug_log("Consultando por nome de usuário", "login.php");
}

$stmt->bind_param("s", $login);
$stmt->execute();
$res = $stmt->get_result();
$usuario = $res->fetch_assoc();

debug_log("Resultado consulta = " . json_encode($usuario), "login.php");

if ($usuario && password_verify($senha, $usuario["senha"])) {
    unset($usuario["senha"]);
    $_SESSION["usuario"] = $usuario;

    debug_log("Login bem-sucedido para usuário ID " . $usuario["id"], "login.php");
    echo json_encode(resposta(true, "Login realizado.", ["usuario" => $usuario]));
    exit;
}

debug_log("Falhou -> senha inválida ou usuário não encontrado", "login.php");
echo json_encode(resposta(false, "Usuário/e-mail ou senha inválidos."));
