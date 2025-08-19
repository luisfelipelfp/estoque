<?php
// Mostrar erros no navegador (debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host = '192.168.15.100';
$db   = 'estoque';
$user = 'root';
$pass = '#Shakka01';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}

// Agora aceita tanto POST quanto GET
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'add') {
    $nome = $_POST['nome'];
    $quantidade = (int) $_POST['quantidade'];

    $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
    $stmt->execute([$nome, $quantidade]);

    $produto_id = $pdo->lastInsertId();

    // Registrar entrada na movimentação
    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, 'entrada', ?)");
    $stmt->execute([$produto_id, $nome, $quantidade]);

    echo json_encode(["success" => "Produto adicionado com sucesso!"]);
}

elseif ($action === 'entrada') {
    $produto_id = (int) $_POST['produto_id'];
    $quantidade = (int) $_POST['quantidade'];

    $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
    $stmt->execute([$quantidade, $produto_id]);

    $stmt = $pdo->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $produto_nome = $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, 'entrada', ?)");
    $stmt->execute([$produto_id, $produto_nome, $quantidade]);

    echo json_encode(["success" => "Entrada registrada!"]);
}

elseif ($action === 'saida') {
    $produto_id = (int) $_POST['produto_id'];
    $quantidade = (int) $_POST['quantidade'];

    $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
    $stmt->execute([$quantidade, $produto_id]);

    $stmt = $pdo->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $produto_nome = $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, 'saida', ?)");
    $stmt->execute([$produto_id, $produto_nome, $quantidade]);

    echo json_encode(["success" => "Saída registrada!"]);
}

elseif ($action === 'remove') {
    $produto_id = (int) $_POST['produto_id'];

    $stmt = $pdo->prepare("SELECT nome, quantidade FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $produto = $stmt->fetch();

    if ($produto) {
        $produto_nome = $produto['nome'];
        $quantidade = $produto['quantidade'];

        // Registrar como movimentação final (remoção = saída do estoque)
        $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, 'saida', ?)");
        $stmt->execute([$produto_id, $produto_nome, $quantidade]);

        // Remover produto da tabela produtos
        $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->execute([$produto_id]);

        echo json_encode(["success" => "Produto removido, histórico mantido."]);
    } else {
        echo json_encode(["error" => "Produto não encontrado."]);
    }
}

elseif ($action === 'list') {
    $stmt = $pdo->query("SELECT * FROM produtos ORDER BY nome ASC");
    $produtos = $stmt->fetchAll();
    echo json_encode($produtos);
}

elseif ($action === 'relatorio') {
    $stmt = $pdo->query("SELECT * FROM movimentacoes ORDER BY data DESC");
    $movimentacoes = $stmt->fetchAll();
    echo json_encode($movimentacoes);
}

else {
    echo json_encode(["error" => "Ação inválida ou não informada."]);
}
