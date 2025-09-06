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

// Adiciona novo produto (sempre começa com quantidade = 0)
function produtos_adicionar(mysqli $conn, string $nome, int $quantidade_inicial = 0, ?int $usuario_id = null): array {
    if (trim($nome) === "") {
        return ["sucesso" => false, "mensagem" => "Nome do produto é obrigatório."];
    }

    // insere produto sempre com 0 no banco
    $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade, ativo) VALUES (?, 0, 1)");
    $stmt->bind_param("s", $nome);

    if (!$stmt->execute()) {
        $erro = $conn->error;
        $stmt->close();
        return ["sucesso" => false, "mensagem" => "Erro ao adicionar produto: " . $erro];
    }

    $id = $conn->insert_id;
    $stmt->close();

    return [
        "sucesso" => true,
        "mensagem" => "Produto adicionado com sucesso.",
        "id"       => $id
    ];
}
