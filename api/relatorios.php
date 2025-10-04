<?php
/**
 * api/relatorios.php — Relatórios e estatísticas de movimentações
 */

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/utils.php";

function relatorio(mysqli $conn, array $filtros = []): array {
    $pagina = max(1, (int)($filtros["pagina"] ?? 1));
    $limite = max(1, (int)($filtros["limite"] ?? 50));
    $offset = ($pagina - 1) * $limite;

    $cond  = [];
    $bind  = [];
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
    if (!empty($filtros["data_inicio"])) {
        $cond[] = "m.data >= ?";
        $bind[] = $filtros["data_inicio"] . " 00:00:00";
        $types .= "s";
    }
    if (!empty($filtros["data_fim"])) {
        $cond[] = "m.data <= ?";
        $bind[] = $filtros["data_fim"] . " 23:59:59";
        $types .= "s";
    }

    $where = $cond ? "WHERE " . implode(" AND ", $cond) : "";

    // Total
    $sqlTotal = "SELECT COUNT(*) AS total FROM movimentacoes m $where";
    $stmtT = $conn->prepare($sqlTotal);
    if ($bind) $stmtT->bind_param($types, ...$bind);
    $stmtT->execute();
    $total = (int)($stmtT->get_result()->fetch_assoc()["total"] ?? 0);
    $stmtT->close();

    // Lista
    $sql = "SELECT 
                m.id,
                COALESCE(p.nome, m.produto_nome, '[Produto removido]') AS produto_nome,
                m.tipo,
                m.quantidade,
                m.data,
                COALESCE(u.nome, 'Sistema') AS usuario
            FROM movimentacoes m
       LEFT JOIN produtos p ON p.id = m.produto_id
       LEFT JOIN usuarios u ON u.id = m.usuario_id
            $where
        ORDER BY m.data DESC, m.id DESC
           LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($bind)
        $stmt->bind_param($types . "ii", ...array_merge($bind, [$limite, $offset]));
    else
        $stmt->bind_param("ii", $limite, $offset);
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($r = $res->fetch_assoc()) {
        $dados[] = [
            "id" => (int)$r["id"],
            "produto_nome" => $r["produto_nome"],
            "tipo" => $r["tipo"],
            "quantidade" => (int)$r["quantidade"],
            "data" => date("d/m/Y H:i", strtotime($r["data"])),
            "usuario" => $r["usuario"]
        ];
    }
    $stmt->close();

    // Gráfico
    $sqlGraf = "SELECT tipo, COUNT(*) AS total FROM movimentacoes m $where GROUP BY tipo";
    $stmtG = $conn->prepare($sqlGraf);
    if ($bind) $stmtG->bind_param($types, ...$bind);
    $stmtG->execute();
    $resG = $stmtG->get_result();
    $graf = ["entrada" => 0, "saida" => 0, "remocao" => 0];
    while ($g = $resG->fetch_assoc()) $graf[$g["tipo"]] = (int)$g["total"];
    $stmtG->close();

    return resposta(true, "", [
        "total" => $total,
        "pagina" => $pagina,
        "limite" => $limite,
        "paginas" => (int)ceil($total / $limite),
        "dados" => $dados,
        "grafico" => $graf
    ]);
}
