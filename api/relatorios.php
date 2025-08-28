<?php
function relatorio() {
    $data_inicio = $_GET["data_inicio"] ?? null;
    $data_fim = $_GET["data_fim"] ?? null;
    $tipo = $_GET["tipo"] ?? null;

    $conn = db();

    $sql = "SELECT * FROM movimentacoes WHERE 1=1";
    $params = [];
    $types = "";

    if ($data_inicio && $data_fim) {
        $sql .= " AND DATE(data) BETWEEN ? AND ?";
        $params[] = $data_inicio;
        $params[] = $data_fim;
        $types .= "ss";
    }

    if ($tipo) {
        $sql .= " AND tipo = ?";
        $params[] = $tipo;
        $types .= "s";
    }

    $sql .= " ORDER BY data DESC";

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $relatorio = [];
    while ($row = $result->fetch_assoc()) {
        $relatorio[] = $row;
    }

    echo json_encode($relatorio);
}
