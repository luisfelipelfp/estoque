<?php
session_start();
header("Content-Type: application/json");
require_once "db.php";

/** Util: saída JSON e fim */
function json_out($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lê input de qualquer forma: JSON, POST form, GET */
$raw   = file_get_contents("php://input");
$body  = json_decode($raw, true);
if (!is_array($body)) { $body = []; }
$params = array_merge($_GET, $_POST, $body);

/** Captura ação */
$action = $params['action'] ?? $params['acao'] ?? '';
$action = trim(strtolower($action));

/** Normaliza sinônimos */
$map = [
    'listar'              => 'listarProdutos',
    'listarprodutos'      => 'listarProdutos',
    'listarmovimentacoes' => 'listarMovimentacoes',

    'cadastrar'           => 'adicionarProduto',
    'adicionar'           => 'adicionarProduto',
    'adicionarproduto'    => 'adicionarProduto',

    'entrada'             => 'entradaProduto',
    'entradaproduto'      => 'entradaProduto',

    'saida'               => 'saidaProduto',
    'saidaproduto'        => 'saidaProduto',

    'remover'             => 'removerProduto',
    'removerproduto'      => 'removerProduto',

    'relatorio'           => 'relatorioMovimentacoes',
    'testeconexao'        => 'testeConexao',
];

/** Se não tiver ação ou não for conhecida */
if ($action === '' || !isset($map[$action])) {
    json_out([
        'sucesso' => false,
        'erro'    => 'Ação inválida',
        'recebido' => $action,
        'acoesAceitas' => array_keys($map)
    ], 400);
}

$action = $map[$action];

/** Helpers */
function get_produto_nome(mysqli $conn, int $id): ?string {
    $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($nome);
    $ok = $stmt->fetch();
    $stmt->close();
    return $ok ? $nome : null;
}

function get_usuario(): ?string {
    return $_SESSION['usuario'] ?? 'sistema';
}

/** Rotas */
switch ($action) {

    case 'testeConexao':
        json_out(['sucesso' => true, 'mensagem' => 'Conexão com banco funcionando!']);

    case 'listarProdutos': {
        $res = $conn->query("SELECT id, nome, quantidade FROM produtos ORDER BY id ASC");
        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = $row; }
        json_out(['sucesso' => true, 'dados' => $out]);
    }

    case 'adicionarProduto': {
        $nome = trim((string)($params['nome'] ?? ''));
        $quantidade = isset($params['quantidade']) ? (int)$params['quantidade'] : 0;

        if ($nome === '') {
            json_out(['sucesso' => false, 'erro' => 'Nome é obrigatório'], 400);
        }

        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $quantidade);
        if (!$stmt->execute()) {
            $erro = $conn->errno === 1062 ? 'Produto já existe' : ('Erro ao inserir: ' . $conn->error);
            $stmt->close();
            json_out(['sucesso' => false, 'erro' => $erro], 400);
        }

        $produto_id = $stmt->insert_id;
        $stmt->close();

        if ($quantidade > 0) {
            $usuario = get_usuario();
            $stmt = $conn->prepare("
                INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data, usuario)
                VALUES (?, ?, ?, 'entrada', NOW(), ?)
            ");
            $stmt->bind_param("isis", $produto_id, $nome, $quantidade, $usuario);
            $stmt->execute();
            $stmt->close();
        }

        json_out(['sucesso' => true, 'id' => $produto_id]);
    }

    case 'entradaProduto': {
        $id  = (int)($params['id'] ?? 0);
        $qtd = (int)($params['quantidade'] ?? 0);

        if ($id <= 0 || $qtd <= 0) {
            json_out(['sucesso' => false, 'erro' => 'ID e quantidade inválidos'], 400);
        }

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        $stmt->bind_param("ii", $qtd, $id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            json_out(['sucesso' => false, 'erro' => 'Produto não encontrado'], 404);
        }

        $stmt->close();

        $nome = get_produto_nome($conn, $id) ?? '';
        $usuario = get_usuario();

        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data, usuario)
            VALUES (?, ?, ?, 'entrada', NOW(), ?)
        ");
        $stmt->bind_param("isis", $id, $nome, $qtd, $usuario);
        $stmt->execute();
        $stmt->close();

        json_out(['sucesso' => true]);
    }

    case 'saidaProduto': {
        $id  = (int)($params['id'] ?? 0);
        $qtd = (int)($params['quantidade'] ?? 0);

        if ($id <= 0 || $qtd <= 0) {
            json_out(['sucesso' => false, 'erro' => 'ID e quantidade inválidos'], 400);
        }

        $stmt = $conn->prepare("SELECT quantidade FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($estoque);

        if (!$stmt->fetch()) {
            json_out(['sucesso' => false, 'erro' => 'Produto não encontrado'], 404);
        }

        $stmt->close();

        if ($estoque - $qtd < 0) {
            json_out(['sucesso' => false, 'erro' => 'Estoque insuficiente'], 400);
        }

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
        $stmt->bind_param("ii", $qtd, $id);
        $stmt->execute();
        $stmt->close();

        $nome = get_produto_nome($conn, $id) ?? '';
        $usuario = get_usuario();

        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data, usuario)
            VALUES (?, ?, ?, 'saida', NOW(), ?)
        ");
        $stmt->bind_param("isis", $id, $nome, $qtd, $usuario);
        $stmt->execute();
        $stmt->close();

        json_out(['sucesso' => true]);
    }

    case 'removerProduto': {
        $id = (int)($params['id'] ?? 0);

        if ($id <= 0) {
            json_out(['sucesso' => false, 'erro' => 'Informe id válido'], 400);
        }

        $nome = get_produto_nome($conn, $id) ?? '';
        $usuario = get_usuario();

        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data, usuario)
            VALUES (?, ?, 0, 'removido', NOW(), ?)
        ");
        $stmt->bind_param("iss", $id, $nome, $usuario);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        json_out(['sucesso' => true]);
    }

    case 'listarMovimentacoes': {
        $where = [];
        $binds = [];
        $types = "";

        if (!empty($params['tipo'])) {
            $where[] = "m.tipo = ?";
            $binds[] = $params['tipo'];
            $types  .= "s";
        }

        if (!empty($params['inicio']) && !empty($params['fim'])) {
            $where[] = "m.data BETWEEN ? AND ?";
            $binds[] = $params['inicio'] . " 00:00:00";
            $binds[] = $params['fim'] . " 23:59:59";
            $types  .= "ss";
        }

        $sql = "
            SELECT m.id, COALESCE(m.produto_nome, p.nome) AS produto_nome, 
                   m.tipo, m.quantidade, m.data, m.usuario
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
        ";
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY m.data DESC, m.id DESC";

        $limit  = isset($params['limit'])  ? (int)$params['limit']  : 50;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $sql .= " LIMIT ? OFFSET ?";
        $types .= "ii";
        $binds[] = $limit;
        $binds[] = $offset;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $res = $stmt->get_result();

        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = $row; }
        $stmt->close();

        json_out(['sucesso' => true, 'dados' => $out]);
    }

    case 'relatorioMovimentacoes': {
        $sql = "
            SELECT 
                SUM(CASE WHEN tipo = 'entrada' THEN quantidade ELSE 0 END) AS totalEntradas,
                SUM(CASE WHEN tipo = 'saida'   THEN quantidade ELSE 0 END) AS totalSaidas,
                SUM(CASE WHEN tipo = 'entrada' THEN quantidade 
                         WHEN tipo = 'saida'   THEN -quantidade ELSE 0 END) AS saldo
            FROM movimentacoes
        ";
        $res = $conn->query($sql);
        $row = $res->fetch_assoc();
        json_out(['sucesso' => true, 'dados' => $row]);
    }

    default:
        json_out(['sucesso' => false, 'erro' => 'Rota não tratada.']);
}
