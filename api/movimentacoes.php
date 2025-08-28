<?php
require_once "db.php";

$acao = $_REQUEST["acao"] ?? null;

switch ($acao) {
    case "listar_movimentacoes":
        $conn = db();

        $sql = "SELECT m.*, p.nome AS produto_nome 
                  FROM movimentacoes m
                  JOIN produtos p ON m.produto_id = p.id
                 WHERE 1=1";
        $params = [];
        $types  = "";

        // Filtros opcionais
        if (!empty($_GET["data_inicio"]) && !empty($_GET["data_fim"])) {
            $sql .= " AND DATE(m.data) BETWEEN ? AND ?";
            $params[] = $_GET["data_inicio"];
            $params[] = $_GET["data_fim"];
            $types   .= "ss";
        }

        if (!empty($_GET["tipo"])) {
            $sql .= " AND m.tipo = ?";
            $params[] = $_GET["tipo"];
            $types   .= "s";
        }

        if (!empty($_GET["produto_id"])) {
            $sql .= " AND m.produto_id = ?";
            $params[] = (int)$_GET["produto_id"];
            $types   .= "i";
        }

        $sql .= " ORDER BY m.data DESC";

        $stmt = $conn->prepare($sql);

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $movs = [];
        while ($row = $result->fetch_assoc()) {
            $movs[] = $row;
        }

        echo json_encode($movs);
        break;

    case "registrar_movimentacao":
        $dados = json_decode(file_get_contents("php://input"), true);
        $produto_id = $dados["produto_id"] ?? null;
        $tipo       = $dados["tipo"] ?? null;
        $quantidade = (int)($dados["quantidade"] ?? 0);

        if (!$produto_id || !$tipo || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos para movimentação."]);
            exit;
        }

        $conn = db();

        // Inserir movimentação
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES (?, ?, ?)");
        $stmt->bind_param("isi", $produto_id, $tipo, $quantidade);
        $stmt->execute();

        // Atualizar estoque do produto
        if ($tipo === "entrada") {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
            $stmt->bind_param("ii", $quantidade, $produto_id);
        } elseif ($tipo === "saida") {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
            $stmt->bind_param("iii", $quantidade, $produto_id, $quantidade);
        }
        $stmt->execute();

        echo json_encode(["sucesso" => true, "mensagem" => "Movimentação registrada com sucesso."]);
        break;

    default:
        echo json_encode(["sucesso" => false, "mensagem" => "Ação de movimentação desconhecida."]);
        break;
}
