<?php
// api/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["usuario"])) {
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "sucesso" => false,
        "erro"    => "Usuário não autenticado"
    ]);
    exit;
}
