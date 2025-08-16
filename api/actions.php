<?php
header('Content-Type: application/json');

// ==========================
// Configuração do banco
// ==========================
$host = "192.168.15.100";   // IP do servidor MariaDB
$user = "root";      // seu usuário do MariaDB
$pass = "#Shakka01";        // sua senha
$db   = "estoque";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(['erro' => "Falha na conexão: " . $conn->connect_error]));
}

// ==========================
// Função para ler parâmetro POST ou GET
// ==========================
function getParam($name) {
    if(isset($_POST[$name])) return $_POST[$name];
    if(isset($_GET[$name])) return $_GET[$name];
    return null;
}

// ==========================
// Ação
// ==========================
$acao = getParam('acao');

switch($acao) {

    // ======================
    case 'listar_produtos':
        $res = $conn->query("SELECT * FROM produtos ORDER BY id DESC");
        $produtos = [];
        while($row = $res->fetch_assoc()){
            $produtos[] = $row;
        }
        echo json_encode($produtos);
        break;

    // ======================
    case 'cadastrar_produto':
        $nome = $conn->real_escape_string(getParam('nome'));
        $qtd = (int)getParam('quantidade');

        // Verifica se produto já existe
        $check = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
        if($check->num_rows > 0){
            echo json_encode(['mensagem' => 'Produto já cadastrado!']);
            exit;
        }

        $conn->query("INSERT INTO produtos(nome,quantidade) VALUES('$nome',$qtd)");
        echo json_encode(['mensagem' => 'Produto cadastrado com sucesso!']);
        break;

    // ======================
    case 'excluir_produto':
        $id = (int)getParam('id');
        $conn->query("DELETE FROM produtos WHERE id=$id");
        echo json_encode(['mensagem' => 'Produto excluído!']);
        break;

    // ======================
    case 'movimentacao':
        $produto_id = (int)getParam('produto_id');
        $tipo = getParam('tipo'); // 'entrada' ou 'saida'
        $qtd = (int)getParam('quantidade');

        // Atualiza estoque
        if($tipo == 'entrada'){
            $conn->query("UPDATE produtos SET quantidade = quantidade + $qtd WHERE id=$produto_id");
        } elseif($tipo == 'saida'){
            $conn->query("UPDATE produtos SET quantidade = quantidade - $qtd WHERE id=$produto_id");
        } else {
            echo json_encode(['mensagem'=>'Tipo inválido']);
            exit;
        }

        // Registra movimentação
        $conn->query("INSERT INTO movimentacoes(produto_id,tipo,quantidade) VALUES($produto_id,'$tipo',$qtd)");

        echo json_encode(['mensagem'=>"Movimentação registrada ($tipo)!"]);
        break;

    // ======================
    case 'relatorio_intervalo':
        $inicio = getParam('inicio');
        $fim = getParam('fim');

        $res = $conn->query("
            SELECT m.*, p.nome 
            FROM movimentacoes m
            JOIN produtos p ON m.produto_id = p.id
            WHERE DATE(m.data) BETWEEN '$inicio' AND '$fim'
            ORDER BY m.data DESC
        ");

        $movs = [];
        while($row = $res->fetch_assoc()){
            $movs[] = $row;
        }
        echo json_encode($movs);
        break;

    // ======================
    default:
        echo json_encode(['mensagem'=>'Ação inválida']);
        break;
}

$conn->close();
