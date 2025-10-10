<?php
/**
 * api/relatorios.php — Relatórios e estatísticas de movimentações
 */

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/utils.php";

date_default_timezone_set("America/Sao_Paulo");

try {
    $conn = db();

    $f = array_merge($_GET, $_POST);

    $pagina = max(1, (int)($f["pagina"] ?? 1));
    $limite = max(1, (int)($f["limite"] ?? 50));
    $offset = ($pagina - 1) * $limite;

    $cond = [];
    $bind = [];
    $types = "";

    if (!empty($f["tipo"])) {
        $cond[] = "m.tipo = ?";
        $bind[] = $f["tipo"];
        $types .= "s";
    }
    if (!empty($f["produto_id"])) {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$f["produto_id"];
        $types .= "i";
    }
    if (!empty($f["data_inicio"])) {
        $cond[] = "m.data >= ?";
        $bind[] = $f["data_inicio"] . " 00:00:00";
        $types .= "s";
    }
    if (!empty($f["data_fim"])) {
        $cond[] = "m.data <= ?";
        $bind[] = $f["data_fim"] . " 23:59:59";
        $types .= "s";
    }

    $where = $cond ? "WHERE " . implode(" AND ", $cond) : "";

    $sql = "
        SELECT 
            m.id,
            COALESCE(p.nome, m.produto_nome, '[Produto removido]') AS produto_nome,
            m.tipo,
            m.quantidade,
            DATE_FORMAT(m.data, '%d/%m/%Y %H:%i') AS data,
            COALESCE(u.nome, 'Sistema') AS usuario
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        $where
        ORDER BY m.data DESC
        LIMIT ? OFFSET ?
    ";

    $bind[] = $limite;
    $bind[] = $offset;
    $types .= "ii";

    $stmt = $conn->prepare($sql);
    if ($bind) $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($r = $res->fetch_assoc()) {
        $dados[] = [
            "id"           => (int)$r["id"],
            "produto_nome" => $r["produto_nome"],
            "tipo"         => $r["tipo"],
            "quantidade"   => (int)$r["quantidade"],
            "data"         => $r["data"],
            "usuario"      => $r["usuario"]
        ];
    }

    echo json_encode([
        "sucesso" => true,
        "pagina"  => $pagina,
        "limite"  => $limite,
        "dados"   => $dados
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("[relatorios.php] " . $e->getMessage());
    echo json_encode([
        "sucesso" => false,
        "erro" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
