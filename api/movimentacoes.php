<?php
/**
 * movimentacoes.php
 * Funções para listar, registrar e remover movimentações.
 */

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/utils.php";

/**
 * Listar movimentações com filtros (paginado e padronizado)
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
    if (!empty($f["usuario_id"])) {
        $where[] = "m.usuario_id = ?";
        $params[] = (int)$f["usuario_id"];
        $types .= "i";
    }
    if (!empty($f["usuario"])) {
        $where[] = "u.nome LIKE ?";
        $params[] = "%" . $f["usuario"] . "%";
        $types .= "s";
    }
    if (!empty($f["data_inicio"] ?? $f["data_ini"])) {
        $dataInicio = $f["data_inicio"] ?? $f["data_ini"];
        $where[] = "m.data >= ?";
        $params[] = $dataInicio . " 00:00:00";
        $types .= "s";
    }
    if (!empty($f["data_fim"])) {
        $where[] = "m.data <= ?";
        $params[] = $f["data_fim"] . " 23:59:59";
        $types .= "s";
    }

    $whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

    // 🔹 Total de registros
    $sqlTotal = "
        SELECT COUNT(*) AS total
        FROM movimentacoes m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        $whereSql
    ";
    $stmtT = $conn->prepare($sqlTotal);
    if ($types) {
        $stmtT->bind_param($types, ...$params);
    }
    $stmtT->execute();
    $total = (int)($stmtT->get_result()->fetch_assoc()["total"] ?? 0);
    $stmtT->close();

    // 🔹 Dados paginados
    $sql = "
        SELECT 
            m.id,
            COALESCE(m.produto_nome, p.nome) AS produto_nome,
            m.produto_id,
            m.tipo,
            m.quantidade,
            m.data,
            COALESCE(u.nome, 'Sistema') AS usuario
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        $whereSql
        ORDER BY m.data DESC, m.id DESC
        LIMIT ? OFFSET ?
    ";

    $paramsPage = $params;
    $typesPage  = $types . "ii";
    $paramsPage[] = $limite;
    $paramsPage[] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($typesPage, ...$paramsPage);
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = [
            "id"           => (int)$row["id"],
            "produto_id"   => (int)$row["produto_id"],
            "produto_nome" => $row["produto_nome"] ?? "",
            "tipo"         => $row["tipo"],
            "quantidade"   => (int)$row["quantidade"],
            "data"         => $row["data"],
            "usuario"      => $row["usuario"] ?? "Sistema",
        ];
    }

    $res->free();
    $stmt->close();

    return resposta(true, "", [
        "total"   => $total,
        "pagina"  => $pagina,
        "limite"  => $limite,
        "paginas" => (int)ceil($total / $limite),
        "dados"   => $dados
    ]);
}
