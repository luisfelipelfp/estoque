<?php
// =======================================
// db.php — Conexão MySQL (versão segura / PHP 8.4+)
// =======================================

function db() {

    $host = "192.168.15.100";   // IP do MySQL
    $user = "root";             // Usuário
    $pass = "#Shakka01";        // Senha
    $db   = "estoque";          // Nome do banco

    // Cria objeto mysqli
    $conn = @new mysqli($host, $user, $pass, $db);

    // Erro na conexão
    if ($conn->connect_errno) {

        error_log("ERRO MySQL: " . $conn->connect_error);

        // NÃO exponha erro interno ao usuário
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Não foi possível conectar ao banco de dados."
        ]);

        http_response_code(500);
        exit;
    }

    // Força UTF-8 real
    if (!$conn->set_charset("utf8mb4")) {
        error_log("Falha ao configurar charset UTF8MB4: " . $conn->error);
    }

    return $conn;
}
