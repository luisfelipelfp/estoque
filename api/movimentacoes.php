<?php
/**
 * movimentacoes.php
 * Funções para listar e registrar movimentações (entrada/saida/remocao).
 */

/**
 * Lista movimentações com paginação e filtros.
 */
function mov_listar(mysqli $conn, array $f): array {
    $pagina = max(1, (int)($f["pagina"] ?? 1));
    $limite = max(1, (int)($f["limite"] ?? 10));
    $offset = ($pagina - 1) * $limite;

    $cond = [];
    $bind = [];
    $types = "";

    $produto = $f["produto"] ?? null;
    $produto_id = $f["produto_id"] ?? null;

    if ($produto_id !== null && $produto_id !== "") {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$produto_id;
        $types .= "i";
    } elseif ($produto !== null && $produto !== "") {
        $cond[] = "COALESCE(m.produto_nome, p.nome) LIKE ?";
        $bind[] = "%".$produto."%";
        $types .= "s";
    }

    if (!empty($f["tipo"]))        { $cond[] = "m.tipo = ?";        $bind[] = $f["tipo"];        $types .= "s"; }
    if (!empty($f["usuario"]))     { $cond[] = "m.usuario = ?";     $bind[] = $f["usuario"];     $types .= "s"; }
    if (!empty($f["responsavel"])) { $cond[] = "m.responsavel = ?"; $bind[] = $f["responsavel"]; $types .= "s"; }
    if (!empty($f["data_inicio"])) { $cond[] = "DATE(m.data) >= ?"; $bind[] = $f["data_inicio"]; $types .= "s"; }
    if (!empty($f["data_fim"]))    { $cond[] = "DATE(m.data) <= ?"; $bind[] = $f["data_fim"];    $types .= "s"; }

    $where = $cond ? ("WHERE ".implode(" AND ", $cond)) : "";

    // total
    $sqlTotal = "SELECT COUNT(*) AS total
                   FROM movimentacoes m
              LEFT JOIN produtos p ON p.id = m.produto_id
                  $where";
    $stmtT = $conn->prepare($sqlTotal);
    if ($bind) $stmtT->bind_param($types, ...$bind);
    $stmtT->execute();
    $total = (int)($stmtT->get_result()->fetch_assoc()["total"] ?? 0);
    $stmtT->close();

    // dados
    $sql = "SELECT m.id, m.produto_id,
                   COALESCE(m.produto_nome, p.nome) AS produto_nome,
                   m.tipo, m.quantidade, m.data, m.usuario, m.responsavel
              FROM movimentacoes m
         LEFT JOIN produtos p ON p.id = m.produto_id
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
 * Entrada
 */
function mov_entrada(mysqli $conn, int $id, int $quantidade, string $usuario = "sistema", string $responsavel = "admin"): array {
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

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
                            VALUES (?, ?, 'entrada', ?, NOW(), ?, ?)");
    $stmt->bind_param("isiss", $id, $nome, $quantidade, $usuario, $responsavel);

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

/**
 * Saída
 */
function mov_saida(mysqli $conn, int $id, int $quantidade, string $usuario = "sistema", string $responsavel = "admin"): array {
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

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
                            VALUES (?, ?, 'saida', ?, NOW(), ?, ?)");
    $stmt->bind_param("isiss", $id, $nome, $quantidade, $usuario, $responsavel);

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

/**
 * Remoção
 */
function mov_remover(mysqli $conn, int $id, string $usuario = "sistema", string $responsavel = "admin"): array {
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

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
                            VALUES (?, ?, 'remocao', ?, NOW(), ?, ?)");
    $stmt->bind_param("isiss", $id, $nome, $qtd, $usuario, $responsavel);

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
