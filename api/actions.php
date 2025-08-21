<?php
header("Content-Type: application/json; charset=UTF-8");

require_once "db.php";

$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Se não veio JSON, tenta pegar via GET (ex: ?action=listar)
$action = $data["action"] ?? ($_GET["action"] ?? "");

try {
    switch ($action) {
        /* ---------------------------
           LISTAR PRODUTOS
        --------------------------- */
        case "listarProdutos":
            $stmt = $pdo->query("SELECT * FROM produtos ORDER BY id DESC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        /* ---------------------------
           ADICIONAR PRODUTO
        --------------------------- */
        case "adicionarProduto":
            $nome = trim($data["nome"] ?? "");
            if ($nome !== "") {
                $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, 0)");
                $ok = $stmt->execute([$nome]);
                echo json_encode(["sucesso" => $ok]);
            } else {
                echo json_encode(["sucesso" => false, "erro" => "Nome inválido"]);
            }
            break;

        /* ---------------------------
           REMOVER PRODUTO (registra movimentação 'removido')
        --------------------------- */
        case "removerProduto":
            $id = $data["id"] ?? 0;
            if ($id) {
                // pega quantidade antes de remover
                $stmt = $pdo->prepare("SELECT quantidade FROM produtos WHERE id = ?");
                $stmt->execute([$id]);
                $produto = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($produto) {
                    $quantidade = $produto["quantidade"];

                    // registra movimentação 'removido'
                    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES (?, 'removido', ?)");
                    $stmt->execute([$id, $quantidade]);

                    // remove produto de fato
                    $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
                    $ok = $stmt->execute([$id]);

                    echo json_encode(["sucesso" => $ok]);
                } else {
                    echo json_encode(["sucesso" => false, "erro" => "Produto não encontrado"]);
                }
            }
            break;

        /* ---------------------------
           ENTRADA DE PRODUTO
        --------------------------- */
        case "entrada":
            $id = $data["id"] ?? 0;
            $qtd = $data["quantidade"] ?? 0;
            if ($id && $qtd > 0) {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
                $stmt->execute([$qtd, $id]);

                $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES (?, 'entrada', ?)");
                $stmt->execute([$id, $qtd]);

                $pdo->commit();
                echo json_encode(["sucesso" => true]);
            } else {
                echo json_encode(["sucesso" => false]);
            }
            break;

        /* ---------------------------
           SAÍDA DE PRODUTO
        --------------------------- */
        case "saida":
            $id = $data["id"] ?? 0;
            $qtd = $data["quantidade"] ?? 0;
            if ($id && $qtd > 0) {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
                $stmt->execute([$qtd, $id]);

                $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES (?, 'saida', ?)");
                $stmt->execute([$id, $qtd]);

                $pdo->commit();
                echo json_encode(["sucesso" => true]);
            } else {
                echo json_encode(["sucesso" => false]);
            }
            break;

        /* ---------------------------
           LISTAR MOVIMENTAÇÕES
        --------------------------- */
        case "listarMovimentacoes":
            $stmt = $pdo->query("
                SELECT m.id, p.nome AS produto, m.tipo, m.quantidade, m.data
                FROM movimentacoes m
                JOIN produtos p ON p.id = m.produto_id
                ORDER BY m.data DESC
            ");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        /* ---------------------------
           AÇÃO INVÁLIDA
        --------------------------- */
        default:
            echo json_encode(["erro" => "Ação inválida"]);
    }
} catch (Exception $e) {
    echo json_encode(["erro" => $e->getMessage()]);
}
