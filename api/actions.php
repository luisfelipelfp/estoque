<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

// Captura JSON enviado no corpo da requisição
$input = json_decode(file_get_contents("php://input"), true);

// Suporta tanto JSON (fetch com body) quanto POST tradicional
$action = $input['acao'] ?? ($_POST['acao'] ?? ($_GET['action'] ?? ''));

function respostaErro($mensagem, $codigo = 400) {
    http_response_code($codigo);
    echo json_encode(["status" => "erro", "mensagem" => $mensagem], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
    // ------------------------------------------------------------------
    case 'listar':
        $sql = "SELECT * FROM produtos ORDER BY id DESC";
        $result = $conn->query($sql);

        if (!$result) {
            respostaErro("Erro ao buscar produtos: " . $conn->error, 500);
        }

        $produtos = [];
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }

        echo json_encode($produtos, JSON_UNESCAPED_UNICODE);
        break;

    // ------------------------------------------------------------------
    case 'relatorio': // <- renomeado para bater com o script.js
    case 'movimentacoes':
        $sql = "SELECT m.*, p.nome AS produto_nome 
                FROM movimentacoes m 
                LEFT JOIN produtos p ON m.produto_id = p.id 
                ORDER BY m.data DESC";
        $result = $conn->query($sql);

        if (!$result) {
            respostaErro("Erro ao buscar movimentações: " . $conn->error, 500);
        }

        $movs = [];
        while ($row = $result->fetch_assoc()) {
            $movs[] = $row;
        }

        echo json_encode($movs, JSON_UNESCAPED_UNICODE);
        break;

    // ------------------------------------------------------------------
    case 'adicionar':
        $nome = $input['nome'] ?? ($_POST['nome'] ?? '');
        $quantidade = intval($input['quantidade'] ?? ($_POST['quantidade'] ?? 0));

        if (empty($nome)) {
            respostaErro("Nome do produto é obrigatório");
        }

        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $quantidade);

        if ($stmt->execute()) {
            echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado com sucesso"]);
        } else {
            respostaErro("Erro ao adicionar produto: " . $stmt->error, 500);
        }
        break;

    // ------------------------------------------------------------------
    case 'remover':
        $id = intval($input['id'] ?? ($_POST['id'] ?? 0));

        if ($id <= 0) {
            respostaErro("ID inválido");
        }

        $produto = $conn->query("SELECT * FROM produtos WHERE id=$id")->fetch_assoc();
        if ($produto) {
            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES (?, 'saida', ?)");
            $stmt->bind_param("ii", $id, $produto['quantidade']);
            $stmt->execute();
        }

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(["sucesso" => true, "mensagem" => "Produto removido com sucesso"]);
        } else {
            respostaErro("Erro ao remover produto: " . $stmt->error, 500);
        }
        break;

    // ------------------------------------------------------------------
    case 'entrada':
    case 'saida':
        $id = intval($input['id'] ?? ($_POST['id'] ?? 0));
        $quantidade = intval($input['quantidade'] ?? ($_POST['quantidade'] ?? 0));

        if ($id <= 0 || $quantidade <= 0) {
            respostaErro("ID e quantidade são obrigatórios");
        }

        $produto = $conn->query("SELECT * FROM produtos WHERE id=$id")->fetch_assoc();
        if (!$produto) {
            respostaErro("Produto não encontrado", 404);
        }

        if ($action === 'saida' && $produto['quantidade'] < $quantidade) {
            respostaErro("Quantidade em estoque insuficiente");
        }

        $novaQtd = $action === 'entrada'
            ? $produto['quantidade'] + $quantidade
            : $produto['quantidade'] - $quantidade;

        $stmt = $conn->prepare("UPDATE produtos SET quantidade=? WHERE id=?");
        $stmt->bind_param("ii", $novaQtd, $id);

        if ($stmt->execute()) {
            $stmt2 = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES (?, ?, ?)");
            $stmt2->bind_param("isi", $id, $action, $quantidade);
            $stmt2->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Movimentação registrada com sucesso"]);
        } else {
            respostaErro("Erro ao atualizar produto: " . $stmt->error, 500);
        }
        break;

    // ------------------------------------------------------------------
    default:
        respostaErro("Ação inválida", 400);
}
