<?php
header("Content-Type: application/json");
require_once "db.php";

// Agora aceita JSON (php://input) e também parâmetros GET
$input = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $input["action"] ?? ($_GET["action"] ?? "");

switch ($action) {

    case "cadastrar":
        $nome = $conn->real_escape_string($input["nome"]);
        $quantidade = (int)$input["quantidade"];

        $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome', $quantidade)");

        echo json_encode(["status" => "ok", "mensagem" => "Produto cadastrado"]);
        break;

    case "movimentar":
        $nome = $conn->real_escape_string($input["nome"]);
        $quantidade = (int)$input["quantidade"];
        $tipo = $input["tipo"];

        $res = $conn->query("SELECT id, quantidade FROM produtos WHERE nome='$nome'");
        if ($res->num_rows > 0) {
            $prod = $res->fetch_assoc();
            $id = $prod["id"];

            $novaQtd = ($tipo == "entrada")
                ? $prod["quantidade"] + $quantidade
                : $prod["quantidade"] - $quantidade;

            $conn->query("UPDATE produtos SET quantidade=$novaQtd WHERE id=$id");

            $conn->query("INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data) 
                          VALUES ($id, '$nome', $quantidade, '$tipo', NOW())");
        }

        echo json_encode(["status" => "ok", "mensagem" => "Movimentação registrada"]);
        break;

    case "remover":
        $nome = $conn->real_escape_string($input["nome"]);
        $res = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");

        if ($res->num_rows > 0) {
            $prod = $res->fetch_assoc();
            $id = $prod["id"];

            // Remove produto
            $conn->query("DELETE FROM produtos WHERE id=$id");

            // Registra a remoção na tabela de movimentações
            $conn->query("INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data) 
                          VALUES ($id, '$nome', 0, 'saida', NOW())");
        }

        echo json_encode(["status" => "ok", "mensagem" => "Produto removido"]);
        break;

    case "listarProdutos":
        $res = $conn->query("SELECT * FROM produtos ORDER BY id ASC");
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        echo json_encode($out);
        break;

    case "listarMovimentacoes":
        $res = $conn->query("SELECT id, produto_nome, quantidade, tipo, data
                             FROM movimentacoes
                             ORDER BY data DESC");
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        echo json_encode($out);
        break;

    case "relatorio":
        $inicio = $conn->real_escape_string($input["inicio"] ?? ($_GET["inicio"] ?? ""));
        $fim    = $conn->real_escape_string($input["fim"] ?? ($_GET["fim"] ?? ""));

        $sql = "SELECT id, produto_nome, quantidade, tipo, data 
                FROM movimentacoes 
                WHERE DATE(data) BETWEEN '$inicio' AND '$fim'
                ORDER BY data DESC";

        $res = $conn->query($sql);
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        echo json_encode($out);
        break;

    case "testeConexao":
        echo json_encode(["status" => "ok", "mensagem" => "Conexão com banco funcionando!"]);
        break;

    default:
        echo json_encode(["erro" => "Ação inválida"]);
}
?>
