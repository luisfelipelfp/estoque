<?php
/**
 * relatorios.php
 * Funções para geração de relatórios de movimentações
 */

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/produtos.php";
require_once __DIR__ . "/utils.php";

/**
 * Gera relatório de movimentações (completo, para análises)
 * Tipos aceitos: 'entrada', 'saida', 'remocao'
 */
function relatorio(mysqli $conn, array $filtros = []): array {
    $pagina = max(1, (int)($filtros["pagina"] ?? 1));
    $limite = max(1, (int)($filtros["limite"] ?? 50));
    $offset = ($pagina - 1) * $limite;

    $cond  = [];
    $bind  = [];
    $types = "";

    // 🔎 Filtro por tipo (entrada, saida, remocao)
    if (!empty($filtros["tipo"])) {
        $cond[] = "m.tipo = ?";
        $bind[] = $filtros["tipo"];
        $types .= "s";
    }

    // 🔎 Filtro por produto
    if (!empty($filtros["produto_id"])) {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$filtros["produto_id"];
        $types .= "i";
    }

    // 🔎 Filtro por usuário (id)
    if (!empty($filtros["usuario_id"])) {
        $cond[] = "m.usuario_id = ?";
        $bind[] = (int)$filtros["usuario_id"];
        $types .= "i";
    }

    // 🔎 Filtro por nome de usuário
    if (!empty($filtros["usuario"])) {
        $cond[] = "u.nome LIKE ?";
        $bind[] = "%" . $filtros["usuario"] . "%";
        $types .= "s";
    }

    // 🔎 Filtros de data (aceita data_inicio ou data_ini)
    $dataInicio = $filtros["data_inicio"] ?? ($filtros["data_ini"] ?? null);
    if (!empty($dataInicio)) {
        $cond[] = "m.data >= ?";
        $bind[] = $dataInicio . " 00:00:00";
        $types .= "s";
    }

    if (!empty($filtros["data_fim"])) {
        $cond[] = "m.data <= ?";
        $bind[] = $filtros["data_fim"] . " 23:59:59";
        $types .= "s";
    }

    $where = $cond ? "WHERE " . implode(" AND ", $cond) : "";

    // 🔹 Total de registros
    $sqlTotal = "SELECT COUNT(*) AS total
                   FROM movimentacoes m
              LEFT JOIN usuarios u ON u.id = m.usuario_id
                  $where";

    $stmtT = $conn->prepare($sqlTotal);
    if ($bind) {
        $stmtT->bind_param($types, ...$bind);
    }
    $stmtT->execute();
    $total = (int)($stmtT->get_result()->fetch_assoc()["total"] ?? 0);
    $stmtT->close();

    // 🔹 Dados paginados
    $sql = "SELECT 
                m.id,
                m.produto_id,
                -- Sempre prioriza o nome salvo na movimentação, pois garante histórico mesmo após remoção
                COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                m.tipo,
                m.quantidade,
                m.data,
                m.usuario_id,
                COALESCE(u.nome, 'Sistema') AS usuario
            FROM movimentacoes m
       LEFT JOIN produtos p ON p.id = m.produto_id
       LEFT JOIN usuarios u ON u.id = m.usuario_id
            $where
        ORDER BY m.data DESC, m.id DESC
           LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);

    if ($bind) {
        $params = array_merge($bind, [$limite, $offset]);
        $stmt->bind_param($types . "ii", ...$params);
    } else {
        $stmt->bind_param("ii", $limite, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $movs = [];
    while ($row = $result->fetch_assoc()) {
        $movs[] = [
            "id"           => (int)$row["id"],
            "produto_id"   => (int)$row["produto_id"],
            "produto_nome" => (string)($row["produto_nome"] ?? "[Produto removido]"),
            "tipo"         => (string)$row["tipo"],   // entrada, saida, remocao
            "quantidade"   => (int)$row["quantidade"],
            "data"         => (string)$row["data"],
            "usuario_id"   => (int)$row["usuario_id"],
            "usuario"      => (string)($row["usuario"] ?? "Sistema"),
        ];
    }
    $stmt->close();

    debug_log("Relatório gerado: total=$total, retornados=" . count($movs) . ", filtros=" . json_encode($filtros, JSON_UNESCAPED_UNICODE));

    return resposta(true, $total === 0 ? "Nenhum registro encontrado para os filtros aplicados." : "", [
        "total"             => $total,
        "pagina"            => $pagina,
        "limite"            => $limite,
        "paginas"           => (int)ceil($total / $limite),
        "dados"             => $movs,
        "filtros_aplicados" => $filtros
    ]);
}
