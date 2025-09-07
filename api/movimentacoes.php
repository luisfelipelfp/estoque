<?php
/**
 * movimentacoes.php
 * Funções para listar e registrar movimentações (entrada/saida/remocao).
 */

function mov_listar(mysqli $conn, array $f): array {
    $pagina = max(1, (int)($f["pagina"] ?? 1));
    $limite = max(1, min(100, (int)($f["limite"] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where = [];
    $params = [];
    $types = "";

    if (!empty($f["produto_id"])) {
        $where[] = "m.produto_id = ?";
        $params[] = (int)$f["produto_id"];
        $types .= "i";
    }
    if (!empty($f["tipo"])) {
        $where[] = "m.tipo = ?";
        $params[] = $f["tipo"];
        $types .= "s";
    }
    if (!empty($f["data_ini"])) {
        $where[] = "m.data >= ?";
        $params[] = $f["data_ini"] . " 00:00:00";
        $types .= "s";
    }
    if (!empty($f["data_fim"])) {
        $where[] = "m.data <= ?";
        $params[] = $f["data_fim"] . " 23:59:59";
        $types .= "s";
    }

    $sql = "
        SELECT m.id,
               COALESCE(m.produto_nome, p.nome) AS produto_nome,
               m.produto_id,
               m.tipo,
               m.quantidade,
               m.data,
               u.nome AS usuario_nome
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        LEFT JOIN usuarios u ON u.id = m.usuario_id
    ";
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY m.data DESC, m.id DESC LIMIT ? OFFSET ?";

    $params[] = $limite;
    $types .= "i";
    $params[] = $offset;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = $row;
    }
    $res->free();
    $stmt->close();

    return $dados;
}

/**
 * Previne duplicatas em curto período
 */
function _mov_is_duplicate(mysqli $conn, int $produto_id, string $tipo, int $quantidade, ?int $usuario_id, int $seconds = 10): bool {
    $sql = "
        SELECT 1 FROM movimentacoes
        WHERE produto_id = ? AND tipo = ? AND quantidade = ?
          AND usuario_id <=> ?
          AND data >= (NOW() - INTERVAL ? SECOND)
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isiii", $produto_id, $tipo, $quantidade, $usuario_id, $seconds);
    $stmt->execute();
    $res = $stmt->get_result();
    $dup = $res->fetch_row() ? true : false;
    $res->free();
    $stmt->close();
    return $dup;
}

/**
 * Registra entrada/saída
 */
function mov_registrar(mysqli $conn, int $produto_id, string $tipo, int $quantidade, ?int $usuario_id): array {
    if (!in_array($tipo, ["entrada", "saida"])) {
        return ["sucesso" => false, "mensagem" => "Tipo inválido"];
    }
    if ($quantidade <= 0) {
        return ["sucesso" => false, "mensagem" => "Quantidade inválida"];
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $res->free();
        $stmt->close();

        if (!$row) {
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
        }
        $nome = $row["nome"];
        $estoque_atual = (int)$row["quantidade"];

        if ($tipo === "saida" && $estoque_atual < $quantidade) {
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Estoque insuficiente."];
        }

        if (_mov_is_duplicate($conn, $produto_id, $tipo, $quantidade, $usuario_id, 10)) {
            $conn->rollback();
            return ["sucesso" => true, "mensagem" => "Ação ignorada: duplicata detectada."];
        }

        $stmt = $conn->prepare("
            INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id)
            VALUES (?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->bind_param("issii", $produto_id, $nome, $tipo, $quantidade, $usuario_id);
        $stmt->execute();
        $stmt->close();

        $novo_estoque = $tipo === "entrada"
            ? $estoque_atual + $quantidade
            : $estoque_atual - $quantidade;

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = ? WHERE id = ?");
        $stmt->bind_param("ii", $novo_estoque, $produto_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        return ["sucesso" => true, "mensagem" => "Movimentação registrada com sucesso."];
    } catch (Throwable $e) {
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Erro interno: " . $e->getMessage()];
    }
}

/**
 * Remove produto (registra remoção e deleta)
 */
function mov_remover(mysqli $conn, int $produto_id, ?int $usuario_id): array {
    if ($produto_id <= 0) {
        return ["sucesso" => false, "mensagem" => "ID do produto inválido."];
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $res->free();
        $stmt->close();

        if (!$row) {
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
        }
        $nome = $row["nome"];

        if (_mov_is_duplicate($conn, $produto_id, 'remocao', 0, $usuario_id, 10)) {
            $conn->rollback();
            return ["sucesso" => true, "mensagem" => "Ação ignorada: duplicata detectada."];
        }

        if ($usuario_id === null) {
            $stmt = $conn->prepare("
                INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id)
                VALUES (?, ?, 'remocao', 0, NOW(), NULL)
            ");
            $stmt->bind_param("is", $produto_id, $nome);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id)
                VALUES (?, ?, 'remocao', 0, NOW(), ?)
            ");
            $stmt->bind_param("isi", $produto_id, $nome, $usuario_id);
        }
        if (!$stmt->execute()) {
            $erro = $conn->error;
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao registrar movimentação: " . $erro];
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $produto_id);
        if (!$stmt->execute()) {
            $erro = $conn->error;
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao deletar produto: " . $erro];
        }
        $stmt->close();

        $conn->commit();
        return ["sucesso" => true, "mensagem" => "Produto removido com sucesso."];
    } catch (Throwable $e) {
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Erro interno ao remover produto: " . $e->getMessage()];
    }
}
