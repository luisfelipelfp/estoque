<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuração do banco
$host = '192.168.15.100';
$user = 'root';
$pass = '#Shakka01';
$db   = 'estoque';

// Conecta ao MariaDB
$conn = new mysqli($host, $user, $pass, $db);
if($conn->connect_error) {
    die(json_encode(['erro'=>'Falha na conexão: '.$conn->connect_error]));
}

// Lê JSON enviado pelo JS
$input = json_decode(file_get_contents('php://input'), true);
$acao = $input['acao'] ?? '';

switch($acao) {

    // Listar produtos
    case 'listar':
        $result = $conn->query("SELECT * FROM produtos");
        $produtos = [];
        while($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode($produtos);
        break;

    // Cadastrar produto
    case 'cadastrar':
        $nome = $conn->real_escape_string($input['nome'] ?? '');
        $qtd = intval($input['quantidade'] ?? 0);
        if(!$nome || $qtd < 0) { echo json_encode(['erro'=>'Dados inválidos']); exit; }

        // Checa duplicidade
        $check = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
        if($check->num_rows > 0) { echo json_encode(['erro'=>'Produto já existe']); exit; }

        $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome', $qtd)");
        echo json_encode(['sucesso'=>true]);
        break;

    // Entrada de produto
    case 'entrada':
        $nome = $conn->real_escape_string($input['nome'] ?? '');
        $qtd = intval($input['quantidade'] ?? 0);
        if(!$nome || $qtd <= 0) { echo json_encode(['erro'=>'Dados inválidos']); exit; }

        $conn->query("UPDATE produtos SET quantidade = quantidade + $qtd WHERE nome='$nome'");
        $conn->query("INSERT INTO movimentacoes (produto_nome, tipo, quantidade, data) VALUES ('$nome','entrada',$qtd,NOW())");
        echo json_encode(['sucesso'=>true]);
        break;

    // Saída de produto
    case 'saida':
        $nome = $conn->real_escape_string($input['nome'] ?? '');
        $qtd = intval($input['quantidade'] ?? 0);
        if(!$nome || $qtd <= 0) { echo json_encode(['erro'=>'Dados inválidos']); exit; }

        $conn->query("UPDATE produtos SET quantidade = quantidade - $qtd WHERE nome='$nome'");
        $conn->query("INSERT INTO movimentacoes (produto_nome, tipo, quantidade, data) VALUES ('$nome','saida',$qtd,NOW())");
        echo json_encode(['sucesso'=>true]);
        break;

    // Remover produto
    case 'remover':
        $nome = $conn->real_escape_string($input['nome'] ?? '');
        if(!$nome) { echo json_encode(['erro'=>'Informe o nome']); exit; }

        $conn->query("DELETE FROM produtos WHERE nome='$nome'");
        echo json_encode(['sucesso'=>true]);
        break;

    // Relatório por data
    case 'relatorio':
        $inicio = $conn->real_escape_string($input['inicio'] ?? '');
        $fim = $conn->real_escape_string($input['fim'] ?? '');
        if(!$inicio || !$fim) { echo json_encode([]); exit; }

        $res = $conn->query("SELECT * FROM movimentacoes WHERE DATE(data) BETWEEN '$inicio' AND '$fim'");
        $movs = [];
        while($row = $res->fetch_assoc()) { $movs[] = $row; }
        echo json_encode($movs);
        break;

    default:
        echo json_encode(['erro'=>'Ação inválida']);
        break;
}

$conn->close();
