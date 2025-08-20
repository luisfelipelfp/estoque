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

            $novaQtd = $tipo == "entrada"
                ? $prod["quantidade"] + $quantidade
                : $prod["quantidade"] - $quantidade;

            $conn->query("UPDATE produtos SET quantidade=$novaQtd WHERE id=$id");
            $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) 
                          VALUES ($id, $quantidade, '$tipo', NOW())");
        }

        echo json_encode(["status" => "ok", "mensagem" => "Movimentação registrada"]);
        break;

    case "remover":
        $nome = $conn->real_escape_string($input["nome"]);
        $res = $conn->query("SELECT id FROM prod
