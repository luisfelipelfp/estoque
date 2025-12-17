<?php
/**
 * relatorios.php — Relatórios e estatísticas de movimentações
 * Compatível e revisado para PHP 8.5
 */

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/utils.php";

date_default_timezone_set("America/Sao_Paulo");

try {
    $conn = db();

    // 🔹 Captura filtros via GET
    $filtros = [
        "pagina"      => $_GET["pagina"]      ?? 1,
        "limite"      => $_GET["limite"]      ?? 50,
        "tipo"        => $_GET["tipo"]        ?? "",
        "produto_id"  => $_GET["produto_id"]  ?? "",
        "usuario_id"  => $_GET["usuario_id"]  ?? "",
        "data_inicio" => $_GET["data_inicio"] ?? "",
        "data_fim"    => $_GET["data_fim"]    ?? ""
    ];

    $pagina = max(1, (int)$filtros["pagina"]);
    $limite = max(1, (int)$filtros["limite"]);
    $offset = ($pagina - 1) * $limite;

    $cond  = [];
    $bind  = [];
    $types = "";

    // 🔸 Filtros dinâmicos (SQL seguro)
    if ($filtros["tipo"] !== "") {
        $cond[] = "m.tipo = ?";
        $bind[] = $filtros["tipo"];
        $types .= "s";
    }

    if ($filtros["produto_id"] !== "") {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$filtros["produto_id"];
        $types .= "i";
    }

    if ($filtros["usuario_id"] !== "") {
        $cond[] = "m.usuario_id = ?";
        $bind[] = (int)$filtros["usuario_id"];
        $types .= "i";
    }

    if ($filtros["data_inicio"] !== "") {
        $cond[] = "m.data >= ?";
        $bind[] = $filtros["data_inicio"] . " 00:00:00";
        $types .= "s";
    }

    if ($filtros["data_fim"] !== "") {
        $cond[] = "m.data <= ?";
        $bind[] = $filtros["data_fim"] . " 23:59:59";
        $types .= "s";
    }

    $where = $cond ? "WHERE " . implode(" AND ", $cond) : "";

    // ============================================================
    // 🔹 TOTAL DE REGISTROS
    // ============================================================
    $sqlTotal = "SELECT COUNT(*) AS total FROM movimentacoes m $where";
    $stmtT = $conn->prepare($sqlTotal);
    if (!$stmtT) {
        throw new Exception("Erro ao preparar SQL total: " . $conn->error);
    }

    if ($bind) {
        $stmtT->bind_param($types, ...$bind);
    }

    $stmtT->execute();
    $resT = $stmtT->get_result();
    $total = (int)($resT->fetch_assoc()["total"] ?? 0);
    $stmtT->close();

    // ============================================================
    // 🔹 LISTA PRINCIPAL (paginada)
    // ============================================================
    $sql = "
        SELECT 
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
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Erro ao preparar SQL principal: " . $conn->error);
    }

    // parâmetros dinâmicos
    if ($bind) {
        $types2 = $types . "ii";
        $params = array_merge($bind, [$limite, $offset]);
        $stmt->bind_param($types2, ...$params);
    } else {
        $stmt->bind_param("ii", $limite, $offset);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($r = $res->fetch_assoc()) {
        $dados[] = [
            "id"           => (int)$r["id"],
            "produto_nome" => $r["produto_nome"],
            "tipo"         => $r["tipo"],
            "quantidade"   => (int)$r["quantidade"],
            "data"         => date("d/m/Y H:i", strtotime($r["data"])),
            "usuario"      => $r["usuario"]
        ];
    }
    $stmt->close();

    // ============================================================
    // 🔹 DADOS PARA GRÁFICO
    // ============================================================
    $sqlGraf = "SELECT tipo, COUNT(*) AS total FROM movimentacoes m $where GROUP BY tipo";

    $stmtG = $conn->prepare($sqlGraf);
    if (!$stmtG) {
        throw new Exception("Erro ao preparar SQL gráfico: " . $conn->error);
    }

    if ($bind) {
        $stmtG->bind_param($types, ...$bind);
    }

    $stmtG->execute();
    $resG = $stmtG->get_result();

    $grafico = [
        "entrada" => 0,
        "saida"   => 0,
        "remocao" => 0
    ];

    while ($g = $resG->fetch_assoc()) {
        $grafico[$g["tipo"]] = (int)$g["total"];
    }

    $stmtG->close();

    // ============================================================
    // 🔹 RESPOSTA FINAL
    // ============================================================
    @ob_clean();
    json_response(true, "Relatório gerado com sucesso.", [
        "total"   => $total,
        "pagina"  => $pagina,
        "limite"  => $limite,
        "paginas" => (int)ceil($total / $limite),
        "dados"   => $dados,
        "grafico" => $grafico
    ]);

} catch (Throwable $e) {
    error_log("[relatorios.php] " . $e->getMessage());
    @ob_clean();
    json_response(false, "Erro ao gerar relatório: " . $e->getMessage());
}
