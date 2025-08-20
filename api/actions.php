<?php
header("Content-Type: application/json");
require_once "db.php";

$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "";

switch ($action) {
    case "cadastrar":
        $nome = $conn->real_escape_string($input["nome"]);
        $quantidade = (int)$input["quantidade"];
        $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome', $quantidade)");
        echo json_encode(["status" => "ok"]);
        break;

    case "movimentar":
        $nome = $conn->real_escape_string($input["nome"]);
        $quantidade = (int)$input["quantidade"];
        $tipo = $input["tipo"];
        
        $res = $conn->query("SELECT id, quantidade FROM produtos WHERE nome='$nome'");
        if ($res->num_rows > 0) {
            $prod = $res->fetch_assoc();
            $id = $prod["id"];
            $novaQtd = $tipo == "entrada" ? $prod["quantidade"] + $quantidade : $prod["quantidade"] - $quantidade;
            $conn->query("UPDATE produtos SET quantidade=$novaQtd WHERE id=$id");
            $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data, status) VALUES ($id, $quantidade, '$tipo', NOW(), 'ok')");
        }
        echo json_encode(["status" => "ok"]);
        break;

    case "remover":
        $nome = $conn->real_escape_string($input["nome"]);
        $res = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
        if ($res->num_rows > 0) {
            $prod = $res->fetch_assoc();
            $id = $prod["id"];
            $conn->query("DELETE FROM produtos WHERE id=$id");
            $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data, status) VALUES ($id, 0, 'remocao', NOW(), 'removido')");
        }
        echo json_encode(["status" => "ok"]);
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
        $res = $conn->query("SELECT m.id, p.nome, m.quantidade, m.tipo, m.data, m.status 
                             FROM movimentacoes m 
                             JOIN produtos p ON m.produto_id = p.id
                             ORDER BY m.data DESC");
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        echo json_encode($out);
        break;

    case "relatorio":
        $inicio = $conn->real_escape_string($input["inicio"]);
        $fim = $conn->real_escape_string($input["fim"]);
        $res = $conn->query("SELECT m.id, p.nome, m.quantidade, m.tipo, m.data, m.status 
                             FROM movimentacoes m 
                             JOIN produtos p ON m.produto_id = p.id
                             WHERE DATE(m.data) BETWEEN '$inicio' AND '$fim'
                             ORDER BY m.data DESC");
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        echo json_encode($out);
        break;

    default:
        echo json_encode(["erro" => "Ação inválida"]);
}
?>
