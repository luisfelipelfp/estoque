<?php
$host = "192.168.15.100";  // IP do servidor MySQL
$user = "root";            // Usuário
$pass = "#Shakka01";       // Senha
$dbname = "estoque";       // Nome do banco

$conn = new mysqli($host, $user, $pass, $dbname);

// Verifica se conectou
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode([
        "status" => "erro",
        "mensagem" => "Falha na conexão com o banco: " . $conn->connect_error
    ]));
}

// Garante UTF-8 para acentos
$conn->set_charset("utf8");
?>
