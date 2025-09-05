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

    if (!empty($f["usuario"])) {
        $cond[] = "(u.nome LIKE ? OR (u.id IS NULL AND 'Sistema' LIKE ?))";
        $bind[] = "%".$f["usuario"]."%";
        $bind[] = "%".$f["usuario"]."%";
        $types .= "ss";
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
 * Registrar entrada de produto
 */
function mov_entrada(mysqli $conn, int $produto_id, int $quantidade, int $usuario_id): array {
    if ($produto_id <= 0 || $quantidade <= 0) {
        return ["sucesso" => false, "mensagem" => "Produto ou quantidade inválida."];
    }

    $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
    $stmt->bind_param("ii", $quantidade, $produto_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario_id) VALUES (?, 'entrada', ?, NOW(), ?)");
    $stmt->bind_param("iii", $produto_id, $quantidade, $usuario_id);
    $stmt->execute();
    $stmt->close();

    return ["sucesso" => true, "mensagem" => "Entrada registrada com sucesso."];
}

/**
 * Registrar saída de produto
 */
function mov_saida(mysqli $conn, int $produto_id, int $quantidade, int $usuario_id): array {
    if ($produto_id <= 0 || $quantidade <= 0) {
        return ["sucesso" => false, "mensagem" => "Produto ou quantidade inválida."];
    }

    // verificar estoque
    $stmt = $conn->prepare("SELECT quantidade FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $estoque = (int)($stmt->get_result()->fetch_assoc()["quantidade"] ?? 0);
    $stmt->close();

    if ($quantidade > $estoque) {
        return ["sucesso" => false, "mensagem" => "Estoque insuficiente."];
    }

    $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
    $stmt->bind_param("ii", $quantidade, $produto_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario_id) VALUES (?, 'saida', ?, NOW(), ?)");
    $stmt->bind_param("iii", $produto_id, $quantidade, $usuario_id);
    $stmt->execute();
    $stmt->close();

    return ["sucesso" => true, "mensagem" => "Saída registrada com sucesso."];
}

/**
 * Remover produto
 */
function mov_remover(mysqli $conn, int $produto_id, int $usuario_id): array {
    if ($produto_id <= 0) {
        return ["sucesso" => false, "mensagem" => "ID do produto inválido."];
    }

    // buscar nome antes de deletar
    $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ["sucesso" => false, "mensagem" => "Produto não encontrado."];
    }
    $nome = $row["nome"];

    // deletar produto
    $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $stmt->close();

    // registrar movimentação de remoção
    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id) VALUES (?, ?, 'remocao', 0, NOW(), ?)");
    $stmt->bind_param("isi", $produto_id, $nome, $usuario_id);
    $stmt->execute();
    $stmt->close();

    return ["sucesso" => true, "mensagem" => "Produto removido com sucesso."];
}
