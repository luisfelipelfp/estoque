<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

function json_out($data, int $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_produto_nome(mysqli $conn, int $id): ?string {
    $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($nome);
    if ($stmt->fetch()) {
        $stmt->close();
        return $nome;
    }
    $stmt->close();
    return null;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['acao'])) {
    json_out(['erro' => 'Ação não especificada'], 400);
}

$acao = $input['acao'];

switch ($acao) {
    case 'listarProdutos': {
        $res = $conn->query("SELECT * FROM produtos ORDER BY id ASC");
        $produtos = [];
        while ($row = $res->fetch_assoc()) {
            $produtos[] = $row;
        }
        json_out($produtos);
    }

    case 'adicionarProduto': {
        $nome = trim($input['nome'] ?? '');
        if ($nome === '') json_out(['erro' => 'Nome inválido'], 400);

        $stmt = $conn->prepare("INSERT INTO produtos (nome) VALUES (?)");
        $stmt->bind_param("s", $nome);
        if (!$stmt->execute()) {
            json_out(['erro' => 'Erro ao adicionar produto: ' . $stmt->error], 500);
        }
        $id = $stmt->insert_id;
        $stmt->close();

        // registra movimentação inicial (entrada com quantidade 0)
        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
            VALUES (?, ?, 0, 'entrada', NOW())
        ");
        $stmt->bind_param("is", $id, $nome);
        $stmt->execute();
        $stmt->close();

        json_out(['sucesso' => true, 'id' => $id]);
    }

    case 'removerProduto': {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            $nome = trim((string)($input['nome'] ?? ''));
            if ($nome === '') json_out(['erro' => 'Informe id ou nome'], 400);
            $stmt = $conn->prepare("SELECT id FROM produtos WHERE nome = ?");
            $stmt->bind_param("s", $nome);
            $stmt->execute();
            $stmt->bind_result($id_found);
            if (!$stmt->fetch()) { $stmt->close(); json_out(['erro' => 'Produto não encontrado'], 404); }
            $stmt->close();
            $id = (int)$id_found;
        }

        $nome = get_produto_nome($conn, $id) ?? '';

        // registra movimentação de remoção
        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
            VALUES (?, ?, 0, 'removido', NOW())
        ");
        $stmt->bind_param("is", $id, $nome);
        $stmt->execute();
        $stmt->close();

        // remove produto
        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        json_out(['sucesso' => true]);
    }

    case 'movimentarProduto': {
        $id = (int)($input['id'] ?? 0);
        $tipo = $input['tipo'] ?? '';
        $quantidade = (int)($input['quantidade'] ?? 0);
        if (!in_array($tipo, ['entrada', 'saida'])) {
            json_out(['erro' => 'Tipo inválido'], 400);
        }
        if ($id <= 0 || $quantidade <= 0) {
            json_out(['erro' => 'Dados inválidos'], 400);
        }

        $nome = get_produto_nome($conn, $id);
        if (!$nome) json_out(['erro' => 'Produto não encontrado'], 404);

        if ($tipo === 'entrada') {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = GREATEST(quantidade - ?, 0) WHERE id = ?");
        }
        $stmt->bind_param("ii", $quantidade, $id);
        $stmt->execute();
        $stmt->close();

        // registra movimentação
        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isis", $id, $nome, $quantidade, $tipo);
        $stmt->execute();
        $stmt->close();

        json_out(['sucesso' => true]);
    }

    case 'listarMovimentacoes': {
        $res = $conn->query("SELECT * FROM movimentacoes ORDER BY data DESC");
        $movs = [];
        while ($row = $res->fetch_assoc()) {
            $movs[] = $row;
        }
        json_out($movs);
    }

    default:
        json_out(['erro' => 'Ação inválida'], 400);
}
