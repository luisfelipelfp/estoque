<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

$conn = db();

// Captura ação
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if (!$action) {
    echo json_encode(["sucesso" => false, "erro" => "Ação não informada"]);
    exit;
}

switch ($action) {
    case "listarprodutos":
        $res = $conn->query("SELECT id, nome, quantidade FROM produtos ORDER BY nome ASC");
        $dados = $res->fetch_all(MYSQLI_ASSOC);
        echo json_encode(["sucesso" => true, "dados" => $dados]);
        break;

    case "registrarmovimentacao":
        $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
        $produto_id = (int)($input["produto_id"] ?? 0);
        $quantidade = (int)($input["quantidade"] ?? 0);
        $tipo = $input["tipo"] ?? "";
        $responsavel = $input["responsavel"] ?? "";

        if (!$produto_id || $quantidade <= 0 || !in_array($tipo, ["entrada", "saida"])) {
            echo json_encode(["sucesso" => false, "erro" => "Dados inválidos"]);
            exit;
        }

        if ($tipo === "entrada") {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
            $stmt->bind_param("ii", $quantidade, $produto_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
            $stmt->bind_param("iii", $quantidade, $produto_id, $quantidade);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                echo json_encode(["sucesso" => false, "erro" => "Estoque insuficiente"]);
                exit;
            }
        }

        // Insere movimentação
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, responsavel, data) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isis", $produto_id, $tipo, $quantidade, $responsavel);
        $stmt->execute();

        echo json_encode(["sucesso" => true]);
        break;

    case "listarmovimentacoes":
        $produto_id = $_GET["produto_id"] ?? null;
        $tipo = $_GET["tipo"] ?? null;
        $data_inicio = $_GET["data_inicio"] ?? null;
        $data_fim = $_GET["data_fim"] ?? null;

        $sql = "SELECT m.id, m.tipo, m.quantidade, m.responsavel, m.data, 
                       p.nome AS produto_nome
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                WHERE 1=1";

        $params = [];
        $types = "";

        if ($produto_id) {
            $sql .= " AND m.produto_id = ?";
            $types .= "i";
            $params[] = $produto_id;
        }
        if ($tipo) {
            $sql .= " AND m.tipo = ?";
            $types .= "s";
            $params[] = $tipo;
        }
        if ($data_inicio) {
            $sql .= " AND DATE(m.data) >= ?";
            $types .= "s";
            $params[] = $data_inicio;
        }
        if ($data_fim) {
            $sql .= " AND DATE(m.data) <= ?";
            $types .= "s";
            $params[] = $data_fim;
        }

        $sql .= " ORDER BY m.data DESC";

        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $dados = $res->fetch_all(MYSQLI_ASSOC);

        echo json_encode(["sucesso" => true, "dados" => $dados]);
        break;

    case "removerproduto":
        $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
        $produto_id = (int)($input["produto_id"] ?? 0);

        if (!$produto_id) {
            echo json_encode(["sucesso" => false, "erro" => "Produto inválido"]);
            exit;
        }

        // Registrar movimentação antes de remover
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, responsavel, data) 
                                VALUES (?, 'remocao', 0, '', NOW())");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();

        echo json_encode(["sucesso" => true]);
        break;

    default:
        echo json_encode(["sucesso" => false, "erro" => "Ação inválida"]);
        break;
}
