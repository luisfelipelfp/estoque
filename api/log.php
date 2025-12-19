<?php
declare(strict_types=1);

/**
 * Sistema central de logs da API
 * Compatível PHP 8.2+ / 8.5
 */

const LOG_DIR = __DIR__ . '/../logs_api';

static $LOG_INITIALIZED = [];

/**
 * Inicializa sistema de log
 */
function initLog(string $contexto): void
{
    global $LOG_INITIALIZED;

    if (isset($LOG_INITIALIZED[$contexto])) {
        return;
    }

    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0775, true);
    }

    $logFile = LOG_DIR . "/{$contexto}.log";

    ini_set('log_errors', '1');
    ini_set('error_log', $logFile);
    ini_set('display_errors', '0');

    set_error_handler(function (
        int $severity,
        string $message,
        string $file,
        int $line
    ) use ($contexto): bool {

        logError($contexto, "PHP ERROR [$severity] $message", $file, $line);
        return true;
    });

    set_exception_handler(function (Throwable $e) use ($contexto): void {

        logError(
            $contexto,
            'EXCEPTION: ' . $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        http_response_code(500);
        echo json_encode([
            'sucesso'  => false,
            'mensagem' => 'Erro interno no servidor.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    });

    register_shutdown_function(function () use ($contexto): void {

        $error = error_get_last();

        if ($error && in_array(
            $error['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
            true
        )) {
            logError(
                $contexto,
                'FATAL ERROR: ' . $error['message'],
                $error['file'] ?? null,
                $error['line'] ?? null
            );
        }
    });

    $LOG_INITIALIZED[$contexto] = true;
}

/**
 * Log de erro
 */
function logError(
    string $contexto,
    string $mensagem,
    ?string $arquivo = null,
    ?int $linha = null,
    ?string $trace = null
): void {

    $data = date('Y-m-d H:i:s');

    $log = "[$data] [$contexto] ERROR: $mensagem";

    if ($arquivo) $log .= " | Arquivo: $arquivo";
    if ($linha)   $log .= " | Linha: $linha";
    if ($trace)   $log .= PHP_EOL . $trace;

    error_log($log);
}

/**
 * Log informativo
 */
function logInfo(string $contexto, string $mensagem, array $dados = []): void
{
    $data = date('Y-m-d H:i:s');

    $log = "[$data] [$contexto] INFO: $mensagem";

    if (!empty($dados)) {
        $log .= ' | Dados: ' . json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    error_log($log);
}

/**
 * Log de aviso
 */
function logWarning(string $contexto, string $mensagem, array $dados = []): void
{
    $data = date('Y-m-d H:i:s');

    $log = "[$data] [$contexto] WARNING: $mensagem";

    if (!empty($dados)) {
        $log .= ' | Dados: ' . json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    error_log($log);
}
