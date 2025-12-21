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
 * Envia resposta JSON padronizada e encerra execução
 */
function json_response(
    bool $sucesso,
    string $mensagem = '',
    mixed $dados = null,
    int $httpCode = 200
): never {

    // 🔥 Limpa buffers ANTES de headers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');

    $payload = resposta($sucesso, $mensagem, $dados);

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {

        logError(
            'utils',
            'Erro ao gerar JSON',
            [
                'erro' => json_last_error_msg(),
                'payload' => $payload
            ]
        );

        echo '{"sucesso":false,"mensagem":"Erro interno","dados":null}';
        exit;
    }

    echo $json;
    exit;
}
