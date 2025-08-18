<?php
header('Content-Type: application/json; charset=utf-8');

$host = "192.168.15.100";
$user = "root";
$pass = "#Shakka01";
$db   = "estoque";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha na conexão', 'detalhe' => $e->getMessage()]);
    exit;
}

function resposta($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true) ?? [];
$acao  = $data['acao'] ?? '';

try {

    if ($acao === 'cadastrar') {
        $nome = trim((string)($data['nome'] ?? ''));
        $qtd  = (int)($data['qtd'] ?? 0);

        if ($nome === '') resposta(['erro' => 'Nome é obrigatório'], 400);
        if ($qtd < 0)     resposta(['erro' => 'Quantidade inválida'], 400);

        // Verifica duplicidade
        $stmt = $conn->prepare("SELECT id FROM produtos WHERE nome = ?");
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) resposta(['erro' => 'Produto já existe'], 400);
        $stmt->close();

        $conn->begin_transaction();
        try {
            // Insere produto como ativo
            // OBS: requer coluna produtos.ativo (TINYINT(1) DEFAULT 1)
            $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade, ativo) VALUES (?, ?, 1)");
            $stmt->bind_param("si", $nome, $qtd);
            $stmt->execute();
            $produto_id = $stmt->insert_id;
            $stmt->close();

            if ($qtd > 0) {
                // Registra movimentação inicial como ENTRADA
                $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'entrada', ?, NOW())");
                $stmt->bind_param("ii", $produto_id, $qtd);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            resposta(['sucesso' => true]);
        } catch (Throwable $t) {
            $conn->rollback();
            throw $t;
        }

    } elseif ($acao === 'entrada' || $acao === 'saida') {
        $nome = trim((string)($data['nome'] ?? ''));
        $qtd  = (int)($data['qtd'] ?? 0);
        if ($nome === '' || $qtd <= 0) resposta(['erro' => 'Dados inválidos'], 400);

        // Busca produto ativo
        $stmt = $conn->prepare("SELECT id, quantidade FROM produtos WHERE nome = ? AND ativo = 1");
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $res = $stmt->get_result();
        $prod = $res->fetch_assoc();
        $stmt->close();

        if (!$prod) resposta(['erro' => 'Produto não encontrado ou inativo'], 404);

        $produto_id = (int)$prod['id'];
        $atual      = (int)$prod['quantidade'];

        $conn->begin_transaction();
        try {
            if ($acao === 'entrada') {
                $novo = $atual + $qtd;
                $stmt = $conn->prepare("UPDATE produtos SET quantidade = ? WHERE id = ?");
                $stmt->bind_param("ii", $novo, $produto_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'entrada', ?, NOW())");
                $stmt->bind_param("ii", $produto_id, $qtd);
                $stmt->execute();
                $stmt->close();
            } else {
                if ($atual - $qtd < 0) {
                    $conn->rollback();
                    resposta(['erro' => 'Estoque insuficiente'], 400);
                }
                $novo = $atual - $qtd;
                $stmt = $conn->prepare("UPDATE produtos SET quantidade = ? WHERE id = ?");
                $stmt->bind_param("ii", $novo, $produto_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'saida', ?, NOW())");
                $stmt->bind_param("ii", $produto_id, $qtd);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            resposta(['sucesso' => true]);
        } catch (Throwable $t) {
            $conn->rollback();
            throw $t;
        }

    } elseif ($acao === 'remover') {
        // Remoção lógica (não apaga da tabela para preservar o histórico)
        $nome = trim((string)($data['nome'] ?? ''));
        if ($nome === '') resposta(['erro' => 'Nome é obrigatório'], 400);

        // Busca produto ativo
        $stmt = $conn->prepare("SELECT id, quantidade FROM produtos WHERE nome = ? AND ativo = 1");
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $res  = $stmt->get_result();
        $prod = $res->fetch_assoc();
        $stmt->close();

        if (!$prod) resposta(['erro' => 'Produto não encontrado ou já removido'], 404);

        $produto_id = (int)$prod['id'];
        $qtdAtual   = (int)$prod['quantidade'];

        $conn->begin_transaction();
        try {
            // 1) Registra uma SAÍDA com toda a quantidade atual (representa a remoção)
            if ($qtdAtual > 0) {
                $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'saida', ?, NOW())");
                $stmt->bind_param("ii", $produto_id, $qtdAtual);
                $stmt->execute();
                $stmt->close();
            }

            // 2) Zera quantidade e marca como inativo
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = 0, ativo = 0 WHERE id = ?");
            $stmt->bind_param("i", $produto_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            resposta(['sucesso' => true]);
        } catch (Throwable $t) {
            $conn->rollback();
            throw $t;
        }

    } elseif ($acao === 'listar') {
        // Lista apenas produtos ativos (removidos não aparecem)
        $res = $conn->query("SELECT id, nome, quantidade FROM produtos WHERE ativo = 1 ORDER BY nome");
        $produtos = $res->fetch_all(MYSQLI_ASSOC);
        resposta($produtos);

    } elseif ($acao === 'relatorio') {
        $inicio = trim((string)($data['inicio'] ?? ''));
        $fim    = trim((string)($data['fim'] ?? ''));

        if ($inicio === '' || $fim === '') resposta(['erro' => 'Período inválido'], 400);

        // LEFT JOIN para ainda mostrar movimentações caso o produto tenha sido removido fisicamente em algum momento
        $stmt = $conn->prepare("
            SELECT 
                m.id, 
                m.produto_id, 
                COALESCE(p.nome, '[EXCLUÍDO]') AS nome, 
                m.quantidade, 
                m.tipo, 
                m.data
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            WHERE m.data BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
            ORDER BY m.data ASC, m.id ASC
        ");
        $stmt->bind_param("ss", $inicio, $fim);
        $stmt->execute();
        $res = $stmt->get_result();
        $rel = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        resposta($rel);

    } else {
        resposta(['erro' => 'Ação inválida'], 400);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno', 'detalhe' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
