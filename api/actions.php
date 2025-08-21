<?php
header("Content-Type: application/json");
require_once "db.php";

$method = $_SERVER["REQUEST_METHOD"];
$action = $_GET["action"] ?? $_POST["action"] ?? null;

if (!$action) {
    echo json_encode(["erro" => "Ação inválida"]);
    exit;
}

switch ($action) {
    case "listarProdutos":
        if ($method !== "GET") {
            echo json_encode(["erro" => "Método inválido"]);
            exit;
        }
        $res = $conn->query("SELECT * FROM produtos ORDER BY id DESC");
        echo json_encode($res->fetch_all(MYSQLI_ASSOC));
        break;

    case "listarMovimentacoes":
        if ($method !== "GET") {
            echo json_encode(["erro" => "Método inválido"]);
            exit;
        }
        $sql = "SELECT m.id, p.nome AS produto, m.tipo, m.quantidade, m.data
                FROM movimentacoes m
                JOIN produtos p ON m.produto_id = p.id
                ORDER BY m.data DESC";
        $res = $conn->query($sql);
        echo json_encode($res->fetch_all(MYSQLI_ASSOC));
        break;

    case "adicionarProduto":
        if ($method !== "POST") {
            echo json_encode(["erro" => "Método inválido"]);
            exit;
        }
        $data = json_decode(file_get_contents("php://input"), true);
        $nome = $conn->real_escape_string($data["nome"]);
        $sql = "INSERT INTO produtos (nome, quantidade) VALUES ('$nome', 0)";
        if ($conn->query($sql)) {
            echo json_encode(["sucesso" => true]);
        } else {
            echo json_encode(["erro" => $conn->error]);
        }
        break;

    case "entrada":
    case "saida":
        if ($method !== "POST") {
            echo json_encode(["erro" => "Método inválido"]);
            exit;
        }
        $data = json_decode(file_get_contents("php://input"), true);
        $produto_id = (int)$data["produto_id"];
        $quantidade = (int)$data["quantidade"];

        if ($action === "entrada") {
            $conn->query("UPDATE produtos SET quantidade = quantidade + $quantidade WHERE id = $produto_id");
            $tipo = "entrada";
        } else {
            $conn->query("UPDATE produtos SET quantidade = quantidade - $quantidade WHERE id = $produto_id");
            $tipo = "saida";
        }

        $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade) 
                      VALUES ($produto_id, '$tipo', $quantidade)");
        echo json_encode(["sucesso" => true]);
        break;

    case "remover":
        if ($method !== "POST") {
            echo json_encode(["erro" => "Método inválido"]);
            exit;
        }
        $data = json_decode(file_get_contents("php://input"), true);
        $produto_id = (int)$data["produto_id"];

        // pega quantidade atual
        $res = $conn->query("SELECT quantidade FROM produtos WHERE id = $produto_id");
        $row = $res->fetch_assoc();
        $quantidade = $row ? (int)$row["quantidade"] : 0;

        // registra como movimentação "removido"
        $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade) 
                      VALUES ($produto_id, 'removido', $quantidade)");

        // remove da tabela de produtos
        $conn->query("DELETE FROM produtos WHERE id = $produto_id");

        echo json_encode(["sucesso" => true]);
        break;

    default:
        echo json_encode(["erro" => "Ação inválida"]);
}
