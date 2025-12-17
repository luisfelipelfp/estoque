<?php
require __DIR__ . '/db.php';

$conn = db(); // chama a função e recebe mysqli

$result = $conn->query("SELECT VERSION() AS version");
$row = $result->fetch_assoc();

header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'mariadb_version' => $row['version']
]);
