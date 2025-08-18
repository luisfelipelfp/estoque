<?php
header('Content-Type: application/json');

$host = "192.168.15.100";
$user = "root";
$pass = "#Shakka01";
$db = "estoque";

$conn = new mysqli($host, $user, $pass, $db);
if($conn->connect_error){ 
    die(json_encode(['erro'=>'Falha na conexão'])); 
}

$data = json_decode(file_get_contents("php://input"), true);
$acao = $data['acao'] ?? '';

if($acao == 'cadastrar'){
    $nome = $conn->real_escape_string($data['nome']);
    $qtd = intval($data['qtd']);
    
    $verifica = $conn->query("SELECT * FROM produtos WHERE nome='$nome'");
    if($verifica->num_rows > 0){
        echo json_encode(['erro'=>'Produto já existe']);
    } else {
        // Insere produto
        $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome',$qtd)");
        $produto_id = $conn->insert_id;

        // Registra movimentação inicial como "entrada"
        if($qtd > 0){
            $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) 
                          VALUES ($produto_id, $qtd, 'entrada', NOW())");
        }

        echo json_encode(['sucesso'=>true]);
    }

} elseif($acao == 'entrada' || $acao == 'saida'){
    $nome = $conn->real_escape_string($data['nome']);
    $qtd = intval($data['qtd']);
    
    // Busca o ID do produto
    $res = $conn->query("SELECT id, quantidade FROM produtos WHERE nome='$nome'");
    if($res->num_rows == 0){
        echo json_encode(['erro'=>'Produto não encontrado']);
        exit;
    }
    $produto = $res->fetch_assoc();
    $produto_id = $produto['id'];

    // Atualiza quantidade
    if($acao == 'entrada'){
        $conn->query("UPDATE produtos SET quantidade = quantidade + $qtd WHERE id=$produto_id");
        $tipo = 'entrada';
    } else {
        $novaQtd = $produto['quantidade'] - $qtd;
        if($novaQtd < 0){
            echo json_encode(['erro'=>'Estoque insuficiente']);
            exit;
        }
        $conn->query("UPDATE produtos SET quantidade = $novaQtd WHERE id=$produto_id");
        $tipo = 'saida';
    }

    // Registra movimentação
    $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) 
                  VALUES ($produto_id, $qtd, '$tipo', NOW())");

    echo json_encode(['sucesso'=>true]);

} elseif($acao == 'remover'){
    $nome = $conn->real_escape_string($data['nome']);
    
    // Busca o produto antes de remover
    $res = $conn->query("SELECT id, quantidade FROM produtos WHERE nome='$nome'");
    if($res->num_rows > 0){
        $produto = $res->fetch_assoc();
        $produto_id = $produto['id'];
        $qtdAtual = $produto['quantidade'];

        // Registra a remoção com a quantidade existente
        $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) 
                      VALUES ($produto_id, $qtdAtual, 'remocao', NOW())");

        // Remove da tabela de produtos
        $conn->query("DELETE FROM produtos WHERE id=$produto_id");
    }

    echo json_encode(['sucesso'=>true]);

} elseif($acao == 'listar'){
    $res = $conn->query("SELECT * FROM produtos");
    $produtos = [];
    while($row = $res->fetch_assoc()){
        $produtos[] = $row;
    }
    echo json_encode($produtos);

} elseif($acao == 'relatorio'){
    $inicio = $conn->real_escape_string($data['inicio']);
    $fim = $conn->real_escape_string($data['fim']);

    // Relatório com LEFT JOIN para pegar também produtos removidos
    $res = $conn->query("
        SELECT m.id, m.produto_id, COALESCE(p.nome, '[REMOVIDO]') as nome, 
               m.quantidade, m.tipo, m.data 
        FROM movimentacoes m
        LEFT JOIN produtos p ON m.produto_id = p.id
        WHERE m.data BETWEEN '$inicio 00:00:00' AND '$fim 23:59:59'
        ORDER BY m.data ASC
    ");
    $rel = [];
    while($row = $res->fetch_assoc()){
        $rel[] = $row;
    }
    echo json_encode($rel);
}

$conn->close();
?>
