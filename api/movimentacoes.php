<?php
function mov_listar(mysqli $conn, array $f): array {
    $pagina = max(1, (int)($f["pagina"] ?? 1));
    $limite = max(1, (int)($f["limite"] ?? 10));
    $offset = ($pagina - 1) * $limite;

    $cond = [];
    $bind = [];
    $types = "";

    // produto / produto_id (aceita id numérico ou nome)
    $produto = $f["produto"] ?? null;
    $produto_id = $f["produto_id"] ?? null;

    if ($produto_id !== null && $produto_id !== "") {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$produto_id;
        $types .= "i";
    } elseif ($produto !== null && $produto !== "") {
        if (is_numeric($produto)) {
            $cond[] = "m.produto_id = ?";
            $bind[] = (int)$produto;
            $types .= "i";
        } else {
            // se quiser filtrar por nome, precisa do nome na tabela (ou join)
            $cond[] = "p.nome LIKE ?";
            $bind[] = "%".$produto."%";
            $types .= "s";
        }
    }

    if (!empty($f["tipo"])) {
        $cond[] = "m.tipo = ?";
        $bind[] = $f["tipo"];
        $types .= "s";
    }

    if (!empty($f["usuario"])) {
        $cond[] = "m.usuario = ?";
        $bind[] = $f["usuario"];
        $types .= "s";
    }

    if (!empty($f["responsavel"])) {
        $cond[] = "m.responsavel = ?";
        $bind[] = $f["responsavel"];
        $types .= "s";
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
                  $where";
    $stmtT = $conn->prepare($sqlTotal);
    if ($bind) $stmtT->bind_param($types, ...$bind);
    $stmtT->execute();
    $total = (int)($stmtT->get_result()->fetch_assoc()["total"] ?? 0);

    // dados
    $sql = "SELECT m.id, m.produto_id, p.nome AS produto_nome, m.tipo, m.quantidade, m.data, m.usuario, m.responsavel
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

    return [
        "sucesso" => true,
        "total"   => $total,
        "pagina"  => $pagina,
        "limite"  => $limite,
        "paginas" => (int)ceil($total / $limite),
        "dados"   => $dados,
    ];
}

function mov_entrada(mysqli $conn, int $id, int $quantidade, string $usuario = "sistema", string $responsavel = "admin"): array {
    if ($id <= 0 || $quantidade <= 0) return ["sucesso" => false, "mensagem" => "Dados inválidos."];

    $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
    $stmt->bind_param("ii", $quantidade, $id);
    if (!$stmt->execute()) return ["sucesso" => false, "mensagem" => "Erro ao atualizar estoque."];

    $stmt2 = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel)
                             VALUES (?, 'entrada', ?, NOW(), ?, ?)");
    $stmt2->bind_param("iiss", $id, $quantidade, $usuario, $responsavel);
    $stmt2->execute();

    return ["sucesso" => true, "mensagem" => "Entrada registrada"];
}

function mov_saida(mysqli $conn, int $id, int $quantidade, string $usuario = "sistema", string $responsavel = "admin"): array {
    if ($id <= 0 || $quantidade <= 0) return ["sucesso" => false, "mensagem" => "Dados inválidos."];

    $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
    $stmt->bind_param("iii", $quantidade, $id, $quantidade);
    $stmt->execute();
    if ($stmt->affected_rows <= 0) return ["sucesso" => false, "mensagem" => "Estoque insuficiente ou produto inexistente."];

    $stmt2 = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel)
                             VALUES (?, 'saida', ?, NOW(), ?, ?)");
    $stmt2->bind_param("iiss", $id, $quantidade, $usuario, $responsavel);
    $stmt2->execute();

    return ["sucesso" => true, "mensagem" => "Saída registrada"];
}
