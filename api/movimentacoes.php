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

// funções mov_entrada, mov_saida, mov_remover
// (sem alterações, apenas mantidas iguais às suas)

function mov_entrada(mysqli $conn, int $id, int $quantidade, int $usuario_id): array {
    // ...
}

function mov_saida(mysqli $conn, int $id, int $quantidade, int $usuario_id): array {
    // ...
}

function mov_remover(mysqli $conn, int $id, int $usuario_id): array {
    // ...
}
