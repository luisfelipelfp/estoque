<?php
// =======================================
// api/utils.php
// Funções utilitárias globais
// =======================================

// ✅ Padroniza respostas da API
if (!function_exists("resposta")) {
    /**
     * Cria um array padronizado de resposta.
     */
    function resposta(bool $sucesso, string $mensagem = "", $dados = null): array {
        return [
            "sucesso"  => $sucesso,
            "mensagem" => $mensagem,
            "dados"    => $dados
        ];
    }
}

// ✅ Log de debug genérico e seguro
if (!function_exists("debug_log")) {
    /**
     * Grava uma linha no log de debug.
     *
     * @param mixed  $msg    Mensagem, array ou objeto (será convertido para JSON)
     * @param string $origem Origem do log (ex: arquivo ou módulo)
     */
    function debug_log($msg, string $origem = "geral"): void {
        $logFile = __DIR__ . "/debug.log";
        $data    = date("Y-m-d H:i:s");

        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Sanitiza quebras de linha
        $msg = str_replace(["\r", "\n"], " ", (string)$msg);

        file_put_contents($logFile, "[$data][$origem] $msg\n", FILE_APPEND);
    }
}

// ✅ Atalho seguro para enviar resposta JSON e encerrar
if (!function_exists("json_response")) {
    /**
     * Envia uma resposta JSON padronizada e encerra a execução.
     */
    function json_response(
        bool $sucesso,
        string $mensagem = "",
        $dados = null,
        int $httpCode = 200
    ): void {
        http_response_code($httpCode);
        header("Content-Type: application/json; charset=utf-8");

        // 🔹 Limpa buffer de saída apenas se existir
        if (ob_get_level() > 0) {
            ob_clean();
        }

        $payload = resposta($sucesso, $mensagem, $dados);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 🔹 Tratamento de erro de JSON (raro, mas possível)
        if ($json === false) {
            $erro = json_last_error_msg();
            debug_log("Falha ao gerar JSON: $erro", "json_response");
            $json = json_encode([
                "sucesso"  => false,
                "mensagem" => "Erro interno ao gerar resposta JSON.",
                "dados"    => null
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        echo $json;
        flush();
        exit;
    }
}
