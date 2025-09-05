<?php
/**
 * movimentacoes.php
 * Funções para listar e registrar movimentações (entrada/saida/remocao).
 */

function mov_listar(mysqli $conn, array $f): array {
    $pagina = max(1, (int)($f["pagina"] ?? 1));
    $limite = max(1, (int)($f["limite"] ?? 10));
    $offset = ($pagina - 1) * $limite;

    $cond = [];
    $bind = [];
    $types = "";

    // use isset para aceitar "0" e strings "0"
    if (isset($f["produto_id"]) && $f["produto_id"] !== "" && $f["produto_id"] !== null) {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$f["produto_id"];
        $types .= "i";
    }

    if (isset($f["tipo"]) && $f["tipo"] !== "") {
        $cond[] = "m.tipo = ?";
        $bind[] = $f["tipo"];
        $types .= "s";
    }

    if (isset($f["usuario_id"]) && $f["usuario_id"] !== "" && $f["usuario_id"] !== null) {
        $cond[] = "m.usuario_id = ?";
        $bind[] = (int)$f["usuario_id"];
        $types .= "i";
    }

    if (isset($f["usuario"]) && $f["usuario"] !== "") {
        // usa COALESCE pra incluir 'Sistema' quando usuário for NULL
        $cond[] = "COALESCE(u.nome, 'Sistema') LIKE ?";
        $bind[] = "%" . $f["usuario"] . "%";
        $types .= "s";
    }

    if (isset($f["data_inicio"]) && $f["data_inicio"] !== "") {
        $cond[] = "DATE(m.data) >= ?";
        $bind[] = $f["data_inicio"];
        $types .= "s";
    }

    if (isset($f["data_fim"]) && $f["data_fim"] !== "") {
        $cond[] = "DATE(m.data) <= ?";
        $bind[] = $f["data_fim"];
        $types .= "s";
    }

    $where = $cond ? ("WHERE ".implode(" AND ", $cond)) : "";

    // total
    $sqlTotal = "SELECT COUNT(*) AS total
                   FROM movimentacoes m
              LEFT JOIN usuarios u ON u.id = m.usuario_id
                  $where";
    $stmtT = $conn->prepare($sqlTotal);
    if ($bind) $stmtT->bind_param($types, ...$bind);
    $stmtT->execute();
    $total = (int)($stmtT->get_result()->fetch_assoc()["total"] ?? 0);
    $stmtT->close();

    // dados
    $sql = "SELECT m.id, m.produto_id,
                   COALESCE(m.produto_nome, p.nome) AS produto_nome,
                   m.tipo, m.quantidade, m.data,
                   m.usuario_id,
                   COALESCE(u.nome, 'Sistema') AS usuario
              FROM movimentacoes m
         LEFT JOIN produtos p ON p.id = m.produto_id
         LEFT JOIN usuarios u ON u.id = m.usuario_id
              $where
          ORDER BY m.data DESC
             LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);

    $types2 = $types . "ii";
    $params2 = array_merge($bind, [$limite, $offset]);

    if ($bind) $stmt->bind_param($types2, ...$params2);
    else       $stmt->bind_param("ii", $limite, $offset);

    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($row = $res->fetch_assoc()) $dados[] = $row;

    $stmt->close();

    return [
        "sucesso" => true,
        "total"   => $total,
        "pagina"  => $pagina,
        "limite"  => $limite,
        "paginas" => (int)ceil($total / $limite),
        "dados"   => $dados,
    ];
}

/**
 * Helpers: checar duplicata recente (últimos N segundos)
 */
function _mov_is_duplicate(mysqli $conn, int $produto_id, string $tipo, int $quantidade, ?int $usuario_id, int $window_seconds = 5): bool {
    $sql = "SELECT 1 FROM movimentacoes
            WHERE produto_id = ? AND tipo = ? AND quantidade = ? AND usuario_id = ?
              AND data >= (NOW() - INTERVAL ? SECOND)
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isiii", $produto_id, $tipo, $quantidade, $usuario_id, $window_seconds);
    $stmt->execute();
    $res = $stmt->get_result();
    $dup = $res && $res->num_rows > 0;
    $stmt->close();
    return $dup;
}

/**
 * Registrar entrada de produto (atômico, com lock e checagem de duplicata)
 */
function mov_entrada(mysqli $conn, int $produto_id, int $quantidade, int $usuario_id): array {
    if ($produto_id <= 0 || $quantidade <= 0) {
        return ["sucesso" => false, "mensagem" => "Produto ou quantidade inválida."];
    }

    // checa duplicata no DB (defesa extra caso sessões/cookies não protejam)
    if (_mov_is_duplicate($conn, $produto_id, 'entrada', $quantidade, $usuario_id, 5)) {
        return ["sucesso" => true, "mensagem" => "Ação ignorada: duplicata detectada (DB)."];
    }

    // início de transação para evitar race conditions
    $conn->begin_transaction();
    try {
        // ler nome do produto e travar linha
        $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
        }
        $nome = $row["nome"];

        // atualizar estoque
        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        $stmt->bind_param("ii", $quantidade, $produto_id);
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao atualizar produto: " . $conn->error];
        }
        $stmt->close();

        // inserir movimentação (grava também produto_nome)
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id) VALUES (?, ?, 'entrada', ?, NOW(), ?)");
        $stmt->bind_param("isii", $produto_id, $nome, $quantidade, $usuario_id);
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao registrar movimentação: " . $conn->error];
        }
        $stmt->close();

        $conn->commit();
        return ["sucesso" => true, "mensagem" => "Entrada registrada com sucesso."];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("mov_entrada erro: " . $e->getMessage());
        return ["sucesso" => false, "mensagem" => "Erro interno ao registrar entrada."];
    }
}

/**
 * Registrar saída de produto (atômico, com lock, valida estoque e checagem de duplicata)
 */
function mov_saida(mysqli $conn, int $produto_id, int $quantidade, int $usuario_id): array {
    if ($produto_id <= 0 || $quantidade <= 0) {
        return ["sucesso" => false, "mensagem" => "Produto ou quantidade inválida."];
    }

    if (_mov_is_duplicate($conn, $produto_id, 'saida', $quantidade, $usuario_id, 5)) {
        return ["sucesso" => true, "mensagem" => "Ação ignorada: duplicata detectada (DB)."];
    }

    $conn->begin_transaction();
    try {
        // ler nome e estoque, travando a linha
        $stmt = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
        }

        $estoque = (int)$row["quantidade"];
        $nome = $row["nome"];

        if ($quantidade > $estoque) {
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Estoque insuficiente."];
        }

        // atualizar estoque
        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
        $stmt->bind_param("ii", $quantidade, $produto_id);
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao atualizar produto: " . $conn->error];
        }
        $stmt->close();

        // inserir movimentacao
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id) VALUES (?, ?, 'saida', ?, NOW(), ?)");
        $stmt->bind_param("isii", $produto_id, $nome, $quantidade, $usuario_id);
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao registrar movimentação: " . $conn->error];
        }
        $stmt->close();

        $conn->commit();
        return ["sucesso" => true, "mensagem" => "Saída registrada com sucesso."];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("mov_saida erro: " . $e->getMessage());
        return ["sucesso" => false, "mensagem" => "Erro interno ao registrar saída."];
    }
}

/**
 * Remover produto (registra o nome antes de deletar; transação; checagem duplicata)
 */
function mov_remover(mysqli $conn, int $produto_id, int $usuario_id): array {
    if ($produto_id <= 0) {
        return ["sucesso" => false, "mensagem" => "ID do produto inválido."];
    }

    // checar duplicata de remoção recente
    if (_mov_is_duplicate($conn, $produto_id, 'remocao', 0, $usuario_id, 5)) {
        return ["sucesso" => true, "mensagem" => "Ação ignorada: duplicata detectada (DB)."];
    }

    $conn->begin_transaction();
    try {
        // buscar nome e travar
        $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
        }
        $nome = $row["nome"];

        // registrar movimentação (com produto_nome)
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id) VALUES (?, ?, 'remocao', 0, NOW(), ?)");
        $stmt->bind_param("isi", $produto_id, $nome, $usuario_id);
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao registrar movimentação de remoção: " . $conn->error];
        }
        $stmt->close();

        // deletar produto (produto_id nas movimentações ficará NULL por ON DELETE SET NULL)
        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $produto_id);
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao deletar produto: " . $conn->error];
        }
        $stmt->close();

        $conn->commit();
        return ["sucesso" => true, "mensagem" => "Produto removido com sucesso."];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("mov_remover erro: " . $e->getMessage());
        return ["sucesso" => false, "mensagem" => "Erro interno ao remover produto."];
    }
}
