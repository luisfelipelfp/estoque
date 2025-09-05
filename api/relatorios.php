<?php
/**
 * relatorios.php
 * Funções para geração de relatórios de movimentações e produtos
 */

require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/produtos.php";

/**
 * Gera relatório de movimentações
 */
function relatorio(mysqli $conn, array $filtros = []): array {
    $pagina = max(1, (int)($filtros["pagina"] ?? 1));
    $limite = max(1, (int)($filtros["limite"] ?? 50));
    $offset = ($pagina - 1) * $limite;

    $cond = [];
    $bind = [];
    $types = "";

    if (!empty($filtros["tipo"])) {
        $cond[] = "m.tipo = ?";
        $bind[] = $filtros["tipo"];
        $types .= "s";
    }

    if (!empty($filtros["produto_id"])) {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$filtros["produto_id"];
        $types .= "i";
    }

    if (!empty($filtros["usuario_id"])) {
        $cond[] = "m.usuario_id = ?";
        $bind[] = (int)$filtros["usuario_id"];
        $types .= "i";
    }

    if (!empty($filtros["usuario"])) {
        $cond[] = "(u.nome LIKE ? OR (u.id IS NULL AND 'Sistema' LIKE ?))";
        $bind[] = "%" . $filtros["usuario"] . "%";
        $bind[] = "%" . $filtros["usuario"] . "%";
        $types .= "ss";
    }

    if (!empty($filtros["data_inicio"])) {
        $cond[] = "DATE(m.data) >= ?";
        $bind[] = $filtros["data_inicio"];
        $types .= "s";
    }

    if (!empty($filtros["data_fim"])) {
        $cond[] = "DATE(m.data) <= ?";
        $bind[] = $filtros["data_fim"];
        $types .= "s";
    }

    $where = $cond ? "WHERE " . implode(" AND ", $cond) : "";

    // Total de registros
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

    // Dados
    $sql = "SELECT m.id, m.produto_id,
                   COALESCE(m.produto_nome, p.nome) AS produto_nome,
                   m.tipo, m.quantidade, m.data,
                   m.usuario_id, COALESCE(u.nome, 'Sistema') AS usuario
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
    while ($row = $res->fetch_assoc()) {
        $dados[] = $row;
    }
    $stmt->close();

    return [
        "sucesso"  => true,
        "total"    => $total,
        "pagina"   => $pagina,
        "limite"   => $limite,
        "paginas"  => (int)ceil($total / $limite),
        "dados"    => $dados,
        "produtos" => produtos_listar($conn, true), // inclui inativos/removidos também
    ];
}
