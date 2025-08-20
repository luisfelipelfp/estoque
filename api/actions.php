<?php
header("Content-Type: application/json");
require_once "db.php";

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? null);

if (!$action) {
    echo json_encode(["erro" => "Ação inválida"]);
    exit;
}

switch ($action) {
    case "listar":
        $stmt = $pdo->query("SELECT * FROM produtos ORDER BY id DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case "cadastrar":
        $nome = $input['nome'] ?? null;
        $qtd = $input['quantidade'] ?? 0;
        if ($nome) {
            $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
            $stmt->execute([$nome, $qtd]);
            echo json_encode(["sucesso" => true]);
        } else {
            echo json_encode(["erro" => "Nome obrigatório"]);
        }
        break;

    // outras ações (movimentar, remover, relatorio) iguais à versão anterior

    default:
        echo json_encode(["erro" => "Ação inválida"]);
}
