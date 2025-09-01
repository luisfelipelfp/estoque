<?php
// Lista todos os produtos cadastrados
function produtos_listar(mysqli $conn): array {
    $res = $conn->query("SELECT * FROM produtos ORDER BY id DESC");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
    }
    return $out; // o front já aceita array puro
}

// Adiciona novo produto
function produtos_adicionar(mysqli $conn, string $nome, int $quantidade = 0): array {
    if (trim($nome) === "") {
        return ["sucesso" => false, "mensagem" => "Nome do produto é obrigatório."];
    }

    $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
    $stmt->bind_param("si", $nome, $quantidade);

    if (!$stmt->execute()) {
        return ["sucesso" => false, "mensagem" => "Erro ao adicionar produto: " . $conn->error];
    }

    $id = $conn->insert_id;

    // Se quantidade inicial > 0, registra movimentação de entrada
    if ($quantidade > 0) {
        $stmt2 = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel)
                                 VALUES (?, 'entrada', ?, NOW(), 'sistema', 'admin')");
        $stmt2->bind_param("ii", $id, $quantidade);
        $stmt2->execute();
    }

    return ["sucesso" => true, "mensagem" => "Produto adicionado com sucesso."];
}
