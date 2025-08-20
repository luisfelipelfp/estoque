<?php
header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';

$acao = $_GET['action'] ?? $_POST['action'] ?? null;

try {
    switch ($acao) {

        // =========================
        // LISTAR PRODUTOS
        // =========================
        case 'listar':
            $sql = "SELECT * FROM produtos ORDER BY id DESC";
            $stmt = $pdo->query($sql);
            $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($produtos);
            break;

        // =========================
        // ADICIONAR PRODUTO
        // =========================
        case 'adicionar':
            $nome = $_POST['nome'] ?? null;
            $quantidade = $_POST['quantidade'] ?? 0;

            if (!$nome) {
                echo json_encode(["erro" => "Nome do produto é obrigatório"]);
                exit;
            }

            $sql = "INSERT INTO produtos (nome, quantidade) VALUES (?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nome, $quantidade]);

            echo json_encode(["sucesso" => true]);
            break;

        // =========================
        // REMOVER PRODUTO
        // =========================
        case 'remover':
            $id = $_POST['id'] ?? null;
            if (!$id) {
                echo json_encode(["erro" => "ID do produto é obrigatório"]);
                exit;
            }

            $sql = "DELETE FROM produtos WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);

            echo json_encode(["sucesso" => true]);
            break;

        // =========================
        // REGISTRAR MOVIMENTAÇÃO
        // =========================
        case 'movimentar':
            $produto_id = $_POST['produto_id'] ?? null;
            $tipo = $_POST['tipo'] ?? null;
            $quantidade = intval($_POST['quantidade'] ?? 0);

            if (!$produto_id || !$tipo || $quantidade <= 0) {
                echo json_encode(["erro" => "Dados inválidos para movimentação"]);
                exit;
            }

            // Atualiza a quantidade do produto
            if ($tipo === 'entrada') {
                $sql = "UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?";
            } elseif ($tipo === 'saida') {
                $sql = "UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?";
            } else {
                echo json_encode(["erro" => "Tipo de movimentação inválido"]);
                exit;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$quantidade, $produto_id]);

            // Insere na tabela de movimentações
            $sql = "INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$produto_id, $tipo, $quantidade]);

            echo json_encode(["sucesso" => true]);
            break;

        // =========================
        // LISTAR MOVIMENTAÇÕES
        // =========================
        case 'listar_movimentacoes':
            $sql = "SELECT * FROM movimentacoes ORDER BY data DESC";
            $stmt = $pdo->query($sql);
            $movs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($movs);
            break;

        // =========================
        // AÇÃO INVÁLIDA
        // =========================
        default:
            echo json_encode(["erro" => "Ação inválida"]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(["erro" => $e->getMessage()]);
}
