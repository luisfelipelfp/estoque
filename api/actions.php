<?php
// api/actions.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$servername = "192.168.15.100"; // IP do servidor do MySQL
$username   = "root";
$password   = "#Shakka01";
$dbname     = "estoque";

// Conexão com banco
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Erro de conexão: " . $conn->connect_error]));
}

$action = $_GET['action'] ?? '';

switch ($action) {

    // ================== LISTAR PRODUTOS ==================
    case 'list':
        $result = $conn->query("SELECT * FROM produtos ORDER BY id DESC");
        $produtos = [];
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode($produtos);
        break;

    // ================== CADASTRAR PRODUTO ==================
    case 'add':
        $data = json_decode(file_get_contents("php://input"), true);
        $nome = $conn->real_escape_string($data['nome']);
        $quantidade = intval($data['quantidade']);

        $sql = "INSERT INTO produtos (nome, quantidade) VALUES ('$nome', $quantidade)";
        if ($conn->query($sql)) {
            echo json_encode(["success" => true, "message" => "Produto cadastrado com sucesso"]);
        } else {
            echo json_encode(["success" => false, "message" => "Erro: " . $conn->error]);
        }
        break;

    // ================== REMOVER PRODUTO ==================
    case 'delete':
        $id = intval($_GET['id'] ?? 0);
        if ($id > 0) {
            $sql = "DELETE FROM produtos WHERE id=$id";
            if ($conn->query($sql)) {
                echo json_encode(["success" => true, "message" => "Produto removido com sucesso"]);
            } else {
                echo json_encode(["success" => false, "message" => "Erro: " . $conn->error]);
            }
        } else {
            echo json_encode(["success" => false, "message" => "ID inválido"]);
        }
        break;

    // ================== ENTRADA / SAÍDA DE PRODUTOS ==================
    case 'movimentar':
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id']);
        $quantidade = intval($data['quantidade']);
        $tipo = $conn->real_escape_string($data['tipo']); // "entrada" ou "saida"

        // Atualiza tabela produtos
        if ($tipo === "entrada") {
            $sql = "UPDATE produtos SET quantidade = quantidade + $quantidade WHERE id=$id";
        } else {
            $sql = "UPDATE produtos SET quantidade = quantidade - $quantidade WHERE id=$id AND quantidade >= $quantidade";
        }

        if ($conn->query($sql)) {
            // Registra movimentação
            $sqlMov = "INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES ($id, '$tipo', $quantidade, NOW())";
            $conn->query($sqlMov);
            echo json_encode(["success" => true, "message" => "Movimentação registrada com sucesso"]);
        } else {
            echo json_encode(["success" => false, "message" => "Erro: " . $conn->error]);
        }
        break;

    // ================== RELATÓRIO DE MOVIMENTAÇÕES ==================
    case 'relatorio':
        $dataInicio = $_GET['inicio'] ?? '';
        $dataFim = $_GET['fim'] ?? '';

        $where = "";
        if ($dataInicio && $dataFim) {
            $where = "WHERE m.data BETWEEN '$dataInicio 00:00:00' AND '$dataFim 23:59:59'";
        }

        $sql = "SELECT m.id, p.nome AS produto, m.tipo, m.quantidade, m.data 
                FROM movimentacoes m
                JOIN produtos p ON m.produto_id = p.id
                $where
                ORDER BY m.data DESC";

        $result = $conn->query($sql);
        $movimentacoes = [];
        while ($row = $result->fetch_assoc()) {
            $movimentacoes[] = $row;
        }

        echo json_encode($movimentacoes);
        break;

    // ================== DEFAULT ==================
    default:
        echo json_encode(["success" => false, "message" => "Ação inválida"]);
}

$conn->close();
