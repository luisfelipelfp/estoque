<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

$action = $_GET['action'] ?? '';

switch ($action) {

    // ✅ Listar produtos
    case 'listar':
        try {
            $stmt = $pdo->query("SELECT * FROM produtos ORDER BY nome ASC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "Erro ao listar produtos: " . $e->getMessage()]);
        }
        break;

    // ✅ Cadastrar produto
    case 'cadastrar':
        $data = json_decode(file_get_contents("php://input"), true);
        $nome = $data['nome'] ?? '';
        $quantidade = (int)($data['quantidade'] ?? 0);

        if (!$nome) {
            echo json_encode(["success" => false, "message" => "Nome do produto é obrigatório"]);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        try {
            $stmt->execute([$nome, $quantidade]);
            echo json_encode(["success" => true, "message" => "Produto cadastrado com sucesso!"]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "Erro: " . $e->getMessage()]);
        }
        break;

    // ✅ Remover produto
    case 'remover':
        $data = json_decode(file_get_contents("php://input"), true);
        $nome = $data['nome'] ?? '';

        if (!$nome) {
            echo json_encode(["success" => false, "message" => "Nome do produto é obrigatório"]);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM produtos WHERE nome = ?");
        $stmt->execute([$nome]);

        echo json_encode(["success" => true, "message" => "Produto removido com sucesso!"]);
        break;

    // ✅ Movimentar (entrada / saída)
    case 'movimentar':
        $data = json_decode(file_get_contents("php://input"), true);
        $nome = $data['nome'] ?? '';
        $quantidade = (int)($data['quantidade'] ?? 0);
        $tipo = $data['tipo'] ?? '';

        if (!$nome || !$tipo) {
            echo json_encode(["success" => false, "message" => "Dados incompletos para movimentação"]);
            exit;
        }

        // Buscar produto
        $stmt = $pdo->prepare("SELECT * FROM produtos WHERE nome = ?");
        $stmt->execute([$nome]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($produto) {
            if ($tipo === 'entrada') {
                $novaQtd = $produto['quantidade'] + $quantidade;
            } else {
                $novaQtd = max(0, $produto['quantidade'] - $quantidade); // Evita negativo
            }

            $stmt = $pdo->prepare("UPDATE produtos SET quantidade = ? WHERE id = ?");
            $stmt->execute([$novaQtd, $produto['id']]);

            $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, ?, ?)");
            $stmt->execute([$produto['id'], $produto['nome'], $tipo, $quantidade]);

            echo json_encode(["success" => true, "message" => "Movimentação registrada com sucesso!"]);
        } else {
            echo json_encode(["success" => false, "message" => "Produto não encontrado!"]);
        }
        break;

    // ✅ Listar movimentações
    case 'movimentacoes':
        $stmt = $pdo->query("
            SELECT m.id, m.tipo, m.quantidade, m.data,
                   COALESCE(m.produto_nome, p.nome) as produto_nome
            FROM movimentacoes m
            LEFT JOIN produtos p ON m.produto_id = p.id
            ORDER BY m.data DESC
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        break;

    // ✅ Relatório com filtro de datas
    case 'relatorio':
        $inicio = $_GET['inicio'] ?? '';
        $fim = $_GET['fim'] ?? '';

        if ($inicio && $fim) {
            $stmt = $pdo->prepare("
                SELECT m.id, m.tipo, m.quantidade, m.data,
                       COALESCE(m.produto_nome, p.nome) as produto_nome
                FROM movimentacoes m
                LEFT JOIN produtos p ON m.produto_id = p.id
                WHERE DATE(m.data) BETWEEN ? AND ?
                ORDER BY m.data DESC
            ");
            $stmt->execute([$inicio, $fim]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([]);
        }
        break;

    // ✅ Ação inválida
    default:
        echo json_encode(["success" => false, "message" => "Ação inválida"]);
        break;
}
