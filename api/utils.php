<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

/**
 * Monta estrutura padrão de resposta
 */
function resposta(bool $sucesso, string $mensagem = '', mixed $dados = null): array
{
    return [
        'sucesso'  => $sucesso,
        'mensagem' => $mensagem,
        'dados'    => $dados
    ];
}

/**
 * Faz limpeza segura dos buffers antes de responder JSON
 */
function limpar_output_buffers(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

/**
 * Gera JSON com tratamento de erro
 */
function json_encode_safe(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json !== false) {
        return $json;
    }

    logError('utils', 'Erro ao gerar JSON', [
        'erro'    => json_last_error_msg(),
        'payload' => $payload
    ]);

    $fallback = [
        'sucesso'  => false,
        'mensagem' => 'Erro interno',
        'dados'    => null
    ];

    $jsonFallback = json_encode(
        $fallback,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($jsonFallback === false) {
        return '{"sucesso":false,"mensagem":"Erro interno","dados":null}';
    }

    return $jsonFallback;
}

/**
 * Envia resposta JSON padronizada e encerra execução
 */
function json_response(
    bool $sucesso,
    string $mensagem = '',
    mixed $dados = null,
    int $httpCode = 200
): never {
    limpar_output_buffers();

    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode_safe(resposta($sucesso, $mensagem, $dados));
    exit;
}