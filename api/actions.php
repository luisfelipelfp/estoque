<?php
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");

$conn = db();
$acao = $_GET["acao"] ?? "";

switch ($acao) {

    // ✅ Teste de conexão
    case "testeconexao":
        echo json_encode(["sucesso" => true, "mensagem" => "Conexão OK"]);
        break;

    // ✅ Listar produtos
    case "listarprodutos":
        $result = $conn->query("SELECT * FROM produtos ORDER BY nome ASC");
        $produtos = [];
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode($produtos);
        break;

    // ✅ Listar movimentações
    case "listarmovimentacoes":
        $result = $conn->query("SELECT * FROM movimentacoes ORDER BY data DESC");
        $movs = [];
        while ($row = $result->fetch_assoc()) {
            $movs[] = $row;
        }
        echo json_encode($movs);
        break;

    // ✅ Adicionar produto
    case "adicionar":
        $nome = $_POST["nome"] ?? "";
        $quantidade = intval($_POST["quantidade"] ?? 0);

        if ($nome === "" || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
            exit;
        }

        // Insere produto
        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $quantidade);
        $stmt->execute();
        $produtoId = $conn->insert_id;

        // Insere movimentação
        $usuario = "sistema";
        $responsavel = "admin";
        $stmt = $conn->prepare("INSERT INTO movimentacoes 
            (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
            VALUES (?, ?, 'entrada', ?, NOW(), ?, ?)");
        $stmt->bind_param("isiss", $produtoId, $nome, $quantidade, $usuario, $responsavel);
        $stmt->execute();

        echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado"]);
        break;

    // ✅ Entrada de estoque
    case "entrada":
        $id = intval($_POST["id"] ?? 0);
        $quantidade = intval($_POST["quantidade"] ?? 0);

        if ($id <= 0 || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
            exit;
        }

        $conn->query("UPDATE produtos SET quantidade = quantidade + $quantidade WHERE id = $id");

        $produto = $conn->query("SELECT nome FROM produtos WHERE id = $id")->fetch_assoc();
        $nome = $produto["nome"] ?? "";

        $usuario = "sistema";
        $responsavel = "admin";
        $stmt = $conn->prepare("INSERT INTO movimentacoes 
            (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
            VALUES (?, ?, 'entrada', ?, NOW(), ?, ?)");
        $stmt->bind_param("isiss", $id, $nome, $quantidade, $usuario, $responsavel);
        $stmt->execute();

        echo json_encode(["sucesso" => true, "mensagem" => "Entrada registrada"]);
        break;

    // ✅ Saída de estoque
    case "saida":
        $id = intval($_POST["id"] ?? 0);
        $quantidade = intval($_POST["quantidade"] ?? 0);

        if ($id <= 0 || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
            exit;
        }

        $conn->query("UPDATE produtos SET quantidade = GREATEST(quantidade - $quantidade, 0) WHERE id = $id");

        $produto = $conn->query("SELECT nome FROM produtos WHERE id = $id")->fetch_assoc();
        $nome = $produto["nome"] ?? "";

        $usuario = "sistema";
        $responsavel = "admin";
        $stmt = $conn->prepare("INSERT INTO movimentacoes 
            (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
            VALUES (?, ?, 'saida', ?, NOW(), ?, ?)");
        $stmt->bind_param("isiss", $id, $nome, $quantidade, $usuario, $responsavel);
        $stmt->execute();

        echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
        break;

    // ✅ Remover produto
    case "remover":
        $id = intval($_POST["id"] ?? 0);
        if ($id <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "ID inválido"]);
            exit;
        }

        $produto = $conn->query("SELECT nome, quantidade FROM produtos WHERE id = $id")->fetch_assoc();
        if (!$produto) {
            echo json_encode(["sucesso" => false, "mensagem" => "Produto não encontrado"]);
            exit;
        }
        $nome = $produto["nome"];
        $quantidade = $produto["quantidade"];

        $conn->query("DELETE FROM produtos WHERE id = $id");

        $usuario = "sistema";
        $responsavel = "admin";
        $stmt = $conn->prepare("INSERT INTO movimentacoes 
            (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
            VALUES (?, ?, 'remocao', ?, NOW(), ?, ?)");
        $stmt->bind_param("isiss", $id, $nome, $quantidade, $usuario, $responsavel);
        $stmt->execute();

        echo json_encode(["sucesso" => true, "mensagem" => "Produto removido"]);
        break;

    // ❌ Ação inválida
    default:
        http_response_code(400);
        echo json_encode(["sucesso" => false, "mensagem" => "Ação inválida"]);
}
