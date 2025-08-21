<?php
header("Content-Type: application/json");
require_once "db.php";

$method = $_SERVER["REQUEST_METHOD"];
$action = $_GET["action"] ?? null;

if ($method !== "POST" || !$action) {
    echo json_encode(["erro" => "Ação inválida"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

switch ($action) {

    // ================= PRODUTOS =================
    case "listarProdutos":
        $result = $conn->query("SELECT * FROM produtos ORDER BY id DESC");
        $produtos = [];
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode($produtos);
        break;

    case "adicionarProduto":
        $nome = trim($data["nome"] ?? "");
        if (!$nome) {
            echo json_encode(["erro" => "Nome do produto obrigatório"]);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO produtos (nome) VALUES (?)");
        $stmt->bind_param("s", $nome);
        if ($stmt->execute()) {
            echo json_encode(["sucesso" => true]);
        } else {
            echo json_encode(["erro" => "Erro ao adicionar produto"]);
        }
        $stmt->close();
        break;

    case "entradaProduto":
        $id = (int)($data["id"] ?? 0);
        $quantidade = (int)($data["quantidade"] ?? 0);

        if ($id <= 0 || $quantidade <= 0) {
            echo json_encode(["erro" => "Dados inválidos"]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        $stmt->bind_param("ii", $quantidade, $id);
        $stmt->execute();
        $stmt->close();

        // registrar movimentação
        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
            SELECT id, nome, ?, 'entrada', NOW() FROM produtos WHERE id = ?
        ");
        $stmt->bind_param("ii", $quantidade, $id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(["sucesso" => true]);
        break;

    case "saidaProduto":
        $id = (int)($data["id"] ?? 0);
        $quantidade = (int)($data["quantidade"] ?? 0);

        if ($id <= 0 || $quantidade <= 0) {
            echo json_encode(["erro" => "Dados inválidos"]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
        $stmt->bind_param("iii", $quantidade, $id, $quantidade);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            echo json_encode(["erro" => "Quantidade insuficiente"]);
            $stmt->close();
            exit;
        }
        $stmt->close();

        // registrar movimentação
        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
            SELECT id, nome, ?, 'saida', NOW() FROM produtos WHERE id = ?
        ");
        $stmt->bind_param("ii", $quantidade, $id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(["sucesso" => true]);
        break;

    case "removerProduto":
        $id = (int)($data["id"] ?? 0);
        if ($id <= 0) {
            echo json_encode(["erro" => "ID inválido"]);
            exit;
        }

        // buscar nome do produto antes de remover
        $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($nome);
        if (!$stmt->fetch()) {
            echo json_encode(["erro" => "Produto não encontrado"]);
            $stmt->close();
            exit;
        }
        $stmt->close();

        // remover produto
        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // registrar movimentação como REMOVIDO
        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
            VALUES (?, ?, 0, 'removido', NOW())
        ");
        $stmt->bind_param("is", $id, $nome);
        $stmt->execute();
        $stmt->close();

        echo json_encode(["sucesso" => true]);
        break;

    // ================= MOVIMENTAÇÕES =================
    case "listarMovimentacoes":
        $result = $conn->query("SELECT * FROM movimentacoes ORDER BY id DESC");
        $movimentacoes = [];
        while ($row = $result->fetch_assoc()) {
            $movimentacoes[] = $row;
        }
        echo json_encode($movimentacoes);
        break;

    default:
        echo json_encode(["erro" => "Ação não reconhecida"]);
}
