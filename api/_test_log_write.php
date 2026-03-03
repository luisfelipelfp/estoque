<?php
require_once __DIR__ . '/log.php';

initLog('login');

logInfo('login', 'TESTE DIRETO DE LOG', [
    'hora' => date('H:i:s')
]);

echo 'OK';