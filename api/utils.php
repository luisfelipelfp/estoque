<?php
// =======================================
// api/utils.php
// Funções utilitárias globais
// =======================================

if (!function_exists("resposta")) {
    function resposta($sucesso, $mensagem = "", $dados = null) {
        return [
            "sucesso" => $sucesso,
            "mensagem" => $mensagem,
            "dados"    => $dados
        ];
    }
}

if (!function_exists("debug_log")) {
    function debug_log($msg, $origem = "geral") {
        $logFile = __DIR__ . "/debug.log";
        $data = date("Y-m-d H:i:s");
        file_put_contents($logFile, "[$data][$origem] $msg\n", FILE_APPEND);
    }
}
