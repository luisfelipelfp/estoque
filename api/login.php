<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
$conn = db();

function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

// 🔒 Aceita apenas POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(resposta(false, "Método inválido."));
    exit;
}

// 🔄 Captura o corpo da requisição (JSON ou POST tradicional)
$input = json_decode(file_get_contents("php://input"), true);
if (is_array($input)) {
    $login = trim($input["login"] ?? $input["email"] ?? "");
    $senha = trim($input["senha"] ?? "");
} else {
    $login = trim($_POST["login"] ?? $_POST["email"] ?? "");
    $senha = trim($_POST["senha"] ?? "");
}

// 🔍 Debug inicial
error_log("LOGIN DEBUG: Recebido login = '$login' | senha = " . ($senha !== "" ? "[preenchida]" : "[vazia]"));

if ($login === "" || $senha === "") {
    error_log("LOGIN DEBUG: Falhou -> login ou senha vazios");
    echo json_encode(resposta(false, "Preencha login e senha."));
    exit;
}

// 🔍 Verifica se login é email ou usuário
if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel 
                            FROM usuarios 
                            WHERE email = ?
                            LIMIT 1");
    error_log("LOGIN DEBUG: Consultando por email");
} else {
    $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel 
                            FROM usuarios 
                            WHERE nome = ?
                            LIMIT 1");
    error_log("LOGIN DEBUG: Consultando por nome de usuário");
}

$stmt->bind_param("s", $login);
$stmt->execute();
$res = $stmt->get_result();
$usuario = $res->fetch_assoc();

error_log("LOGIN DEBUG: Resultado consulta = " . json_encode($usuario));

if ($usuario && password_verify($senha, $usuario["senha"])) {
    unset($usuario["senha"]); // 🔒 nunca expor hash
    $_SESSION["usuario"] = $usuario;

    error_log("LOGIN DEBUG: Login bem-sucedido para usuário ID " . $usuario["id"]);
    echo json_encode(resposta(true, "Login realizado.", [
        "usuario" => $usuario
    ]));
} else {
    error_log("LOGIN DEBUG: Falhou -> senha inválida ou usuário não encontrado");
    echo json_encode(resposta(false, "Usuário/e-mail ou senha inválidos."));
}
