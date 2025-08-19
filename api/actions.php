<?php
$host = 'localhost';
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
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    // Adicionar produto
    $nome = $_POST['nome'];
    $quantidade = (int) $_POST['quantidade'];

    $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
    $stmt->execute([$nome, $quantidade]);

    $produto_id = $pdo->lastInsertId();

    // Registrar entrada na movimentação
    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, 'entrada', ?)");
    $stmt->execute([$produto_id, $nome, $quantidade]);

    echo "Produto adicionado com sucesso!";
}

elseif ($action === 'entrada') {
    // Entrada de produtos
    $produto_id = (int) $_POST['produto_id'];
    $quantidade = (int) $_POST['quantidade'];

    // Atualiza estoque
    $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
    $stmt->execute([$quantidade, $produto_id]);

    // Pega nome do produto
    $stmt = $pdo->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $produto_nome = $stmt->fetchColumn();

    // Registra movimentação
    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, 'entrada', ?)");
    $stmt->execute([$produto_id, $produto_nome, $quantidade]);

    echo "Entrada registrada!";
}

elseif ($action === 'saida') {
    // Saída de produtos
    $produto_id = (int) $_POST['produto_id'];
    $quantidade = (int) $_POST['quantidade'];

    // Atualiza estoque
    $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
    $stmt->execute([$quantidade, $produto_id]);

    // Pega nome do produto
    $stmt = $pdo->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $produto_nome = $stmt->fetchColumn();

    // Registra movimentação
    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, 'saida', ?)");
    $stmt->execute([$produto_id, $produto_nome, $quantidade]);

    echo "Saída registrada!";
}

elseif ($action === 'remove') {
    // Remover produto do cadastro, mas manter histórico
    $produto_id = (int) $_POST['produto_id'];

    // Pega nome e quantidade antes de apagar
    $stmt = $pdo->prepare("SELECT nome, quantidade FROM produtos WHERE id = ?");
    $stmt->execute([$produto_id]);
    $produto = $stmt->fetch();

    if ($produto) {
        $produto_nome = $produto['nome'];
        $quantidade = $produto['quantidade'];

        // Registrar a remoção como movimentação especial
        $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade) VALUES (?, ?, 'saida', ?)");
        $stmt->execute([$produto_id, $produto_nome, $quantidade]);

        // Agora sim remove da tabela produtos
        $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->execute([$produto_id]);

        echo "Produto removido (histórico mantido)!";
    } else {
        echo "Produto não encontrado.";
    }
}

elseif ($action === 'list') {
    // Listar produtos
    $stmt = $pdo->query("SELECT * FROM produtos");
    $produtos = $stmt->fetchAll();
    echo json_encode($produtos);
}

elseif ($action === 'relatorio') {
    // Relatório de movimentações
    $stmt = $pdo->query("SELECT * FROM movimentacoes ORDER BY data DESC");
    $movimentacoes = $stmt->fetchAll();
    echo json_encode($movimentacoes);
}
?>
