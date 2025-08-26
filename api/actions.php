<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

$conn = db();

$acao = $_GET["acao"] ?? $_POST["acao"] ?? null;

if (!$acao) {
    echo json_encode(["sucesso" => false, "mensagem" => "Nenhuma ação especificada"]);
    exit;
}

switch ($acao) {
    case "testeconexao":
        echo json_encode(["sucesso" => true, "mensagem" => "Conexão OK"]);
        break;

    case "listarprodutos":
        $sql = "SELECT * FROM produtos";
        $res = $conn->query($sql);
        $produtos = [];
        while ($row = $res->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode($produtos);
        break;

    case "listarmovimentacoes":
        $sql = "SELECT m.id, 
                       COALESCE(p.nome, 'Produto removido') AS produto_nome, 
                       m.tipo, 
                       m.quantidade, 
                       m.data, 
                       m.usuario, 
                       m.responsavel
                FROM movimentacoes m
                LEFT JOIN produtos p ON m.produto_id = p.id
                ORDER BY m.data DESC";
        $res = $conn->query($sql);
        $movs = [];
        while ($row = $res->fetch_assoc()) {
            $movs[] = $row;
        }
        echo json_encode($movs);
        break;

    case "adicionar":
        $nome = $_POST["nome"] ?? null;
        $quantidade = (int) ($_POST["quantidade"] ?? 0);
        $usuario = $_POST["usuario"] ?? "sistema";
        $responsavel = $_POST["responsavel"] ?? "admin";

        if (!$nome || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?) 
                                ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)");
        $stmt->bind_param("si", $nome, $quantidade);
        if ($stmt->execute()) {
            $produto_id = $conn->insert_id ?: $conn->query("SELECT id FROM produtos WHERE nome='$nome'")->fetch_assoc()["id"];
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel) 
                          VALUES ($produto_id, 'entrada', $quantidade, NOW(), '$usuario', '$responsavel')");

            echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado com sucesso"]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Erro: " . $stmt->error]);
        }
        break;

    case "saida":
        $id = (int) ($_POST["id"] ?? 0);
        $quantidade = (int) ($_POST["quantidade"] ?? 0);
        $usuario = $_POST["usuario"] ?? "sistema";
        $responsavel = $_POST["responsavel"] ?? "admin";

        if ($id <= 0 || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
        $stmt->bind_param("iii", $quantidade, $id, $quantidade);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel) 
                          VALUES ($id, 'saida', $quantidade, NOW(), '$usuario', '$responsavel')");

            echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Quantidade insuficiente ou produto inexistente"]);
        }
        break;

    case "remover":
        $id = (int) ($_POST["id"] ?? $_GET["id"] ?? 0);
        $usuario = $_POST["usuario"] ?? "sistema";
        $responsavel = $_POST["responsavel"] ?? "admin";

        if ($id <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "ID inválido"]);
            exit;
        }

        $produto = $conn->query("SELECT nome, quantidade FROM produtos WHERE id=$id")->fetch_assoc();
        if ($produto) {
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel) 
                          VALUES ($id, 'remocao', {$produto['quantidade']}, NOW(), '$usuario', '$responsavel')");
        }

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(["sucesso" => true, "mensagem" => "Produto removido com sucesso"]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Erro ao remover produto ou produto inexistente"]);
        }
        break;

    default:
        echo json_encode(["sucesso" => false, "mensagem" => "Ação inválida"]);
        break;
}

$conn->close();
?>
