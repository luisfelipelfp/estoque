<?php
require_once "db.php";

$acao = $_REQUEST["acao"] ?? null;

if ($acao === "relatorio") {
    $data_inicio = $_GET["data_inicio"] ?? null;
    $data_fim    = $_GET["data_fim"] ?? null;
    $tipo        = $_GET["tipo"] ?? null;
    $produto_id  = $_GET["produto_id"] ?? null;

    $conn = db();

    $sql = "SELECT m.*, p.nome AS produto_nome
              FROM movimentacoes m
              JOIN produtos p ON m.produto_id = p.id
             WHERE 1=1";
    $params = [];
    $types  = "";

    if ($data_inicio && $data_fim) {
        $sql .= " AND DATE(m.data) BETWEEN ? AND ?";
        $params[] = $data_inicio;
        $params[] = $data_fim;
        $types   .= "ss";
    }

    if ($tipo) {
        $sql .= " AND m.tipo = ?";
        $params[] = $tipo;
        $types   .= "s";
    }

    if ($produto_id) {
        $sql .= " AND m.produto_id = ?";
        $params[] = (int)$produto_id;
        $types   .= "i";
    }

    $sql .= " ORDER BY m.data DESC";

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
    exit;
}

echo json_encode(["sucesso" => false, "mensagem" => "Ação de relatório desconhecida."]);
