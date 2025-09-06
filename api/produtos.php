<?php
/**
 * produtos.php
 * Funções para manipulação de produtos
 */

// Lista produtos (por padrão apenas ativos)
function produtos_listar(mysqli $conn, bool $incluir_inativos = false): array {
    $sql = $incluir_inativos
        ? "SELECT id, nome, quantidade, ativo FROM produtos ORDER BY id DESC"
        : "SELECT id, nome, quantidade, ativo FROM produtos WHERE ativo = 1 ORDER BY id DESC";

    $res = $conn->query($sql);
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        $res->free();
    }
    return $out; // o front já aceita array puro
}

// Adiciona novo produto (não registra movimentação)
function produtos_adicionar(mysqli $conn, string $nome, int $quantidade = 0, ?int $usuario_id = null): array {
    if (trim($nome) === "") {
        return ["sucesso" => false, "mensagem" => "Nome do produto é obrigatório."];
    }

    $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade, ativo) VALUES (?, ?, 1)");
    $stmt->bind_param("si", $nome, $quantidade);

    if (!$stmt->execute()) {
        $erro = $conn->error;
        $stmt->close();
        return ["sucesso" => false, "mensagem" => "Erro ao adicionar produto: " . $erro];
    }

    $id = $conn->insert_id;
    $stmt->close();

    // Retorna apenas os dados do produto criado
    return [
        "sucesso" => true,
        "mensagem" => "Produto adicionado com sucesso.",
        "id"       => $id,
        "nome"     => $nome,
        "quantidade" => $quantidade
    ];
}
