<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

session_destroy();
echo json_encode(resposta(true, "Logout realizado."));
   