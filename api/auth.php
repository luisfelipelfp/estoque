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

    // Retorna resposta padronizada e encerra
    json_response(false, "Usuário não autenticado", null, 401);
}

// 🔑 Usuário autenticado → exporta variável
$usuario = $_SESSION["usuario"];

// Loga dados básicos do usuário
debug_log([
    "msg"   => "Usuário autenticado",
    "id"    => $usuario["id"]    ?? null,
    "email" => $usuario["email"] ?? null,
    "nivel" => $usuario["nivel"] ?? null
], "auth.php");
