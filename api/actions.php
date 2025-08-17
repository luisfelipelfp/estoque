<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configurações do banco
$host = '192.168.15.100';
$user = 'root';
$pass = '#Shakka01';
$db   = 'estoque';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode(['erro' => true, 'mensagem' => 'Erro de conexão: '.$conn->connect_error]);
    exit;
}

// Recebe os dados JSON do JS
$data = json_decode(file_get_contents('php://input'), true);
$acao = $data['acao'] ?? '';

switch($acao){
    case 'cadastrar':
        $nome = $conn->real_escape_string($data['nome']);
        $qtd = (int)$data['quantidade'];

        // Verifica duplicidade
        $res = $conn->query("SELECT * FROM produtos WHERE nome='$nome'");
        if($res->num_rows > 0){
            echo json_encode(['erro'=>true,'mensagem'=>'Produto já cadastrado']);
            exit;
        }

        $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome',$qtd)");
        echo json_encode(['erro'=>false,'mensagem'=>'Produto cadastrado com sucesso']);
    break;

    case 'entrada':
        $nome = $conn->real_escape_string($data['nome']);
        $qtd = (int)$data['quantidade'];

        $conn->query("UPDATE produtos SET quantidade = quantidade + $qtd WHERE nome='$nome'");
        $conn->query("INSERT INTO movimentacoes (produto, tipo, quantidade, data) VALUES ('$nome','entrada',$qtd,NOW())");
        echo json_encode(['erro'=>false,'mensagem'=>'Entrada registrada']);
    break;

    case 'saida':
        $nome = $conn->real_escape_string($data['nome']);
        $qtd = (int)$data['quantidade'];

        $res = $conn->query("SELECT quantidade FROM produtos WHERE nome='$nome'");
        $row = $res->fetch_assoc();
        if($row['quantidade'] < $qtd){
            echo json_encode(['erro'=>true,'mensagem'=>'Quantidade insuficiente']);
            exit;
        }

        $conn->query("UPDATE produtos SET quantidade = quantidade - $qtd WHERE nome='$nome'");
        $conn->query("INSERT INTO movimentacoes (produto, tipo, quantidade, data) VALUES ('$nome','saida',$qtd,NOW())");
        echo json_encode(['erro'=>false,'mensagem'=>'Saída registrada']);
    break;

    case 'remover':
        $nome = $conn->real_escape_string($data['nome']);
        $conn->query("DELETE FROM produtos WHERE nome='$nome'");
        echo json_encode(['erro'=>false,'mensagem'=>'Produto removido']);
    break;

    case 'listar':
        $res = $conn->query("SELECT * FROM produtos ORDER BY id");
        $produtos = [];
        while($row = $res->fetch_assoc()){
            $produtos[] = $row;
        }
        echo json_encode($produtos);
    break;

    case 'relatorio':
        $inicio = $conn->real_escape_string($data['inicio']);
        $fim = $conn->real_escape_string($data['fim']);

        $res = $conn->query("SELECT * FROM movimentacoes WHERE DATE(data) BETWEEN '$inicio' AND '$fim' ORDER BY data");
        $movs = [];
        while($row = $res->fetch_assoc()){
            $movs[] = $row;
        }
        echo json_encode($movs);
    break;

    default:
        echo json_encode(['erro'=>true,'mensagem'=>'Ação inválida']);
}
?>
