<?php
function listar_movimentacoes() {
    $conn = db();
    $sql = "SELECT * FROM movimentacoes ORDER BY data DESC";
    $result = $conn->query($sql);

    $movs = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $movs[] = $row;
        }
    }

    echo json_encode($movs);
}

function registrar_movimentacao() {
    $dados = json_decode(file_get_contents("php://input"), true);
    $produto_id = $dados["produto_id"] ?? null;
    $tipo = $dados["tipo"] ?? null;
    $quantidade = (int)($dados["quantidade"] ?? 0);

    if (!$produto_id || !$tipo || $quantidade <= 0) {
        echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos para movimentação."]);
        exit;
    }

    $conn = db();

    // Inserir movimentação
    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $produto_id, $tipo, $quantidade);
    $stmt->execute();

    // Atualizar estoque do produto
    if ($tipo === "entrada") {
        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
        $stmt->bind_param("ii", $quantidade, $produto_id);
    } elseif ($tipo === "saida") {
        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
        $stmt->bind_param("iii", $quantidade, $produto_id, $quantidade);
    }
    $stmt->execute();

    echo json_encode(["sucesso" => true, "mensagem" => "Movimentação registrada com sucesso."]);
}
