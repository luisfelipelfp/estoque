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

    if (!empty($f["produto_id"])) {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$f["produto_id"];
        $types .= "i";
    }

    if (!empty($f["tipo"])) {
        $cond[] = "m.tipo = ?";
        $bind[] = $f["tipo"];
        $types .= "s";
    }

    if (!empty($f["usuario_id"])) {
        $cond[] = "m.usuario_id = ?";
        $bind[] = (int)$f["usuario_id"];
        $types .= "i";
    }

    if (!empty($f["data_inicio"])) {
        $cond[] = "DATE(m.data) >= ?";
        $bind[] = $f["data_inicio"];
        $types .= "s";
    }

    if (!empty($f["data_fim"])) {
        $cond[] = "DATE(m.data) <= ?";
        $bind[] = $f["data_fim"];
        $types .= "s";
    }

    $where = $cond ? ("WHERE ".implode(" AND ", $cond)) : "";

    // total
    $sqlTotal = "SELECT COUNT(*) AS total
                   FROM movimentacoes m
              LEFT JOIN produtos p ON p.id = m.produto_id
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
                   u.nome AS usuario_nome,
                   u.nivel AS usuario_nivel
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

function mov_entrada(mysqli $conn, int $id, int $quantidade, int $usuario_id): array {
    if ($id <= 0 || $quantidade <= 0) {
        return ["sucesso" => false, "mensagem" => "Dados inválidos."];
    }

    $conn->begin_transaction();

    $chk = $conn->prepare("SELECT nome FROM produtos WHERE id = ? FOR UPDATE");
    $chk->bind_param("i", $id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$row) {
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
    }
    $nome = $row["nome"];

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id)
                            VALUES (?, ?, 'entrada', ?, NOW(), ?)");
    $stmt->bind_param("isii", $id, $nome, $quantidade, $usuario_id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Erro: ".$err];
    }

    $stmt->close();
    $conn->commit();
    return ["sucesso" => true, "mensagem" => "Entrada registrada"];
}

function mov_saida(mysqli $conn, int $id, int $quantidade, int $usuario_id): array {
    if ($id <= 0 || $quantidade <= 0) {
        return ["sucesso" => false, "mensagem" => "Dados inválidos."];
    }

    $conn->begin_transaction();

    $chk = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ? FOR UPDATE");
    $chk->bind_param("i", $id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$row) {
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
    }

    if ((int)$row["quantidade"] < $quantidade) {
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Estoque insuficiente."];
    }

    $nome = $row["nome"];

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id)
                            VALUES (?, ?, 'saida', ?, NOW(), ?)");
    $stmt->bind_param("isii", $id, $nome, $quantidade, $usuario_id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Erro: ".$err];
    }

    $stmt->close();
    $conn->commit();
    return ["sucesso" => true, "mensagem" => "Saída registrada"];
}

function mov_remover(mysqli $conn, int $id, int $usuario_id): array {
    if ($id <= 0) {
        return ["sucesso" => false, "mensagem" => "ID inválido."];
    }

    $conn->begin_transaction();

    $p = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ? FOR UPDATE");
    $p->bind_param("i", $id);
    $p->execute();
    $row = $p->get_result()->fetch_assoc();
    $p->close();

    if (!$row) {
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
    }

    $nome = $row["nome"];
    $qtd  = (int)$row["quantidade"];

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id)
                            VALUES (?, ?, 'remocao', ?, NOW(), ?)");
    $stmt->bind_param("isii", $id, $nome, $qtd, $usuario_id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $conn->rollback();
        return ["sucesso" => false, "mensagem" => "Erro: ".$err];
    }

    $stmt->close();
    $conn->commit();
    return ["sucesso" => true, "mensagem" => "Remoção registrada"];
}
