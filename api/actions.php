<?php
header("Content-Type: application/json; charset=utf-8");
$host = "localhost";
$user = "root";
$pass = "";
$db = "estoque";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(["sucesso" => false, "mensagem" => "Erro na conexão: " . $conn->connect_error]));
}

$acao = $_REQUEST["acao"] ?? "";

switch ($acao) {
    case "listarprodutos":
        $result = $conn->query("SELECT * FROM produtos ORDER BY nome ASC");
        $produtos = [];
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode(["sucesso" => true, "dados" => $produtos]);
        break;

    case "listarmovimentacoes":
        $result = $conn->query("SELECT * FROM movimentacoes ORDER BY data DESC");
        $movs = [];
        while ($row = $result->fetch_assoc()) {
            $movs[] = $row;
        }
        echo json_encode(["sucesso" => true, "dados" => $movs]);
        break;

    case "entrada":
        $id = $_POST["id"] ?? null;
        $qtd = intval($_POST["quantidade"] ?? 0);
        $usuario = $_POST["usuario"] ?? "";
        $responsavel = $_POST["responsavel"] ?? "";

        if ($id && $qtd > 0) {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
            $stmt->bind_param("ii", $qtd, $id);
            $stmt->execute();

            $stmtNome = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
            $stmtNome->bind_param("i", $id);
            $stmtNome->execute();
            $resNome = $stmtNome->get_result();
            $produto = $resNome->fetch_assoc();

            $stmtMov = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel) VALUES (?, ?, 'entrada', ?, NOW(), ?, ?)");
            $stmtMov->bind_param("isiss", $id, $produto['nome'], $qtd, $usuario, $responsavel);
            $stmtMov->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Entrada registrada"]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
        }
        break;

    case "saida":
        $id = $_POST["id"] ?? null;
        $qtd = intval($_POST["quantidade"] ?? 0);
        $usuario = $_POST["usuario"] ?? "";
        $responsavel = $_POST["responsavel"] ?? "";

        if ($id && $qtd > 0) {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
            $stmt->bind_param("iii", $qtd, $id, $qtd);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $stmtNome = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
                $stmtNome->bind_param("i", $id);
                $stmtNome->execute();
                $resNome = $stmtNome->get_result();
                $produto = $resNome->fetch_assoc();

                $stmtMov = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel) VALUES (?, ?, 'saida', ?, NOW(), ?, ?)");
                $stmtMov->bind_param("isiss", $id, $produto['nome'], $qtd, $usuario, $responsavel);
                $stmtMov->execute();

                echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
            } else {
                echo json_encode(["sucesso" => false, "mensagem" => "Quantidade insuficiente"]);
            }
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
        }
        break;

    case "adicionar":
        $nome = $_POST["nome"] ?? "";
        $qtd = intval($_POST["quantidade"] ?? 0);

        if ($nome !== "") {
            $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
            $stmt->bind_param("si", $nome, $qtd);
            if ($stmt->execute()) {
                echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado"]);
            } else {
                echo json_encode(["sucesso" => false, "mensagem" => "Erro ao adicionar produto"]);
            }
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Nome inválido"]);
        }
        break;

    case "remover":
        $id = $_POST["id"] ?? null;
        $usuario = $_POST["usuario"] ?? "";
        $responsavel = $_POST["responsavel"] ?? "";

        if ($id) {
            $stmtNome = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ?");
            $stmtNome->bind_param("i", $id);
            $stmtNome->execute();
            $resNome = $stmtNome->get_result();
            $produto = $resNome->fetch_assoc();

            if ($produto) {
                $stmtMov = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel) VALUES (?, ?, 'remocao', ?, NOW(), ?, ?)");
                $stmtMov->bind_param("isiss", $id, $produto['nome'], $produto['quantidade'], $usuario, $responsavel);
                $stmtMov->execute();

                $stmtDel = $conn->prepare("DELETE FROM produtos WHERE id = ?");
                $stmtDel->bind_param("i", $id);
                $stmtDel->execute();

                echo json_encode(["sucesso" => true, "mensagem" => "Produto removido"]);
            } else {
                echo json_encode(["sucesso" => false, "mensagem" => "Produto não encontrado"]);
            }
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "ID inválido"]);
        }
        break;

    default:
        echo json_encode(["sucesso" => false, "mensagem" => "Ação inválida"]);
        break;
}
