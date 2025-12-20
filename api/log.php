<?php
// api/log.php
declare(strict_types=1);

/**
 * ==========================================
 * Sistema central de logs da API
 * ==========================================
 * - Logs separados por contexto
 * - NÃO usa error_log (incompatível com PHP-FPM)
 * - Escrita direta em arquivo (produção-safe)
 * ==========================================
 */

const LOG_DIR = __DIR__ . '/../logs_api';

static $LOG_INITIALIZED = [];

/**
 * Inicializa o log de um contexto
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

    $arquivo = LOG_DIR . "/{$contexto}.log";

    if (!file_exists($arquivo)) {
        touch($arquivo);
        chmod($arquivo, 0664);
    }

    // Captura exceções não tratadas
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

    // Captura fatal errors
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
 * Escrita física no arquivo
 */
function writeLog(string $contexto, string $nivel, string $mensagem): void
{
    $data = date('Y-m-d H:i:s');
    $linha = "[$data] [$contexto] $nivel: $mensagem" . PHP_EOL;

    file_put_contents(
        LOG_DIR . "/{$contexto}.log",
        $linha,
        FILE_APPEND | LOCK_EX
    );
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
    $extra = [];

    if ($arquivo) $extra[] = "Arquivo: $arquivo";
    if ($linha)   $extra[] = "Linha: $linha";

    $msg = $mensagem;

    if ($extra) {
        $msg .= ' | ' . implode(' | ', $extra);
    }

    writeLog($contexto, 'ERROR', $msg);

    if ($trace) {
        writeLog($contexto, 'TRACE', $trace);
    }
}

/**
 * Log informativo
 */
function logInfo(string $contexto, string $mensagem, array $dados = []): void
{
    if ($dados) {
        $mensagem .= ' | Dados: ' . json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    writeLog($contexto, 'INFO', $mensagem);
}

/**
 * Log de aviso
 */
function logWarning(string $contexto, string $mensagem, array $dados = []): void
{
    if ($dados) {
        $mensagem .= ' | Dados: ' . json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    writeLog($contexto, 'WARNING', $mensagem);
}
