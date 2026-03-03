<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

function db(): mysqli
{
    initLog('db');

    $host   = '192.168.15.100';
    $user   = 'root';
    $pass   = '#Shakka01';
    $dbname = 'estoque';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli($host, $user, $pass, $dbname);
        $conn->set_charset('utf8mb4');

        logInfo('db', 'Conexão com banco estabelecida');

        return $conn;

    } catch (mysqli_sql_exception $e) {

        logError(
            'db',
            'Erro ao conectar no banco',
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        );

        json_response(false, 'Erro ao conectar ao banco.', null, 500);
    }
}
