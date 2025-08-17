<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configurações de conexão
$host = "192.168.15.100";
$user = "root";
$pass = "#Shakka01";
$db   = "estoque";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(["status" => "erro", "mensagem" => "Falha na conexão: " . $conn->connect_error]));
}

$acao = $_POST['acao'] ?? '';

switch($acao) {

    case 'listar_produtos':
        $result = $conn->query("SELECT * FROM produtos ORDER BY id ASC");
        $produtos = [];
        while($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode($produtos);
    break;

    case 'cadastrar_produto':
        $nome = $conn->real_escape_string($_POST['nome'] ?? '');
        $quantidade = intval($_POST['quantidade'] ?? 0);

        if(empty($nome)) {
            echo json_encode(["status"=>"erro","mensagem"=>"Nome do produto vazio"]);
            exit;
        }

        // Verifica duplicidade
        $check = $conn->query("SELECT id FROM produtos WHERE nome='$nome'");
        if($check->num_rows > 0){
            echo json_encode(["status"=>"erro","mensagem"=>"Produto já cadastrado"]);
            exit;
        }

        $conn->query("INSERT INTO produtos (nome, quantidade) VALUES ('$nome', $quantidade)");
        $id = $conn->insert_id;

        // Registra entrada
        $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) VALUES ($id, $quantidade, 'entrada', NOW())");

        echo json_encode(["status"=>"sucesso","mensagem"=>"Produto cadastrado"]);
    break;

    case 'entrada_saida':
        $produto_id = intval($_POST['produto_id'] ?? 0);
        $quantidade = intval($_POST['quantidade'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';

        if($quantidade <= 0 || ($tipo != 'entrada' && $tipo != 'saida')){
            echo json_encode(["status"=>"erro","mensagem"=>"Dados inválidos"]);
            exit;
        }

        // Atualiza quantidade
        if($tipo == 'entrada'){
            $conn->query("UPDATE produtos SET quantidade = quantidade + $quantidade WHERE id = $produto_id");
        } else {
            // Verifica estoque suficiente
            $result = $conn->query("SELECT quantidade FROM produtos WHERE id=$produto_id");
            $row = $result->fetch_assoc();
            if($row['quantidade'] < $quantidade){
                echo json_encode(["status"=>"erro","mensagem"=>"Estoque insuficiente"]);
                exit;
            }
            $conn->query("UPDATE produtos SET quantidade = quantidade - $quantidade WHERE id = $produto_id");
        }

        // Registra movimentação
        $conn->query("INSERT INTO movimentacoes (produto_id, quantidade, tipo, data) VALUES ($produto_id, $quantidade, '$tipo', NOW())");

        echo json_encode(["status"=>"sucesso","mensagem"=>"Movimentação registrada"]);
    break;

    case 'remover_produto':
        $produto_id = intval($_POST['produto_id'] ?? 0);
        $conn->query("DELETE FROM produtos WHERE id=$produto_id");
        echo json_encode(["status"=>"sucesso","mensagem"=>"Produto removido"]);
    break;

    case 'relatorio':
        $data_inicio = $_POST['data_inicio'] ?? '';
        $data_fim = $_POST['data_fim'] ?? '';

        if(empty($data_inicio) || empty($data_fim)){
            echo json_encode(["status"=>"erro","mensagem"=>"Datas inválidas"]);
            exit;
        }

        $sql = "SELECT m.*, p.nome FROM movimentacoes m 
                JOIN produtos p ON m.produto_id = p.id
                WHERE DATE(m.data) BETWEEN '$data_inicio' AND '$data_fim'
                ORDER BY m.data ASC";

        $result = $conn->query($sql);
        $movs = [];
        while($row = $result->fetch_assoc()){
            $movs[] = $row;
        }

        echo json_encode($movs);
    break;

    default:
        echo json_encode(["status"=>"erro","mensagem"=>"Ação inválida"]);
}
$conn->close();
