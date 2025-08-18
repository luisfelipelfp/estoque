<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost", "root", "#Shakka01", "estoque");

if ($conn->connect_error) {
    die(json_encode(["erro" => "Falha na conexão: " . $conn->connect_error]));
}

$acao = $_GET['acao'] ?? '';
$data = json_decode(file_get_contents("php://input"), true);

if ($acao == 'listar') {
    $res = $conn->query("SELECT * FROM produtos ORDER BY nome");
    $produtos = [];
    while ($row = $res->fetch_assoc()) {
        $produtos[] = $row;
    }
    echo json_encode($produtos);

} elseif ($acao == 'adicionar') {
    $nome = $conn->real_escape_string($data['nome']);
    $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome', 0)");
    echo json_encode(['sucesso' => true]);

} elseif ($acao == 'entrada') {
    $nome = $conn->real_escape_string($data['nome']);
    $qtd = intval($data['qtd']);

    // Busca ID do produto
    $res = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
    $row = $res->fetch_assoc();
    $produto_id = $row['id'];

    // Atualiza estoque
    $conn->query("UPDATE produtos SET quantidade = quantidade + $qtd WHERE id=$produto_id");

    // Registra movimentação
    $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) 
                  VALUES ($produto_id, $qtd, 'entrada', NOW())");

    echo json_encode(['sucesso' => true]);

} elseif ($acao == 'saida') {
    $nome = $conn->real_escape_string($data['nome']);
    $qtd = intval($data['qtd']);

    // Busca ID do produto
    $res = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
    $row = $res->fetch_assoc();
    $produto_id = $row['id'];

    // Atualiza estoque
    $conn->query("UPDATE produtos SET quantidade = quantidade - $qtd WHERE id=$produto_id");

    // Registra movimentação
    $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) 
                  VALUES ($produto_id, $qtd, 'saida', NOW())");

    echo json_encode(['sucesso' => true]);

} elseif ($acao == 'relatorio') {
    $inicio = $conn->real_escape_string($_GET['inicio']);
    $fim = $conn->real_escape_string($_GET['fim']);

    $res = $conn->query("
        SELECT m.id, p.nome, m.quantidade, m.tipo, m.data
        FROM movimentacoes m
        JOIN produtos p ON m.produto_id = p.id
        WHERE m.data BETWEEN '$inicio 00:00:00' AND '$fim 23:59:59'
        ORDER BY m.data DESC
    ");

    $relatorio = [];
    while ($row = $res->fetch_assoc()) {
        $relatorio[] = $row;
    }
    echo json_encode($relatorio);

} else {
    echo json_encode(["erro" => "Ação inválida"]);
}
?>
