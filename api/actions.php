<?php
header('Content-Type: application/json');

// Configuração do banco
$host = "192.168.15.100";
$user = "root";
$pass = "#Shakka01";
$db   = "estoque";

// Conecta
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(['erro' => 'Falha na conexão: '.$conn->connect_error]));
}

// Recebe os dados do JS
$input = json_decode(file_get_contents("php://input"), true);
$acao  = $input['acao'] ?? '';

if($acao == 'cadastrar'){
    $nome = $conn->real_escape_string($input['nome']);
    $qtd  = (int)$input['qtd'];
    $conn->query("INSERT INTO produtos(nome, quantidade) VALUES('$nome',$qtd)");
    $conn->query("INSERT INTO movimentacoes(nome, tipo, quantidade, data) VALUES('$nome','entrada',$qtd,NOW())");
    echo json_encode(['status'=>'ok']);

} elseif($acao == 'entrada'){
    $nome = $conn->real_escape_string($input['nome']);
    $qtd  = (int)$input['qtd'];
    $conn->query("UPDATE produtos SET quantidade = quantidade + $qtd WHERE nome='$nome'");
    $conn->query("INSERT INTO movimentacoes(nome, tipo, quantidade, data) VALUES('$nome','entrada',$qtd,NOW())");
    echo json_encode(['status'=>'ok']);

} elseif($acao == 'saida'){
    $nome = $conn->real_escape_string($input['nome']);
    $qtd  = (int)$input['qtd'];

    // Verifica se há quantidade suficiente
    $res = $conn->query("SELECT quantidade FROM produtos WHERE nome='$nome'");
    $row = $res->fetch_assoc();
    if($row['quantidade'] < $qtd){
        echo json_encode(['erro'=>'Quantidade insuficiente']);
        exit;
    }

    $conn->query("UPDATE produtos SET quantidade = quantidade - $qtd WHERE nome='$nome'");
    $conn->query("INSERT INTO movimentacoes(nome, tipo, quantidade, data) VALUES('$nome','saida',$qtd,NOW())");
    echo json_encode(['status'=>'ok']);

} elseif($acao == 'remover'){
    $nome = $conn->real_escape_string($input['nome']);
    $conn->query("DELETE FROM produtos WHERE nome='$nome'");
    echo json_encode(['status'=>'ok']);

} elseif($acao == 'listar'){
    $res = $conn->query("SELECT nome, quantidade FROM produtos");
    $produtos = [];
    while($row = $res->fetch_assoc()){
        $produtos[] = $row;
    }
    echo json_encode($produtos);

} elseif($acao == 'relatorio'){
    $inicio = $conn->real_escape_string($input['inicio']);
    $fim    = $conn->real_escape_string($input['fim']);

    $res = $conn->query("SELECT nome, tipo, quantidade, DATE_FORMAT(data,'%Y-%m-%d %H:%i:%s') as data 
                         FROM movimentacoes 
                         WHERE DATE(data) BETWEEN '$inicio' AND '$fim'
                         ORDER BY data ASC");

    $movs = [];
    while($row = $res->fetch_assoc()){
        $movs[] = $row;
    }
    echo json_encode($movs);
}

$conn->close();
