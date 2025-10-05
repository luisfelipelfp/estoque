<?php
// =======================================
// api/utils.php
// Funções utilitárias globais
// =======================================

// ✅ Padroniza respostas da API
if (!function_exists("resposta")) {
    function resposta(bool $sucesso, string $mensagem = "", $dados = null): array {
        return [
            "sucesso"  => $sucesso,
            "mensagem" => $mensagem,
            "dados"    => $dados
        ];
    }
}

// ✅ Log de debug genérico
if (!function_exists("debug_log")) {
    /**
     * Grava uma linha no log de debug.
     *
     * @param mixed  $msg    Mensagem, array ou objeto (será convertido para JSON)
     * @param string $origem Arquivo ou módulo de origem
     */
    function debug_log($msg, string $origem = "geral"): void {
        $logFile = __DIR__ . "/debug.log";
        $data    = date("Y-m-d H:i:s");

        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents($logFile, "[$data][$origem] $msg\n", FILE_APPEND);
    }
}

// ✅ Atalho para enviar resposta JSON e encerrar
if (!function_exists("json_response")) {
    function json_response(
        bool $sucesso,
        string $mensagem = "",
        $dados = null,
        int $httpCode = 200
    ): void {
        http_response_code($httpCode);
        header("Content-Type: application/json; charset=utf-8");
        ob_clean(); // 🔹 remove qualquer saída anterior
        echo json_encode(
            resposta($sucesso, $mensagem, $dados),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
