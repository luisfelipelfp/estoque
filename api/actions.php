<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host = "192.168.15.100";
$db   = "estoque";
$user = "root";
$pass = "#Shakka01";
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo json_encode(["erro" => "Falha na conexão: " . $e->getMessage()]);
    exit;
}

// Lê dados JSON enviados pelo frontend
$data = json_decode(file_get_contents("php://input"), true);
$acao = $data['acao'] ?? '';

if ($acao === 'cadastrar') {
    $nome = $data['nome'];
    $qtd  = (int) $data['qtd'];

    // Verifica se já existe
    $stmt = $pdo->prepare("SELECT id FROM produtos WHERE nome = ?");
    $stmt->execute([$nome]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['erro' => 'Produto já existe']);
        exit;
    }

    // Insere produto
    $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
    $stmt->execute([$nome, $qtd]);
    $produto_id = $pdo->lastInsertId();

    // Registra movimentação inicial
    if ($qtd > 0) {
        $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) 
                               VALUES (?, ?, 'entrada', NOW())");
        $stmt->execute([$produto_id, $qtd]);
    }

    echo json_encode(['sucesso' => true]);
}

elseif ($acao === 'entrada' || $acao === 'saida') {
    $nome = $data['nome'];
    $qtd  = (int) $data['qtd'];

    $stmt = $pdo->prepare("SELECT id, quantidade FROM produtos WHERE nome = ?");
    $stmt->execute([$nome]);
    $produto = $stmt->fetch();

    if (!$produto) {
        echo json_encode(['erro' => 'Produto não encontrado']);
        exit;
    }

    $produto_id = $produto['id'];

    if ($acao === 'entrada') {
        $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        $stmt->execute([$qtd, $produto_id]);
        $tipo = 'entrada';
    } else {
        $novaQtd = $produto['quantidade'] - $qtd;
        if ($novaQtd < 0) {
            echo json_encode(['erro' => 'Estoque insuficiente']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE produtos SET quantidade = ? WHERE id = ?");
        $stmt->execute([$novaQtd, $produto_id]);
        $tipo = 'saida';
    }

    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) 
                           VALUES (?, ?, ?, NOW())");
    $stmt->execute([$produto_id, $qtd, $tipo]);

    echo json_encode(['sucesso' => true]);
}

elseif ($acao === 'remover') {
    $nome = $data['nome'];
    $stmt = $pdo->prepare("DELETE FROM produtos WHERE nome = ?");
    $stmt->execute([$nome]);

    echo json_encode(['sucesso' => true]);
}

elseif ($acao === 'listar') {
    $stmt = $pdo->query("SELECT * FROM produtos ORDER BY nome ASC");
    $produtos = $stmt->fetchAll();
    echo json_encode($produtos);
}

elseif ($acao === 'relatorio') {
    $inicio = $data['inicio'];
    $fim    = $data['fim'];

    $stmt = $pdo->prepare("
        SELECT m.id, m.produto_id, p.nome, m.quantidade, m.tipo, m.data 
        FROM movimentacoes m
        JOIN produtos p ON m.produto_id = p.id
        WHERE m.data BETWEEN ? AND ?
        ORDER BY m.data ASC
    ");
    $stmt->execute([$inicio . " 00:00:00", $fim . " 23:59:59"]);
    $rel = $stmt->fetchAll();

    echo json_encode($rel);
}

else {
    echo json_encode(['erro' => 'Ação inválida']);
}
