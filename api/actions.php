<?php
header('Content-Type: application/json');

$host = '192.168.15.100';
$db = 'estoque';
$user = 'root';
$pass = '#Shakka01';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die(json_encode(['sucesso'=>false,'mensagem'=>'Erro de conexão']));

$acao = $_REQUEST['acao'] ?? '';

if ($acao === 'cadastrar_produto') {
    $nome = $_POST['nome'] ?? '';
    $quantidade = intval($_POST['quantidade'] ?? 0);

    if (!$nome || $quantidade < 0) die(json_encode(['sucesso'=>false,'mensagem'=>'Dados inválidos']));

    // Evitar duplicados
    $check = $conn->prepare("SELECT id FROM produtos WHERE nome=?");
    $check->bind_param('s',$nome);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) die(json_encode(['sucesso'=>false,'mensagem'=>'Produto já cadastrado']));

    $stmt = $conn->prepare("INSERT INTO produtos(nome,quantidade) VALUES(?,?)");
    $stmt->bind_param('si',$nome,$quantidade);
    $stmt->execute();
    echo json_encode(['sucesso'=>true,'mensagem'=>'Produto cadastrado']);
}

elseif ($acao === 'listar_produtos') {
    $res = $conn->query("SELECT * FROM produtos");
    $produtos = [];
    while ($row = $res->fetch_assoc()) $produtos[] = $row;
    echo json_encode($produtos);
}

elseif ($acao === 'movimentacao') {
    $produto_id = intval($_POST['produto_id'] ?? 0);
    $tipo = $_POST['tipo'] ?? '';
    $quantidade = intval($_POST['quantidade'] ?? 0);

    if (!$produto_id || !$tipo || $quantidade <= 0) die(json_encode(['sucesso'=>false,'mensagem'=>'Dados inválidos']));

    $res = $conn->query("SELECT quantidade FROM produtos WHERE id=$produto_id");
    $produto = $res->fetch_assoc();

    if ($tipo === 'saida' && $produto['quantidade'] < $quantidade)
        die(json_encode(['sucesso'=>false,'mensagem'=>'Quantidade insuficiente']));

    $novoQtd = ($tipo === 'entrada') ? $produto['quantidade'] + $quantidade : $produto['quantidade'] - $quantidade;
    $conn->query("UPDATE produtos SET quantidade=$novoQtd WHERE id=$produto_id");
    $stmt = $conn->prepare("INSERT INTO movimentacoes(produto_id,tipo,quantidade) VALUES(?,?,?)");
    $stmt->bind_param('isi',$produto_id,$tipo,$quantidade);
    $stmt->execute();

    echo json_encode(['sucesso'=>true,'mensagem'=>'Movimentação registrada']);
}

elseif ($acao === 'relatorio_intervalo') {
    $inicio = $_POST['inicio'] ?? '';
    $fim = $_POST['fim'] ?? '';
    $stmt = $conn->prepare("SELECT m.*, p.nome FROM movimentacoes m JOIN produtos p ON m.produto_id=p.id WHERE m.data BETWEEN ? AND ?");
    $stmt->bind_param('ss', $inicio, $fim);
    $stmt->execute();
    $res = $stmt->get_result();
    $movs = [];
    while ($row = $res->fetch_assoc()) $movs[] = $row;
    echo json_encode($movs);
}

$conn->close();
?>
