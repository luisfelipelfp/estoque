<?php
header('Content-Type: application/json');

$host = "192.168.15.100";
$user = "root";
$pass = "#Shakka01";
$db   = "estoque";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha na conexão: '.$conn->connect_error]);
    exit;
}
$conn->set_charset('utf8mb4');

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true) ?: [];
$acao = $data['acao'] ?? '';

function respond($arr, $code = 200){
    http_response_code($code);
    echo json_encode($arr);
    exit;
}

if ($acao === 'cadastrar') {
    $nome = trim($data['nome'] ?? '');
    $qtd  = isset($data['qtd']) ? (int)$data['qtd'] : 0;
    if ($nome === '') respond(['erro' => 'Informe o nome do produto.'], 400);
    if ($qtd < 0)     respond(['erro' => 'Quantidade inicial inválida.'], 400);

    // Verifica duplicidade
    $stmt = $conn->prepare("SELECT id FROM produtos WHERE nome = ?");
    $stmt->bind_param("s", $nome);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) respond(['erro' => 'Produto já existe.'], 409);
    $stmt->close();

    $conn->begin_transaction();
    try {
        // Insere produto
        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $qtd);
        if (!$stmt->execute()) throw new Exception($conn->error);
        $produto_id = $stmt->insert_id;
        $stmt->close();

        // Registra movimentação inicial como ENTRADA (se > 0)
        if ($qtd > 0) {
            $tipo = 'entrada';
            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("isi", $produto_id, $tipo, $qtd);
            if (!$stmt->execute()) throw new Exception($conn->error);
            $stmt->close();
        }

        $conn->commit();
        respond(['sucesso' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        respond(['erro' => 'Falha ao cadastrar: '.$e->getMessage()], 500);
    }

} elseif ($acao === 'entrada' || $acao === 'saida') {
    $nome = trim($data['nome'] ?? '');
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

    if ($acao === 'saida' && $qtd > $qtd_atual) {
        respond(['erro' => 'Estoque insuficiente.'], 400);
    }

    $conn->begin_transaction();
    try {
        if ($acao === 'entrada') {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
        }
        $stmt->bind_param("ii", $qtd, $produto_id);
        if (!$stmt->execute()) throw new Exception($conn->error);
        $stmt->close();

        $tipo = $acao; // 'entrada' ou 'saida' (ENUM válido)
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("isi", $produto_id, $tipo, $qtd);
        if (!$stmt->execute()) throw new Exception($conn->error);
        $stmt->close();

        $conn->commit();
        respond(['sucesso' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        respond(['erro' => 'Falha ao registrar movimentação: '.$e->getMessage()], 500);
    }

} elseif ($acao === 'remover') {
    $nome = trim($data['nome'] ?? '');
    if ($nome === '') respond(['erro' => 'Informe o nome do produto.'], 400);

    // Busca produto
    $stmt = $conn->prepare("SELECT id, quantidade FROM produtos WHERE nome = ? LIMIT 1");
    $stmt->bind_param("s", $nome);
    $stmt->execute();
    $res = $stmt->get_result();
    $prod = $res->fetch_assoc();
    $stmt->close();
    if (!$prod) respond(['erro' => 'Produto não encontrado.'], 404);

    $produto_id = (int)$prod['id'];
    $qtdAtual   = (int)$prod['quantidade'];

    // Remoção = registrar SAÍDA com todo o estoque (ENUM válido) + excluir produto
    $conn->begin_transaction();
    try {
        if ($qtdAtual > 0) {
            $tipo = 'saida';
            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("isi", $produto_id, $tipo, $qtdAtual);
            if (!$stmt->execute()) throw new Exception($conn->error);
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $produto_id);
        if (!$stmt->execute()) throw new Exception($conn->error);
        $stmt->close();

        $conn->commit();
        respond(['sucesso' => true, 'mensagem' => 'Produto removido e movimentação registrada']);
    } catch (Exception $e) {
        $conn->rollback();
        respond(['erro' => 'Falha ao remover: '.$e->getMessage()], 500);
    }

} elseif ($acao === 'listar') {
    $res = $conn->query("SELECT id, nome, quantidade FROM produtos ORDER BY nome ASC");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    respond($out);

} elseif ($acao === 'relatorio') {
    $inicio = trim($data['inicio'] ?? '');
    $fim    = trim($data['fim'] ?? '');
    if ($inicio === '' || $fim === '') respond(['erro' => 'Informe datas início e fim (YYYY-MM-DD).'], 400);

    $ini = $inicio.' 00:00:00';
    $fi  = $fim.' 23:59:59';

    // LEFT JOIN para incluir produtos já removidos
    $sql = "
        SELECT m.id, m.produto_id,
               COALESCE(p.nome, '[REMOVIDO]') AS nome,
               m.tipo, m.quantidade, m.data
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        WHERE m.data BETWEEN ? AND ?
        ORDER BY m.data ASC, m.id ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $ini, $fi);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    respond($rows);

} else {
    respond(['erro' => 'Ação inválida.'], 400);
}
