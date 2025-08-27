<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "db.php"; // importa função db()

$acao = $_REQUEST["acao"] ?? null;

if (!$acao) {
    echo json_encode(["sucesso" => false, "mensagem" => "Nenhuma ação especificada."]);
    exit;
}

switch ($acao) {
    // ------------------- PRODUTOS -------------------
    case "listar_produtos":
        $conn = db();
        $sql = "SELECT * FROM produtos ORDER BY id DESC";
        $result = $conn->query($sql);

        $produtos = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $produtos[] = $row;
            }
        }

        echo json_encode($produtos);
        break;

    case "adicionar_produto":
        $dados = json_decode(file_get_contents("php://input"), true);
        $nome = $dados["nome"] ?? null;
        $quantidade = $dados["quantidade"] ?? 0;

        if (!$nome) {
            echo json_encode(["sucesso" => false, "mensagem" => "Nome do produto é obrigatório."]);
            exit;
        }

        $conn = db();
        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $quantidade);

        if ($stmt->execute()) {
            echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado com sucesso."]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Erro ao adicionar produto: " . $conn->error]);
        }
        break;

    case "remover_produto":
        $id = $_GET["id"] ?? null;

        if (!$id) {
            echo json_encode(["sucesso" => false, "mensagem" => "ID do produto é obrigatório."]);
            exit;
        }

        $conn = db();

        // Registrar movimentação antes de remover
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) 
                                SELECT id, 'remocao', quantidade FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        // Remover produto
        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(["sucesso" => true, "mensagem" => "Produto removido com sucesso."]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Erro ao remover produto: " . $conn->error]);
        }
        break;

    // ------------------- MOVIMENTAÇÕES -------------------
    case "listar_movimentacoes":
        $conn = db();
        $sql = "SELECT * FROM movimentacoes ORDER BY data DESC";
        $result = $conn->query($sql);

        $movs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $movs[] = $row;
            }
        }

        echo json_encode($movs);
        break;

    case "registrar_movimentacao":
        $dados = json_decode(file_get_contents("php://input"), true);
        $produto_id = $dados["produto_id"] ?? null;
        $tipo = $dados["tipo"] ?? null;
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

    case "relatorio":
        $data_inicio = $_GET["data_inicio"] ?? null;
        $data_fim = $_GET["data_fim"] ?? null;
        $tipo = $_GET["tipo"] ?? null;

        $conn = db();

        $sql = "SELECT * FROM movimentacoes WHERE 1=1";
        $params = [];
        $types = "";

        if ($data_inicio && $data_fim) {
            $sql .= " AND DATE(data) BETWEEN ? AND ?";
            $params[] = $data_inicio;
            $params[] = $data_fim;
            $types .= "ss";
        }

        if ($tipo) {
            $sql .= " AND tipo = ?";
            $params[] = $tipo;
            $types .= "s";
        }

        $sql .= " ORDER BY data DESC";

        $stmt = $conn->prepare($sql);

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $relatorio = [];
        while ($row = $result->fetch_assoc()) {
            $relatorio[] = $row;
        }

        echo json_encode($relatorio);
        break;

    default:
        echo json_encode(["sucesso" => false, "mensagem" => "Ação desconhecida."]);
        break;
}
