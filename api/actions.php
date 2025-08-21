<?php
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

/** Captura ação por 'action' ou 'acao' */
$action = $params['action'] ?? $params['acao'] ?? '';

/** Normaliza sinônimos de ação */
$map = [
    'listar'              => 'listarProdutos',
    'listarProdutos'      => 'listarProdutos',
    'listarMovimentacoes' => 'listarMovimentacoes',

    'cadastrar'           => 'adicionarProduto',
    'adicionar'           => 'adicionarProduto',
    'adicionarProduto'    => 'adicionarProduto',

    'entrada'             => 'entradaProduto',
    'entradaProduto'      => 'entradaProduto',

    'saida'               => 'saidaProduto',
    'saidaProduto'        => 'saidaProduto',

    'remover'             => 'removerProduto',
    'removerProduto'      => 'removerProduto',

    'relatorio'           => 'relatorio',
    'testeConexao'        => 'testeConexao',
];

if (!isset($map[$action])) {
    json_out(['erro' => 'Ação inválida', 'recebido' => $action], 400);
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

/** Rotas */
switch ($action) {

    case 'testeConexao':
        json_out(['status' => 'ok', 'mensagem' => 'Conexão com banco funcionando!']);

    case 'listarProdutos': {
        $res = $conn->query("SELECT id, nome, quantidade FROM produtos ORDER BY id ASC");
        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = $row; }
        json_out($out);
    }

    case 'adicionarProduto': {
        $nome = trim((string)($params['nome'] ?? ''));
        $quantidade = isset($params['quantidade']) ? (int)$params['quantidade'] : 0;
        if ($nome === '') {
            json_out(['erro' => 'Nome é obrigatório'], 400);
        }

        // Evita duplicado por UNIQUE(nome)
        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $quantidade);
        if (!$stmt->execute()) {
            $erro = $conn->errno === 1062 ? 'Produto já existe' : ('Erro ao inserir: '.$conn->error);
            $stmt->close();
            json_out(['erro' => $erro], 400);
        }
        $produto_id = $stmt->insert_id;
        $stmt->close();

        // Registra movimentação inicial (entrada) se quantidade > 0
        if ($quantidade > 0) {
            $stmt = $conn->prepare("
                INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
                VALUES (?, ?, ?, 'entrada', NOW())
            ");
            $stmt->bind_param("isi", $produto_id, $nome, $quantidade);
            $stmt->execute();
            $stmt->close();
        }

        json_out(['sucesso' => true, 'id' => $produto_id]);
    }

    case 'entradaProduto': {
        $id  = (int)($params['id'] ?? 0);
        $qtd = (int)($params['quantidade'] ?? 0);
        if ($id <= 0 || $qtd <= 0) {
            json_out(['erro' => 'ID e quantidade devem ser positivos'], 400);
        }

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        $stmt->bind_param("ii", $qtd, $id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            $stmt->close();
            json_out(['erro' => 'Produto não encontrado'], 404);
        }
        $stmt->close();

        $nome = get_produto_nome($conn, $id) ?? '';
        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
            VALUES (?, ?, ?, 'entrada', NOW())
        ");
        $stmt->bind_param("isi", $id, $nome, $qtd);
        $stmt->execute();
        $stmt->close();

        json_out(['sucesso' => true]);
    }

    case 'saidaProduto': {
        $id  = (int)($params['id'] ?? 0);
        $qtd = (int)($params['quantidade'] ?? 0);
        if ($id <= 0 || $qtd <= 0) {
            json_out(['erro' => 'ID e quantidade devem ser positivos'], 400);
        }

        // Verifica saldo
        $stmt = $conn->prepare("SELECT quantidade FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($estoque);
        if (!$stmt->fetch()) {
            $stmt->close();
            json_out(['erro' => 'Produto não encontrado'], 404);
        }
        $stmt->close();
        if ($estoque - $qtd < 0) {
            json_out(['erro' => 'Estoque insuficiente'], 400);
        }

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
        $stmt->bind_param("ii", $qtd, $id);
        $stmt->execute();
        $stmt->close();

        $nome = get_produto_nome($conn, $id) ?? '';
        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
            VALUES (?, ?, ?, 'saida', NOW())
        ");
        $stmt->bind_param("isi", $id, $nome, $qtd);
        $stmt->execute();
        $stmt->close();

        json_out(['sucesso' => true]);
    }

   case 'removerProduto': {
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        // Também aceita remoção por nome como fallback
        $nome = trim((string)($params['nome'] ?? ''));
        if ($nome === '') json_out(['erro' => 'Informe id ou nome'], 400);
        $stmt = $conn->prepare("SELECT id FROM produtos WHERE nome = ?");
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $stmt->bind_result($id_found);
        if (!$stmt->fetch()) { $stmt->close(); json_out(['erro' => 'Produto não encontrado'], 404); }
        $stmt->close();
        $id = (int)$id_found;
    }

    $nome = get_produto_nome($conn, $id) ?? '';

    // registra uma movimentação de “remoção” com tipo = 'removido'
    $stmt = $conn->prepare("
        INSERT INTO movimentacoes (produto_id, produto_nome, quantidade, tipo, data)
        VALUES (?, ?, 0, 'removido', NOW())
    ");
    $stmt->bind_param("is", $id, $nome);
    $stmt->execute();
    $stmt->close();

    // remove o produto
    $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    json_out(['sucesso' => true]);
}


    case 'listarMovimentacoes': {
        $sql = "
            SELECT 
                m.id,
                COALESCE(m.produto_nome, p.nome) AS produto_nome,
                m.tipo,
                m.quantidade,
                m.data
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            ORDER BY m.data DESC, m.id DESC
        ";
        $res = $conn->query($sql);
        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = $row; }
        json_out($out);
    }

    case 'relatorio': {
        $inicio = trim((string)($params['inicio'] ?? ''));
        $fim    = trim((string)($params['fim'] ?? ''));

        if ($inicio === '' || $fim === '') {
            // se não vierem datas, retorna últimos 90 dias
            $sql = "
                SELECT 
                    m.id,
                    COALESCE(m.produto_nome, p.nome) AS produto_nome,
                    m.tipo, m.quantidade, m.data
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                WHERE m.data >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                ORDER BY m.data DESC, m.id DESC
            ";
            $res = $conn->query($sql);
        } else {
            $inicio_ts = $inicio . " 00:00:00";
            $fim_ts    = $fim    . " 23:59:59";
            $stmt = $conn->prepare("
                SELECT 
                    m.id,
                    COALESCE(m.produto_nome, p.nome) AS produto_nome,
                    m.tipo, m.quantidade, m.data
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                WHERE m.data BETWEEN ? AND ?
                ORDER BY m.data DESC, m.id DESC
            ");
            $stmt->bind_param("ss", $inicio_ts, $fim_ts);
            $stmt->execute();
            $res = $stmt->get_result();
        }

        $out = [];
        while ($row = $res->fetch_assoc()) { $out[] = $row; }
        if (isset($stmt) && $stmt) { $stmt->close(); }
        json_out($out);
    }

    default:
        json_out(['erro' => 'Rota não tratada.']);
}
