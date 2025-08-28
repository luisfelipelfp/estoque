<?php
header("Content-Type: application/json; charset=UTF-8");

require_once "db.php";
require_once "produtos.php";
require_once "movimentacoes.php";
require_once "relatorios.php";

$acao = $_REQUEST["acao"] ?? null;

if (!$acao) {
    echo json_encode(["sucesso" => false, "mensagem" => "Nenhuma ação especificada."]);
    exit;
}

switch ($acao) {
    // Produtos
    case "listar_produtos": listar_produtos(); break;
    case "adicionar_produto": adicionar_produto(); break;
    case "remover_produto": remover_produto(); break;

    // Movimentações
    case "listar_movimentacoes": listar_movimentacoes(); break;
    case "registrar_movimentacao": registrar_movimentacao(); break;

    // Relatórios
    case "relatorio": relatorio(); break;

    default:
        echo json_encode(["sucesso" => false, "mensagem" => "Ação desconhecida."]);
        break;
}
