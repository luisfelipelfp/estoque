<?php
header('Content-Type: application/json');

$host = "192.168.15.100";
$db = "estoque";
$user = "root";
$pass = "#Shakka01";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e){
    die(json_encode(['erro'=>'Falha na conexão: '.$e->getMessage()]));
}

$data = json_decode(file_get_contents("php://input"), true);
$acao = $data['acao'] ?? '';

if($acao === 'cadastrar'){
    $nome = $data['nome'] ?? '';
    $qtd = intval($data['qtd'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE nome=?");
    $stmt->execute([$nome]);
    if($stmt->rowCount() > 0){
        echo json_encode(['erro'=>'Produto já existe']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?,?)");
    $stmt->execute([$nome,$qtd]);
    $produto_id = $pdo->lastInsertId();

    if($qtd > 0){
        $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) VALUES (?,?,?,NOW())");
        $stmt->execute([$produto_id,$qtd,'entrada']);
    }

    echo json_encode(['sucesso'=>true]);

} elseif($acao === 'entrada' || $acao === 'saida'){
    $nome = $data['nome'] ?? '';
    $qtd = intval($data['qtd'] ?? 0);

    $stmt = $pdo->prepare("SELECT id, quantidade FROM produtos WHERE nome=?");
    $stmt->execute([$nome]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$produto){
        echo json_encode(['erro'=>'Produto não encontrado']);
        exit;
    }

    $produto_id = $produto['id'];
    $novaQtd = $acao === 'entrada' ? $produto['quantidade'] + $qtd : $produto['quantidade'] - $qtd;
    if($acao === 'saida' && $novaQtd < 0){
        echo json_encode(['erro'=>'Estoque insuficiente']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE produtos SET quantidade=? WHERE id=?");
    $stmt->execute([$novaQtd,$produto_id]);

    $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) VALUES (?,?,?,NOW())");
    $stmt->execute([$produto_id,$qtd,$acao]);

    echo json_encode(['sucesso'=>true]);

} elseif($acao === 'remover'){
    $nome = $data['nome'] ?? '';
    $stmt = $pdo->prepare("DELETE FROM produtos WHERE nome=?");
    $stmt->execute([$nome]);
    echo json_encode(['sucesso'=>true]);

} elseif($acao === 'listar'){
    $stmt = $pdo->query("SELECT * FROM produtos");
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($produtos);

} elseif($acao === 'relatorio'){
    $inicio = $data['inicio'] ?? '';
    $fim = $data['fim'] ?? '';

    $stmt = $pdo->prepare("
        SELECT m.id, m.produto_id, p.nome, m.quantidade, m.tipo, m.data
        FROM movimentacoes m
        JOIN produtos p ON m.produto_id = p.id
        WHERE m.data BETWEEN ? AND ?
        ORDER BY m.data ASC
    ");
    $stmt->execute(["$inicio 00:00:00", "$fim 23:59:59"]);
    $rel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rel);
}

?>
