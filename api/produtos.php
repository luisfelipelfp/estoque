<?php
function produtos_listar(mysqli $conn): array {
    $res = $conn->query("SELECT * FROM produtos ORDER BY id DESC");
    $out = [];
    if ($res) while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out; // o front já aceita array puro
}

function produtos_adicionar(mysqli $conn, string $nome, int $quantidade = 0): array {
    if ($nome === "") {
        return ["sucesso" => false, "mensagem" => "Nome do produto é obrigatório."];
    }

    $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
    $stmt->bind_param("si", $nome, $quantidade);
    if (!$stmt->execute()) {
        return ["sucesso" => false, "mensagem" => "Erro ao adicionar: ".$conn->error];
    }

    // registra movimentação inicial como entrada (se > 0)
    if ($quantidade > 0) {
        $id = $conn->insert_id;
        $stmt2 = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel)
                                 VALUES (?, 'entrada', ?, NOW(), 'sistema', 'admin')");
        $stmt2->bind_param("ii", $id, $quantidade);
        $stmt2->execute();
    }

    return ["sucesso" => true, "mensagem" => "Produto adicionado com sucesso."];
}

function produtos_remover(mysqli $conn, int $id): array {
    if ($id <= 0) return ["sucesso" => false, "mensagem" => "ID inválido."];

    // pega quantidade atual para registrar remoção
    $stmtSel = $conn->prepare("SELECT quantidade FROM produtos WHERE id = ?");
    $stmtSel->bind_param("i", $id);
    $stmtSel->execute();
    $prod = $stmtSel->get_result()->fetch_assoc();
    if (!$prod) return ["sucesso" => false, "mensagem" => "Produto inexistente."];

    $qtd = (int)$prod["quantidade"];

    $stmtMov = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel)
                               VALUES (?, 'remocao', ?, NOW(), 'sistema', 'admin')");
    $stmtMov->bind_param("ii", $id, $qtd);
    $stmtMov->execute();

    $stmtDel = $conn->prepare("DELETE FROM produtos WHERE id = ?");
    $stmtDel->bind_param("i", $id);
    if ($stmtDel->execute()) {
        return ["sucesso" => true, "mensagem" => "Produto removido com sucesso."];
    }
    return ["sucesso" => false, "mensagem" => "Erro ao remover produto: ".$conn->error];
}
