<?php
// =======================================
// api/utils.php
// Funções utilitárias globais (compatível com PHP 8.5)
// =======================================

// =======================================
// 🔹 Resposta padronizada
// =======================================
if (!function_exists("resposta")) {

    /**
     * Cria uma resposta padronizada.
     */
    function resposta(bool $sucesso, string $mensagem = "", mixed $dados = null): array {
        return [
            "sucesso"  => $sucesso,
            "mensagem" => $mensagem,
            "dados"    => $dados
        ];
    }
}

// =======================================
// 🔹 Log seguro
// =======================================
if (!function_exists("debug_log")) {

    /**
     * Escreve no log de debug com segurança.
     *
     * @param mixed  $msg
     * @param string $origem
     */
    function debug_log(mixed $msg, string $origem = "geral"): void {
        $logFile = __DIR__ . "/debug.log";
        $data    = date("Y-m-d H:i:s");

        // Converte arrays/objetos para JSON
        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Garante que msg é string
        $msg = (string)$msg;

        // Remove quebras de linha para manter integridade
        $msg = str_replace(["\r", "\n"], " ", $msg);

        // Escreve log
        @file_put_contents($logFile, "[$data][$origem] $msg\n", FILE_APPEND);
    }
}

// =======================================
// 🔹 Resposta JSON com saída segura
// =======================================
if (!function_exists("json_response")) {

    /**
     * Envia uma resposta JSON e finaliza o script.
     */
    function json_response(
        bool $sucesso,
        string $mensagem = "",
        mixed $dados = null,
        int $httpCode = 200
    ): void {

        http_response_code($httpCode);
        header("Content-Type: application/json; charset=utf-8");

        // Limpa buffer de saída (se existir)
        if (ob_get_level() > 0) {
            while (ob_get_level() > 0) {
                ob_end_clean(); // Evita warnings no PHP 8.5
            }
        }

        $payload = resposta($sucesso, $mensagem, $dados);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Trata erros de codificação JSON
        if ($json === false) {
            $erro = json_last_error_msg();
            debug_log("Erro ao gerar JSON: $erro", "json_response");

            $json = json_encode([
                "sucesso"  => false,
                "mensagem" => "Erro ao gerar resposta JSON.",
                "dados"    => null
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        echo $json;
        flush();
        exit;
    }
}
