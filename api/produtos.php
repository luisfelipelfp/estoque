<?php
/**
 * produtos.php
 * Funções para manipulação de produtos
 */

// Lista produtos (por padrão apenas ativos)
function produtos_listar(mysqli $conn, bool $incluir_inativos = false): array {
    if ($incluir_inativos) {
        $sql = "SELECT id, nome, quantidade, ativo FROM produtos ORDER BY id DESC";
    } else {
        $sql = "SELECT id, nome, quantidade, ativo FROM produtos WHERE ativo = 1 ORDER BY id DESC";
    }

    $res = $conn->query($sql);
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
    }
    return $out; // o front já aceita array puro
}

// Adiciona novo produto
function produtos_adicionar(mysqli $conn, string $nome, int $quantidade = 0, ?int $usuario_id = null): array {
    if (trim($nome) === "") {
        return ["sucesso" => false, "mensagem" => "Nome do produto é obrigatório."];
    }

    $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade, ativo) VALUES (?, ?, 1)");
    $stmt->bind_param("si", $nome, $quantidade);

    if (!$stmt->execute()) {
        return ["sucesso" => false, "mensagem" => "Erro ao adicionar produto: " . $conn->error];
    }

    $id = $conn->insert_id;

    // Se quantidade inicial > 0, registra movimentação de entrada
    if ($quantidade > 0) {
        $stmt2 = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario_id)
                                 VALUES (?, ?, 'entrada', ?, NOW(), ?)");
        $stmt2->bind_param("isii", $id, $nome, $quantidade, $usuario_id);
        $stmt2->execute();
    }

    return ["sucesso" => true, "mensagem" => "Produto adicionado com sucesso."];
}
