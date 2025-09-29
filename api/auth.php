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

// Log estruturado (convertendo array para string JSON)
debug_log(
    "Usuário autenticado: " . json_encode([
        "id"    => $usuario["id"]    ?? null,
        "email" => $usuario["email"] ?? null,
        "nivel" => $usuario["nivel"] ?? null
    ], JSON_UNESCAPED_UNICODE),
    "auth.php"
);
