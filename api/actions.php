<?php
header('Content-Type: application/json; charset=utf-8');

// Configurações do banco
$host = "192.168.15.100"; // IP do seu MariaDB
$db   = "estoque";     // Nome do banco
$user = "root";
$pass = "#Shakka01";
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Exception $e) {
    echo json_encode(['error' => 'Erro na conexão: '.$e->getMessage()]);
    exit;
}

// Ação recebida
$acao = $_GET['acao'] ?? '';

switch($acao){

    // LISTAR PRODUTOS
    case 'listar_produtos':
        $stmt = $pdo->query("SELECT id, nome, quantidade FROM produtos ORDER BY id DESC");
        echo json_encode($stmt->fetchAll());
        break;

    // CADASTRAR PRODUTO
    case 'cadastrar_produto':
        $nome = trim($_GET['nome'] ?? '');
        if(!$nome) { echo json_encode(['error'=>'Nome obrigatório']); exit; }

        // Verifica duplicidade
        $stmt = $pdo->prepare("SELECT id FROM produtos WHERE nome=?");
        $stmt->execute([$nome]);
        if($stmt->fetch()) {
            echo json_encode(['error'=>'Produto já existe']); exit;
        }

        $stmt = $pdo->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?,0)");
        $stmt->execute([$nome]);
        echo json_encode(['success'=>true]);
        break;

    // EXCLUIR PRODUTO
    case 'excluir_produto':
        $id = intval($_GET['id'] ?? 0);
        if($id>0){
            $stmt = $pdo->prepare("DELETE FROM produtos WHERE id=?");
            $stmt->execute([$id]);
        }
        echo json_encode(['success'=>true]);
        break;

    // REGISTRAR MOVIMENTAÇÃO
    case 'movimentacao':
        $produto_id = intval($_GET['produto_id'] ?? 0);
        $tipo = $_GET['tipo'] ?? '';
        $quantidade = intval($_GET['quantidade'] ?? 0);
        if($produto_id<=0 || !in_array($tipo,['entrada','saida']) || $quantidade<=0){
            echo json_encode(['error'=>'Dados inválidos']); exit;
        }

        // Atualiza estoque
        if($tipo=='entrada'){
            $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id=?");
            $stmt->execute([$quantidade,$produto_id]);
        } else {
            // Verifica estoque suficiente
            $stmt = $pdo->prepare("SELECT quantidade FROM produtos WHERE id=?");
            $stmt->execute([$produto_id]);
            $qtdAtual = $stmt->fetchColumn();
            if($qtdAtual < $quantidade) {
                echo json_encode(['error'=>'Estoque insuficiente']); exit;
            }
            $stmt = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id=?");
            $stmt->execute([$quantidade,$produto_id]);
        }

        // Registra movimentação
        $stmt = $pdo->prepare("INSERT INTO movimentacoes (produto_id,tipo,quantidade,data) VALUES (?,?,?,NOW())");
        $stmt->execute([$produto_id,$tipo,$quantidade]);
        echo json_encode(['success'=>true]);
        break;

    // RELATÓRIO POR INTERVALO DE DATAS
    case 'relatorio_intervalo':
        $data_inicio = $_GET['data_inicio'] ?? '';
        $data_fim    = $_GET['data_fim'] ?? '';
        $produto_id  = intval($_GET['produto_id'] ?? 0);

        if(!$data_inicio || !$data_fim){
            echo json_encode([]);
            exit;
        }

        $sql = "SELECT m.id, p.nome, m.tipo, m.quantidade, m.data 
                FROM movimentacoes m
                INNER JOIN produtos p ON p.id = m.produto_id
                WHERE DATE(m.data) BETWEEN ? AND ?";
        $params = [$data_inicio, $data_fim];

        if($produto_id>0){
            $sql .= " AND m.produto_id = ?";
            $params[] = $produto_id;
        }

        $sql .= " ORDER BY m.data ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        break;

    default:
        echo json_encode(['error'=>'Ação inválida']);
        break;
}
