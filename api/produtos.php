<?php
function listar_produtos() {
    $conn = db();
    $sql = "SELECT * FROM produtos ORDER BY id DESC";
    $result = $conn->query($sql);

    $produtos = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $produtos[] = $row;
        }
    }

    echo json_encode($produtos);
}

function adicionar_produto() {
    $dados = json_decode(file_get_contents("php://input"), true);
    $nome = $dados["nome"] ?? null;
    $quantidade = $dados["quantidade"] ?? 0;

    if (!$nome) {
        echo json_encode(["sucesso" => false, "mensagem" => "Nome do produto é obrigatório."]);
        exit;
    }

    $conn = db();
    $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
    $stmt->bind_param("si", $nome, $quantidade);

    if ($stmt->execute()) {
        echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado com sucesso."]);
    } else {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao adicionar produto: " . $conn->error]);
    }
}

function remover_produto() {
    $id = $_GET["id"] ?? null;

    if (!$id) {
        echo json_encode(["sucesso" => false, "mensagem" => "ID do produto é obrigatório."]);
        exit;
    }

    $conn = db();

    // Registrar movimentação antes de remover
    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade) 
                            SELECT id, 'remocao', quantidade FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    // Remover produto
    $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(["sucesso" => true, "mensagem" => "Produto removido com sucesso."]);
    } else {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao remover produto: " . $conn->error]);
    }
}
