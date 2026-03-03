<?php
declare(strict_types=1);

// Gera hash seguro usando o algoritmo padrão do PHP (bcrypt/argon2)
$senha = "123456";

// PASSWORD_DEFAULT no PHP 8.5 atualmente usa bcrypt por padrão
$hash = password_hash($senha, PASSWORD_DEFAULT, [
    'cost' => 12 // custo recomendado para 2025
]);

echo $hash;
