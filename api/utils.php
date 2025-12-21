<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

function resposta(bool $sucesso, string $mensagem = '', mixed $dados = null): array
{
    return [
        'sucesso'  => $sucesso,
        'mensagem' => $mensagem,
        'dados'    => $dados
    ];
}

function json_response(
    bool $sucesso,
    string $mensagem = '',
    mixed $dados = null,
    int $httpCode = 200
): void {

    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $json = json_encode(
        resposta($sucesso, $mensagem, $dados),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        logError('utils', 'Erro ao gerar JSON', json_last_error_msg());
        $json = '{"sucesso":false,"mensagem":"Erro interno","dados":null}';
    }

    echo $json;
    exit;
}
