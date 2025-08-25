<?php
function db() {
    $host = "192.168.15.100";   // IP do servidor MySQL
    $user = "root";             // Usuário do banco
    $pass = "#Shakka01";        // Senha do banco
    $db   = "estoque";          // Nome do banco

    // Cria a conexão
    $conn = new mysqli($host, $user, $pass, $db);

    // Verifica se houve erro na conexão
    if ($conn->connect_error) {
        die(json_encode([
            "sucesso" => false,
            "mensagem" => "Erro na conexão com o banco: " . $conn->connect_error
        ]));
    }

    // Garante que a comunicação será em UTF-8
    $conn->set_charset("utf8mb4");

    return $conn;
}
?>
