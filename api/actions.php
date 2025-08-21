<?php
header("Content-Type: application/json");

// Conexão com o banco
$host = "localhost";
$db   = "estoque";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(["erro" => "Erro na conexão: " . $e->getMessage()]);
    exit;
}

// Verifica ação
$action = $_GET["action"] ?? "";

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
        $data = json_decode(file_get_contents("php://input"), true);
        $nome = trim($data["nome"] ?? "");

        if ($nome === "") {
            echo json_encode(["erro" => "Nome inválido"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, 0)");
            $stmt->execute([$nome]);
            echo json_encode(["sucesso" => true]);
        } catch (Exception $e) {
            echo json_encode(["erro" => "Erro ao adicionar: " . $e->getMessage()]);
        }
        break;

    /* ---------------------------
       ENTRADA DE PRODUTO
    --------------------------- */
    case "entradaProduto":
        $data = json_decode(file_get_contents("php://input"), true);
        $id   = (int)($data["id"] ?? 0);
        $qtd  = (int)($data["quantidade"] ?? 0);

        if ($id <= 0 || $qtd <= 0) {
            echo json_encode(["erro" => "Dados inválidos"]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?")
                ->execute([$qtd, $id]);

            $pdo->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'entrada', ?, NOW())")
                ->execute([$id, $qtd]);

            $pdo->commit();
            echo json_encode(["sucesso" => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["erro" => "Erro: " . $e->getMessage()]);
        }
        break;

    /* ---------------------------
       SAÍDA DE PRODUTO
    --------------------------- */
    case "saidaProduto":
        $data = json_decode(file_get_contents("php://input"), true);
        $id   = (int)($data["id"] ?? 0);
        $qtd  = (int)($data["quantidade"] ?? 0);

        if ($id <= 0 || $qtd <= 0) {
            echo json_encode(["erro" => "Dados inválidos"]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Verifica se tem estoque suficiente
            $stmt = $pdo->prepare("SELECT quantidade FROM produtos WHERE id = ?");
            $stmt->execute([$id]);
            $estoque = $stmt->fetchColumn();

            if ($estoque < $qtd) {
                throw new Exception("Estoque insuficiente.");
            }

            $pdo->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?")
                ->execute([$qtd, $id]);

            $pdo->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'saida', ?, NOW())")
                ->execute([$id, $qtd]);

            $pdo->commit();
            echo json_encode(["sucesso" => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["erro" => "Erro: " . $e->getMessage()]);
        }
        break;

    /* ---------------------------
       REMOVER PRODUTO
    --------------------------- */
    case "removerProduto":
        $data = json_decode(file_get_contents("php://input"), true);
        $id   = (int)($data["id"] ?? 0);

        if ($id <= 0) {
            echo json_encode(["erro" => "ID inválido"]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Pega quantidade antes de remover
            $stmt = $pdo->prepare("SELECT quantidade FROM produtos WHERE id = ?");
            $stmt->execute([$id]);
            $qtd = $stmt->fetchColumn();

            // Remove produto
            $pdo->prepare("DELETE FROM produtos WHERE id = ?")->execute([$id]);

            // Registra movimentação de "removido"
            $pdo->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'removido', ?, NOW())")
                ->execute([$id, $qtd]);

            $pdo->commit();
            echo json_encode(["sucesso" => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["erro" => "Erro: " . $e->getMessage()]);
        }
        break;

    /* ---------------------------
       LISTAR MOVIMENTAÇÕES
    --------------------------- */
    case "listarMovimentacoes":
        $stmt = $pdo->query("
            SELECT m.id, 
                   COALESCE(p.nome, 'Produto removido') AS produto_nome, 
                   m.tipo, 
                   m.quantidade, 
                   m.data
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
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
