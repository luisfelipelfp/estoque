<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

// Captura JSON enviado no corpo da requisição
$input = json_decode(file_get_contents("php://input"), true);

// Apenas "acao" é aceito agora
$acao = $input['acao'] ?? ($_POST['acao'] ?? null);

if (!$acao) {
    echo json_encode(["sucesso" => false, "erro" => "Nenhuma ação especificada"]);
    exit;
}

switch ($acao) {
    case 'listarProdutos':
        $stmt = $pdo->query("SELECT * FROM produtos ORDER BY id DESC");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["sucesso" => true, "dados" => $dados]);
        break;

    case 'adicionarProduto':
        $nome = trim($input['nome'] ?? $_POST['nome'] ?? "");
        if (!$nome) {
            echo json_encode(["sucesso" => false, "erro" => "Nome do produto é obrigatório"]);
            exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, 0)");
            $stmt->execute([$nome]);
            echo json_encode(["sucesso" => true]);
        } catch (PDOException $e) {
            echo json_encode(["sucesso" => false, "erro" => "Erro ao adicionar produto: " . $e->getMessage()]);
        }
        break;

    case 'entradaProduto':
        $id = intval($input['id'] ?? $_POST['id'] ?? 0);
        $quantidade = intval($input['quantidade'] ?? $_POST['quantidade'] ?? 0);

        if ($id <= 0 || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "erro" => "Dados inválidos"]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?")
                ->execute([$quantidade, $id]);

            $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data)
                           SELECT id, nome, 'entrada', ?, NOW() FROM produtos WHERE id = ?")
                ->execute([$quantidade, $id]);

            $pdo->commit();
            echo json_encode(["sucesso" => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(["sucesso" => false, "erro" => "Erro na entrada: " . $e->getMessage()]);
        }
        break;

    case 'saidaProduto':
        $id = intval($input['id'] ?? $_POST['id'] ?? 0);
        $quantidade = intval($input['quantidade'] ?? $_POST['quantidade'] ?? 0);

        if ($id <= 0 || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "erro" => "Dados inválidos"]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT quantidade, nome FROM produtos WHERE id = ?");
            $stmt->execute([$id]);
            $produto = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$produto || $produto['quantidade'] < $quantidade) {
                throw new Exception("Estoque insuficiente");
            }

            $pdo->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?")
                ->execute([$quantidade, $id]);

            $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data)
                           VALUES (?, ?, 'saida', ?, NOW())")
                ->execute([$id, $produto['nome'], $quantidade]);

            $pdo->commit();
            echo json_encode(["sucesso" => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["sucesso" => false, "erro" => $e->getMessage()]);
        }
        break;

    case 'removerProduto':
        $id = intval($input['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Primeiro registramos a remoção no histórico
            $stmt = $pdo->prepare("SELECT nome, quantidade FROM produtos WHERE id = ?");
            $stmt->execute([$id]);
            $produto = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($produto) {
                $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data)
                               VALUES (?, ?, 'saida', ?, NOW())")
                    ->execute([$id, $produto['nome'], $produto['quantidade']]);
            }

            // Depois removemos
            $pdo->prepare("DELETE FROM produtos WHERE id = ?")->execute([$id]);

            $pdo->commit();
            echo json_encode(["sucesso" => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["sucesso" => false, "erro" => $e->getMessage()]);
        }
        break;

    case 'listarMovimentacoes':
        $stmt = $pdo->query("SELECT * FROM movimentacoes ORDER BY id DESC");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["sucesso" => true, "dados" => $dados]);
        break;

    default:
        echo json_encode(["sucesso" => false, "erro" => "Ação inválida"]);
}
