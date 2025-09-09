<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

require_once "db.php";
$conn = db();

$login = $_POST["login"] ?? "";
$senha = $_POST["senha"] ?? "";

$stmt = $conn->prepare("SELECT id, nome, login, senha_hash, nivel FROM usuarios WHERE login = ?");
$stmt->bind_param("s", $login);
$stmt->execute();
$res = $stmt->get_result();
$usuario = $res->fetch_assoc();

if ($usuario && password_verify($senha, $usuario["senha_hash"])) {
    unset($usuario["senha_hash"]);
    $_SESSION["usuario"] = $usuario;
    echo json_encode(["sucesso" => true, "usuario" => $usuario]);
} else {
    http_response_code(401);
    echo json_encode(["erro" => "Login ou senha inválidos"]);
}
