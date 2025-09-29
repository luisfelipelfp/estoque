<?php
// =======================================
// api/auth.php
// Middleware de autenticação
// =======================================

require_once __DIR__ . "/utils.php";

// ⚠️ Atenção: a sessão já deve estar iniciada em actions.php
if (!isset($_SESSION["usuario"])) {
    // Log de tentativa inválida
    debug_log("Acesso negado -> usuário não autenticado.", "auth.php");

    // Retorna resposta padronizada
    echo json_encode(resposta(false, "Usuário não autenticado"));
    exit;
}

// 🔑 Usuário autenticado → exporta variável
$usuario = $_SESSION["usuario"];

// Log estruturado (sem precisar de json_encode manual)
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
