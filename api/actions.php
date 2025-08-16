<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// Configurações do banco
$host = '192.168.15.100'; // IP do servidor MariaDB
$user = 'SEU_USUARIO';
$pass = 'SUA_SENHA';
$db   = 'estoque';

// Conexão
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de conexão: ' . $conn->connect_error]);
    exit;
}

// Função auxiliar para prevenir SQL Injection
function limpar($conn, $valor) {
    return $conn->real_escape_string($valor);
}

// Pega ação
$acao = $_POST['acao'] ?? '';

switch($acao) {

    case 'cadastrar_produto':
        $nome = limpar($conn, trim($_POST['nome'] ?? ''));
        $quantidade = intval($_POST['quantidade'] ?? 0);

        if (!$nome || $quantidade < 0) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Campos inválidos']);
            exit;
        }

        // Verifica duplicidade
        $check = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
        if ($check->num_rows > 0) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Produto já cadastrado']);
            exit;
        }

        $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome', $quantidade)");
        $id = $conn->insert_id;

        // Registra entrada inicial como movimentação
        $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES ($id,'entrada',$quantidade,NOW())");

        echo json_encode(['sucesso' => true, 'mensagem' => 'Produto cadastrado com sucesso']);
        break;

    case 'entrada_produto':
        $nome = limpar($conn, trim($_POST['nome'] ?? ''));
        $quantidade = intval($_POST['quantidade'] ?? 0);

        if (!$nome || $quantidade <= 0) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Campos inválidos']);
            exit;
        }

        $res = $conn->query("SELECT id, quantidade FROM produtos WHERE nome='$nome'");
        if ($res->num_rows == 0) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Produto não encontrado']);
            exit;
        }
        $prod = $res->fetch_assoc();
        $novo_total = $prod['quantidade'] + $quantidade;

        $conn->query("UPDATE produtos SET quantidade=$novo_total WHERE id=".$prod['id']);
        $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (".$prod['id'].",'entrada',$quantidade,NOW())");

        echo json_encode(['sucesso' => true, 'mensagem' => 'Entrada registrada']);
        break;

    case 'saida_produto':
        $nome = limpar($conn, trim($_POST['nome'] ?? ''));
        $quantidade = intval($_POST['quantidade'] ?? 0);

        if (!$nome || $quantidade <= 0) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Campos inválidos']);
            exit;
        }

        $res = $conn->query("SELECT id, quantidade FROM produtos WHERE nome='$nome'");
        if ($res->num_rows == 0) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Produto não encontrado']);
            exit;
        }
        $prod = $res->fetch_assoc();

        if ($quantidade > $prod['quantidade']) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Quantidade insuficiente em estoque']);
            exit;
        }

        $novo_total = $prod['quantidade'] - $quantidade;

        $conn->query("UPDATE produtos SET quantidade=$novo_total WHERE id=".$prod['id']);
        $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (".$prod['id'].",'saida',$quantidade,NOW())");

        echo json_encode(['sucesso' => true, 'mensagem' => 'Saída registrada']);
        break;

    case 'listar_produtos':
        $produtos = [];
        $res = $conn->query("SELECT id, nome, quantidade FROM produtos ORDER BY id ASC");
        while($row = $res->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode(['sucesso' => true, 'produtos' => $produtos]);
        break;

    case 'relatorio':
        $dataInicio = $_POST['dataInicio'] ?? '';
        $dataFim = $_POST['dataFim'] ?? '';

        if (!$dataInicio || !$dataFim) {
            echo json_encode(['sucesso' => false, 'movimentacoes' => [], 'mensagem' => 'Datas inválidas']);
            exit;
        }

        $dataInicio = limpar($conn, $dataInicio);
        $dataFim = limpar($conn, $dataFim);

        $movs = [];
        $res = $conn->query("
            SELECT m.tipo, m.quantidade, m.data, p.nome 
            FROM movimentacoes m
            JOIN produtos p ON m.produto_id = p.id
            WHERE DATE(m.data) BETWEEN '$dataInicio' AND '$dataFim'
            ORDER BY m.data ASC
        ");

        while($row = $res->fetch_assoc()) {
            $movs[] = $row;
        }

        echo json_encode(['sucesso' => true, 'movimentacoes' => $movs]);
        break;

    default:
        echo json_encode(['sucesso' => false, 'mensagem' => 'Ação inválida']);
        break;
}

$conn->close();
?>