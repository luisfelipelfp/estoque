<?php
function relatorio(mysqli $conn, array $f): array {
    $cond = [];
    $bind = [];
    $types = "";

    // produto / produto_id
    if (!empty($f["produto_id"])) {
        $cond[] = "m.produto_id = ?";
        $bind[] = (int)$f["produto_id"];
        $types .= "i";
    } elseif (!empty($f["produto"])) {
        if (is_numeric($f["produto"])) {
            $cond[] = "m.produto_id = ?";
            $bind[] = (int)$f["produto"];
            $types .= "i";
        } else {
            $cond[] = "COALESCE(m.produto_nome, p.nome) LIKE ?";
            $bind[] = "%".$f["produto"]."%";
            $types .= "s";
        }
    }

    if (!empty($f["tipo"]))        { $cond[] = "m.tipo = ?";        $bind[] = $f["tipo"];        $types .= "s"; }
    if (!empty($f["usuario"]))     { $cond[] = "m.usuario = ?";     $bind[] = $f["usuario"];     $types .= "s"; }
    if (!empty($f["responsavel"])) { $cond[] = "m.responsavel = ?"; $bind[] = $f["responsavel"]; $types .= "s"; }
    if (!empty($f["data_inicio"])) { $cond[] = "DATE(m.data) >= ?"; $bind[] = $f["data_inicio"]; $types .= "s"; }
    if (!empty($f["data_fim"]))    { $cond[] = "DATE(m.data) <= ?"; $bind[] = $f["data_fim"];    $types .= "s"; }

    $where = $cond ? ("WHERE ".implode(" AND ", $cond)) : "";

    $sql = "SELECT m.id, 
                   m.produto_id, 
                   COALESCE(m.produto_nome, p.nome) AS produto_nome,
                   m.tipo, 
                   m.quantidade, 
                   m.data, 
                   m.usuario, 
                   m.responsavel
              FROM movimentacoes m
              LEFT JOIN produtos p ON p.id = m.produto_id
              $where
             ORDER BY m.data DESC";

    $stmt = $conn->prepare($sql);
    if ($bind) $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = $row;
    }

    return ["sucesso" => true, "dados" => $dados];
}
