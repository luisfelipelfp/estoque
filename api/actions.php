<?php
header("Content-Type: application/json; charset=UTF-8");

// Conexão com o banco
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "estoque";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha na conexão com o banco"]);
    exit;
}

// Normaliza a ação (sempre minúsculo)
$acao = isset($_GET["acao"]) ? strtolower($_GET["acao"]) : '';

switch ($acao) {
    case "listarprodutos":
        $sql = "SELECT * FROM produtos ORDER BY nome ASC";
        $result = $conn->query($sql);

        $produtos = [];
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }

        echo json_encode($produtos);
        break;

    case "listarmovimentacoes":
        $sql = "SELECT m.id, m.produto_id, m.produto_nome, m.tipo, m.quantidade, m.data, 
                       m.usuario, m.responsavel, p.nome AS nome_produto
                FROM movimentacoes m
                LEFT JOIN produtos p ON m.produto_id = p.id
                ORDER BY m.data DESC";

        $result = $conn->query($sql);

        $movimentacoes = [];
        while ($row = $result->fetch_assoc()) {
            $movimentacoes[] = $row;
        }

        echo json_encode($movimentacoes);
        break;

    default:
        http_response_code(400);
        echo json_encode(["erro" => "Ação inválida"]);
        break;
}

$conn->close();
