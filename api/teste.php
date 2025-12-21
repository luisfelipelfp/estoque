<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

initLog('teste');

try {
    $conn = db();
    json_response(true, 'Conexão OK');
} catch (Throwable $e) {
    json_response(false, $e->getMessage());
}
