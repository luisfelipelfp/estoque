<?php
header('Content-Type: application/json');

$host = "192.168.15.100";
$user = "root";
$pass = "#Shakka01";
$db = "estoque";

$conn = new mysqli($host,$user,$pass,$db);
if($conn->connect_error){ die(json_encode(['erro'=>'Falha na conexão'])); }

$data = json_decode(file_get_contents("php://input"), true);
$acao = $data['acao'] ?? '';

if($acao == 'cadastrar'){
    $nome = $conn->real_escape_string($data['nome']);
    $qtd = intval($data['qtd']);
    $verifica = $conn->query("SELECT * FROM produtos WHERE nome='$nome'");
    if($verifica->num_rows > 0){
        echo json_encode(['erro'=>'Produto já existe']);
    } else {
        $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome',$qtd)");
        echo json_encode(['sucesso'=>true]);
    }
} elseif($acao == 'entrada'){
    $nome = $conn->real_escape_string($data['nome']);
    $qtd = intval($data['qtd']);
    $conn->query("UPDATE produtos SET quantidade = quantidade + $qtd WHERE nome='$nome'");
    $conn->query("INSERT INTO movimentacoes (nome, quantidade, tipo, data) VALUES ('$nome',$qtd,'entrada',NOW())");
    echo json_encode(['sucesso'=>true]);
} elseif($acao == 'saida'){
    $nome = $conn->real_escape_string($data['nome']);
    $qtd = intval($data['qtd']);
    $conn->query("UPDATE produtos SET quantidade = quantidade - $qtd WHERE nome='$nome'");
    $conn->query("INSERT INTO movimentacoes (nome, quantidade, tipo, data) VALUES ('$nome',$qtd,'saida',NOW())");
    echo json_encode(['sucesso'=>true]);
} elseif($acao == 'remover'){
    $nome = $conn->real_escape_string($data['nome']);
    $conn->query("DELETE FROM produtos WHERE nome='$nome'");
    echo json_encode(['sucesso'=>true]);
} elseif($acao == 'listar'){
    $res = $conn->query("SELECT * FROM produtos");
    $produtos = [];
    while($row=$res->fetch_assoc()){
        $produtos[] = $row;
    }
    echo json_encode($produtos);
} elseif($acao == 'relatorio'){
    $inicio = $conn->real_escape_string($data['inicio']);
    $fim = $conn->real_escape_string($data['fim']);
    $res = $conn->query("SELECT * FROM movimentacoes WHERE data BETWEEN '$inicio 00:00:00' AND '$fim 23:59:59'");
    $rel = [];
    while($row=$res->fetch_assoc()){
        $rel[] = $row;
    }
    echo json_encode($rel);
}

$conn->close();
?>
