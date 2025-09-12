<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

function resposta($logado, $usuario = null) {
    return [
        "sucesso" => $logado,
        "usuario" => $usuario
    ];
}

if (isset($_SESSION["usuario"])) {
    echo json_encode(resposta(true, $_SESSION["usuario"]));
} else {
    echo json_encode(resposta(false, null));
}
