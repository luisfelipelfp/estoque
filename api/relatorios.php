<?php
/**
 * relatorios.php
 * Geração de relatórios de movimentações com filtros flexíveis.
 */

function relatorio(mysqli $conn, array $f): array {
    $cond = [];
    $bind = [];
    $types = "";

    // Filtros opcionais
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

    $where = $cond ? ("WHERE " . implode(" AND ", $cond)) : "";

    // Consulta principal
    $sql = "SELECT m.id,
                   m.produto_id,
                   COALESCE(m.produto_nome, p.nome) AS produto_nome,
                   m.tipo,
                   m.quantidade,
                   m.data,
                   m.usuario_id,
                   COALESCE(u.nome, 'Sistema') AS usuario_nome,
                   u.nivel AS usuario_nivel
              FROM movimentacoes m
         LEFT JOIN produtos p ON p.id = m.produto_id
         LEFT JOIN usuarios u ON u.id = m.usuario_id
              $where
          ORDER BY m.data DESC";

    $stmt = $conn->prepare($sql);
    if ($bind) {
        $stmt->bind_param($types, ...$bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = $row;
    }

    $stmt->close();

    return [
        "sucesso" => true,
        "total"   => count($dados),
        "dados"   => $dados
    ];
}
