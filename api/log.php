Vou te mandar o meu api/log.php atual para que você possa analisar fazer todos os ajustes necessários e já me devolver de forma completa. Manda da mesma forma que mandou o ultimo, não travou.
api/log.php
<?php
declare(strict_types=1);

/**
 * ==========================================
 * Sistema central de logs da API
 * ==========================================
 */

const LOG_DIR = __DIR__ . '/../logs_api';

/**
 * Controle interno de contextos já inicializados
 */
$GLOBALS['LOG_INITIALIZED'] = $GLOBALS['LOG_INITIALIZED'] ?? [];

/**
 * Garante que o diretório de logs exista
 */
function ensureLogDir(): void
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0775, true);
    }
}

/**
 * Inicializa o log de um contexto
 */
function initLog(string $contexto): void
{
    if (isset($GLOBALS['LOG_INITIALIZED'][$contexto])) {
        return;
    }

    ensureLogDir();

    $arquivo = LOG_DIR . "/{$contexto}.log";

    if (!file_exists($arquivo)) {
        @touch($arquivo);
        @chmod($arquivo, 0664);
    }

    set_exception_handler(function (Throwable $e) use ($contexto): void {
        logError($contexto, 'EXCEPTION', [
            'mensagem' => $e->getMessage(),
            'arquivo'  => $e->getFile(),
            'linha'    => $e->getLine(),
            'trace'    => $e->getTraceAsString()
        ]);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'sucesso'  => false,
            'mensagem' => 'Erro interno no servidor.',
            'dados'    => null
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    });

    register_shutdown_function(function () use ($contexto): void {
        $error = error_get_last();

        if ($error && in_array(
            $error['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
            true
        )) {
            logError($contexto, 'FATAL ERROR', $error);
        }
    });

    $GLOBALS['LOG_INITIALIZED'][$contexto] = true;
}

/**
 * Escrita física no arquivo
 */
function writeLog(string $contexto, string $nivel, string $mensagem): void
{
    ensureLogDir();

    $arquivo = LOG_DIR . "/{$contexto}.log";
    $data = date('Y-m-d H:i:s');
    $linha = "[$data] [$contexto] $nivel: $mensagem" . PHP_EOL;

    $ok = @file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX);

    if ($ok === false) {
        error_log("[{$contexto}] {$nivel}: {$mensagem}");
    }
}

/**
 * Log de erro
 */
function logError(string $contexto, string $mensagem, array $dados = []): void
{
    $texto = $mensagem;

    if (!empty($dados)) {
        $json = json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $texto .= ' | ' . ($json !== false ? $json : 'Falha ao serializar dados do log');
    }

    writeLog($contexto, 'ERROR', $texto);
}

/**
 * Log informativo
 */
function logInfo(string $contexto, string $mensagem, array $dados = []): void
{
    if (!empty($dados)) {
        $json = json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $mensagem .= ' | ' . ($json !== false ? $json : 'Falha ao serializar dados do log');
    }

    writeLog($contexto, 'INFO', $mensagem);
}

/**
 * Log de aviso
 */
function logWarning(string $contexto, string $mensagem, array $dados = []): void
{
    if (!empty($dados)) {
        $json = json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $mensagem .= ' | ' . ($json !== false ? $json : 'Falha ao serializar dados do log');
    }

    writeLog($contexto, 'WARNING', $mensagem);
}
