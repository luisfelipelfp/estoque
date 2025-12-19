<?php
// =======================================
// api/utils.php
// Funções utilitárias globais
// Compatível PHP 8.2+ / 8.5
// =======================================

declare(strict_types=1);

require_once __DIR__ . '/log.php';

// =======================================
// 🔹 Resposta padronizada (array)
// =======================================
if (!function_exists('resposta')) {

    /**
     * Monta payload padrão de resposta
     */
    function resposta(
        bool $sucesso,
        string $mensagem = '',
        mixed $dados = null
    ): array {
        return [
            'sucesso'  => $sucesso,
            'mensagem' => $mensagem,
            'dados'    => $dados
        ];
    }
}

// =======================================
// 🔹 Resposta JSON segura (finaliza script)
// =======================================
if (!function_exists('json_response')) {

    /**
     * Envia resposta JSON e encerra execução
     */
    function json_response(
        bool $sucesso,
        string $mensagem = '',
        mixed $dados = null,
        int $httpCode = 200
    ): void {

        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');

        // Limpa qualquer buffer ativo (PHP 8.5 safe)
        if (ob_get_level() > 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $payload = resposta($sucesso, $mensagem, $dados);

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // Fallback seguro em caso de erro de JSON
        if ($json === false) {

            logError('utils', 'Erro ao gerar JSON', [
                'erro'    => json_last_error_msg(),
                'payload' => $payload
            ]);

            $json = json_encode([
                'sucesso'  => false,
                'mensagem' => 'Erro interno ao gerar resposta.',
                'dados'    => null
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        echo $json;
        flush();
        exit;
    }
}

// =======================================
// 🔹 Helper: valida método HTTP
// =======================================
if (!function_exists('require_method')) {

    /**
     * Garante método HTTP esperado
     */
    function require_method(string $method, string $origem): void {

        $esperado = strtoupper($method);
        $recebido = $_SERVER['REQUEST_METHOD'] ?? 'desconhecido';

        if ($recebido !== $esperado) {

            logWarning($origem, 'Método HTTP inválido', [
                'recebido' => $recebido,
                'esperado' => $esperado
            ]);

            json_response(false, 'Método inválido.', null, 405);
        }
    }
}

// =======================================
// 🔹 Helper: leitura segura de JSON
// =======================================
if (!function_exists('get_json_input')) {

    /**
     * Lê e valida JSON do corpo da requisição
     */
    function get_json_input(string $origem): array {

        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {

            logWarning($origem, 'JSON inválido recebido', [
                'raw' => $raw
            ]);

            return [];
        }

        return $data;
    }
}
