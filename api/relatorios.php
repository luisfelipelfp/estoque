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

    // ======================
    // Total de registros (sem LIMIT/OFFSET)
    // ======================
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
        $cond[] = "COALESCE(u.nome, 'Sistema') LIKE ?";
        $bind[] = "%" . $filtros["usuario"] . "%";
        $types .= "s";
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

    $sqlTotal = "SELECT COUNT(*) AS total
                   FROM movimentacoes m
              LEFT JOIN usuarios u ON u.id = m.usuario_id
                  $where";
    $stmtT = $conn->prepare($sqlTotal);
    if ($bind) $stmtT->bind_param($types, ...$bind);
    $stmtT->execute();
    $total = (int)($stmtT->get_result()->fetch_assoc()["total"] ?? 0);
    $stmtT->close();

    // ======================
    // Dados via mov_listar()
    // ======================
    $movs = mov_listar($conn, [
        "pagina"     => $pagina,
        "limite"     => $limite,
        "tipo"       => $filtros["tipo"]       ?? null,
        "produto_id" => $filtros["produto_id"] ?? null,
        "data_ini"   => $filtros["data_inicio"] ?? null,
        "data_fim"   => $filtros["data_fim"]    ?? null,
    ]);

    return [
        "sucesso"  => true,
        "total"    => $total,
        "pagina"   => $pagina,
        "limite"   => $limite,
        "paginas"  => (int)ceil($total / $limite),
        "dados"    => $movs,
        "produtos" => produtos_listar($conn, true), // inclui inativos/removidos também
    ];
}
