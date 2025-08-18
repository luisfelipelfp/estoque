<?php
header('Content-Type: application/json');

// ===== CONFIG DO BANCO (use as SUAS credenciais) =====
$host = "192.168.15.100";
$user = "root";
$pass = "#Shakka01";
$db   = "estoque";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha na conexão com o banco: '.$conn->connect_error]);
    exit;
}
$conn->set_charset('utf8mb4');

// Lê JSON do corpo (compatível com seu front-end)
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true) ?: [];

// Acão pode vir do JSON (preferido) ou fallback para POST/GET
$acao = $data['acao'] ?? ($_POST['acao'] ?? ($_GET['acao'] ?? ''));

// Helper para responder e encerrar
function respond($arr, $code = 200){
    http_response_code($code);
    echo json_encode($arr);
    exit;
}

switch ($acao) {

    case 'listar': {
        $sql = "SELECT id, nome, quantidade FROM produtos ORDER BY nome ASC";
        $res = $conn->query($sql);
        $out = [];
        while ($row = $res->fetch_assoc()) $out[] = $row;
        respond($out);
    }

    case 'cadastrar': {
        $nome = trim($conn->real_escape_string($data['nome'] ?? ''));
        $qtd  = isset($data['qtd']) ? (int)$data['qtd'] : 0;

        if ($nome === '') respond(['erro' => 'Informe o nome do produto.'], 400);
        if ($qtd < 0)      respond(['erro' => 'Quantidade inicial não pode ser negativa.'], 400);

        // verifica duplicidade
        $stmt = $conn->prepare("SELECT id FROM produtos WHERE nome = ?");
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) respond(['erro' => 'Produto já existe.'], 409);
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $qtd);
        if (!$stmt->execute()) respond(['erro' => 'Falha ao cadastrar produto.'], 500);
        $stmt->close();

        respond(['sucesso' => true]);
    }

    case 'entrada':
    case 'saida': {
        $nome = trim($conn->real_escape_string($data['nome'] ?? ''));
        $qtd  = (int)($data['qtd'] ?? 0);
        if ($nome === '' || $qtd <= 0) respond(['erro' => 'Informe nome e quantidade (>0).'], 400);

        // Busca produto
        $stmt = $conn->prepare("SELECT id, quantidade FROM produtos WHERE nome = ?");
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $res = $stmt->get_result();
        $prod = $res->fetch_assoc();
        $stmt->close();

        if (!$prod) respond(['erro' => 'Produto não encontrado.'], 404);

        $produto_id = (int)$prod['id'];
        $qtd_atual  = (int)$prod['quantidade'];

        // Controle de estoque para saída
        if ($acao === 'saida' && $qtd > $qtd_atual) {
            respond(['erro' => 'Quantidade em estoque insuficiente.'], 400);
        }

        // Transação: atualiza estoque + registra movimentação
        $conn->begin_transaction();
        try {
            if ($acao === 'entrada') {
                $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
            }
            $stmt->bind_param("ii", $qtd, $produto_id);
            if (!$stmt->execute()) throw new Exception('Falha ao atualizar estoque.');
            $stmt->close();

            $tipo = ($acao === 'entrada') ? 'entrada' : 'saida';
            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("isi", $produto_id, $tipo, $qtd);
            if (!$stmt->execute()) throw new Exception('Falha ao registrar movimentação.');
            $stmt->close();

            $conn->commit();
            respond(['sucesso' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            respond(['erro' => $e->getMessage()], 500);
        }
    }

    case 'remover': {
        $nome = trim($conn->real_escape_string($data['nome'] ?? ''));
        if ($nome === '') respond(['erro' => 'Informe o nome do produto.'], 400);

        $stmt = $conn->prepare("SELECT id FROM produtos WHERE nome = ?");
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $res = $stmt->get_result();
        $prod = $res->fetch_assoc();
        $stmt->close();

        if (!$prod) respond(['erro' => 'Produto não encontrado.'], 404);

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $prod['id']);
        if (!$stmt->execute()) respond(['erro' => 'Falha ao remover produto.'], 500);
        $stmt->close();

        // movimentações relacionadas são removidas por ON DELETE CASCADE
        respond(['sucesso' => true]);
    }

    case 'relatorio': {
        // vindo do JSON
        $inicio = trim($data['inicio'] ?? '');
        $fim    = trim($data['fim'] ?? '');

        if ($inicio === '' || $fim === '') respond(['erro' => 'Informe as datas de início e fim (YYYY-MM-DD).'], 400);

        // Monta range de datetime completo
        $ini = $inicio.' 00:00:00';
        $fi  = $fim.' 23:59:59';

        $stmt = $conn->prepare("
            SELECT m.id, p.nome, m.quantidade, m.tipo, m.data
            FROM movimentacoes m
            JOIN produtos p ON p.id = m.produto_id
            WHERE m.data BETWEEN ? AND ?
            ORDER BY m.data DESC, m.id DESC
        ");
        $stmt->bind_param("ss", $ini, $fi);
        $stmt->execute();
        $res = $stmt->get_result();

        $out = [];
        while ($row = $res->fetch_assoc()) $out[] = $row;
        $stmt->close();

        respond($out);
    }

    default:
        respond(['erro' => 'Ação inválida ou ausente.'], 400);
}
