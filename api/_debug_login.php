<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "INICIO<br>";

require_once __DIR__ . '/log.php';
echo "LOG OK<br>";

require_once __DIR__ . '/utils.php';
echo "UTILS OK<br>";

require_once __DIR__ . '/db.php';
echo "DB OK<br>";

$conn = db();
echo "CONEXAO OK<br>";

exit;
