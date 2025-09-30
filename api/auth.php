<?php
// =======================================
// api/auth.php
// Middleware de autenticação
// =======================================

require_once __DIR__ . "/utils.php";

// Garantir que a sessão esteja ativa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["usuario"])) {
    // Log de tentativa inválida
    debug_log("Acesso negado -> usuário não autenticado.", "auth.php");

    // Retorna resposta padronizada + HTTP 401
    http_response_code(401);
    echo json_encode(resposta(false, "Usuário não autenticado"));
    exit;
}

// 🔑 Usuário autenticado → exporta variável
$usuario = $_SESSION["usuario"];

// Log estruturado
debug_log(
    [
        "mensagem" => "Usuário autenticado",
        "dados" => [
            "id"    => $usuario["id"]    ?? null,
            "email" => $usuario["email"] ?? null,
            "nivel" => $usuario["nivel"] ?? null
        ]
    ],
    "auth.php"
);
