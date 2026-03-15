<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

function db_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    if ($value !== false) {
        $value = trim((string)$value);
        return $value !== '' ? $value : $default;
    }

    if (isset($_ENV[$key])) {
        $value = trim((string)$_ENV[$key]);
        return $value !== '' ? $value : $default;
    }

    if (isset($_SERVER[$key])) {
        $value = trim((string)$_SERVER[$key]);
        return $value !== '' ? $value : $default;
    }

    return $default;
}

function db_env_int(string $key, int $default): int
{
    $value = db_env($key);

    if ($value === null || $value === '') {
        return $default;
    }

    if (!preg_match('/^\d+$/', $value)) {
        return $default;
    }

    $intValue = (int)$value;
    return $intValue > 0 ? $intValue : $default;
}

function db_config(): array
{
    return [
        'host'   => db_env('ESTOQUE_DB_HOST', '127.0.0.1'),
        'user'   => db_env('ESTOQUE_DB_USER', ''),
        'pass'   => db_env('ESTOQUE_DB_PASS', ''),
        'dbname' => db_env('ESTOQUE_DB_NAME', 'estoque'),
        'port'   => db_env_int('ESTOQUE_DB_PORT', 3306),
    ];
}

function db_validate_config(array $config): void
{
    if (trim((string)($config['host'] ?? '')) === '') {
        throw new RuntimeException('Configuração do banco inválida: host não informado.');
    }

    if (trim((string)($config['user'] ?? '')) === '') {
        throw new RuntimeException('Configuração do banco inválida: usuário não informado.');
    }

    if (trim((string)($config['dbname'] ?? '')) === '') {
        throw new RuntimeException('Configuração do banco inválida: nome do banco não informado.');
    }

    $port = (int)($config['port'] ?? 0);
    if ($port <= 0 || $port > 65535) {
        throw new RuntimeException('Configuração do banco inválida: porta inválida.');
    }
}

function db_safe_log_context(array $config): array
{
    return [
        'host'  => (string)($config['host'] ?? ''),
        'banco' => (string)($config['dbname'] ?? ''),
        'porta' => (int)($config['port'] ?? 0),
    ];
}

function db(): mysqli
{
    initLog('db');

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $config = db_config();
        db_validate_config($config);

        $conn = new mysqli(
            (string)$config['host'],
            (string)$config['user'],
            (string)$config['pass'],
            (string)$config['dbname'],
            (int)$config['port']
        );

        $conn->set_charset('utf8mb4');

        logInfo('db', 'Conexão com banco estabelecida', db_safe_log_context($config));

        return $conn;
    } catch (Throwable $e) {
        $config = db_config();

        logError('db', 'Erro ao conectar no banco', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage(),
            'contexto' => db_safe_log_context($config)
        ]);

        json_response(false, 'Erro ao conectar ao banco.', null, 500);
    }
}