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

$login = trim($_POST["login"] ?? "");
$senha = trim($_POST["senha"] ?? "");

if ($login === "" || $senha === "") {
    echo json_encode(resposta(false, "Preencha login e senha."));
    exit;
}

// Verifica se login é um e-mail válido
if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel 
                            FROM usuarios 
                            WHERE email = ?
                            LIMIT 1");
} else {
    $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel 
                            FROM usuarios 
                            WHERE nome = ?
                            LIMIT 1");
}

$stmt->bind_param("s", $login);
$stmt->execute();
$res = $stmt->get_result();
$usuario = $res->fetch_assoc();

if ($usuario && password_verify($senha, $usuario["senha"])) {
    unset($usuario["senha"]); // 🔒 nunca expor hash
    $_SESSION["usuario"] = $usuario;

    echo json_encode(resposta(true, "Login realizado.", [
        "usuario" => $usuario
    ]));
} else {
    echo json_encode(resposta(false, "Usuário/e-mail ou senha inválidos."));
}
