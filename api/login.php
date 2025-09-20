<?php
// =======================================
// Sessão e configuração do cookie
// =======================================
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/",
    "domain"   => "",        // usa o domínio atual (ajuste se necessário)
    "secure"   => false,     // mudar para true se usar HTTPS
    "httponly" => true,
    "samesite" => "Lax"      // "None" se precisar entre domínios
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =======================================
// Headers padrão + CORS
// =======================================
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://192.168.15.100"); // ajuste se acessar de outro host
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Se for uma pré-verificação (CORS preflight)
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =======================================
// Conexão DB
// =======================================
require_once __DIR__ . "/db.php";
$conn = db();

// =======================================
// Utilitários
// =======================================
$logFile = __DIR__ . "/debug.log";

function debug_log($msg) {
    global $logFile;
    $data = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$data] login.php -> $msg\n", FILE_APPEND);
}

function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

function read_body() {
    $body = file_get_contents("php://input");
    $data = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        return $data;
    }
    return $_POST ?? [];
}

// =======================================
// Validação do método
// =======================================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    debug_log("Método inválido: " . $_SERVER["REQUEST_METHOD"]);
    echo json_encode(resposta(false, "Método inválido."));
    exit;
}

// =======================================
// Captura do corpo
// =======================================
$body  = read_body();
$login = trim($body["login"] ?? $body["email"] ?? "");
$senha = $body["senha"] ?? "";

debug_log("Recebido login = '$login' | senha (len=" . strlen($senha) . ")");

if ($login === "" || $senha === "") {
    debug_log("Falhou -> login ou senha vazios");
    echo json_encode(resposta(false, "Preencha login e senha."));
    exit;
}

// =======================================
// Consulta usuário
// =======================================
if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel 
                            FROM usuarios 
                            WHERE email = ?
                            LIMIT 1");
    debug_log("Consultando por email");
} else {
    $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel 
                            FROM usuarios 
                            WHERE nome = ?
                            LIMIT 1");
    debug_log("Consultando por nome de usuário");
}

$stmt->bind_param("s", $login);
$stmt->execute();
$res     = $stmt->get_result();
$usuario = $res->fetch_assoc();

debug_log("Resultado consulta = " . json_encode($usuario ? ["id" => $usuario["id"], "email" => $usuario["email"], "nivel" => $usuario["nivel"]] : null));

// =======================================
// Verificação de senha
// =======================================
if ($usuario && password_verify($senha, $usuario["senha"])) {
    unset($usuario["senha"]); // 🔒 nunca expor hash
    $_SESSION["usuario"] = $usuario;

    debug_log("Login bem-sucedido para usuário ID " . $usuario["id"]);
    echo json_encode(resposta(true, "Login realizado com sucesso.", ["usuario" => $usuario]));
    exit;
}

// =======================================
// Falha de autenticação
// =======================================
debug_log("Falhou -> senha inválida ou usuário não encontrado");
echo json_encode(resposta(false, "Usuário/e-mail ou senha inválidos."));
