<?php
/**
 * produtos.php
 * Funções para manipulação de produtos
 */
require_once __DIR__ . "/movimentacoes.php";

/**
 * Lista produtos
 * @param mysqli $conn
 * @param bool $incluir_inativos Se true, lista todos, senão apenas ativos
 * @return array
 */
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

/**
 * Adiciona um novo produto
 * @param mysqli $conn
 * @param string $nome Nome do produto
 * @param int $quantidade_inicial Quantidade inicial (se > 0 gera movimentação de entrada)
 * @param int|null $usuario_id Usuário que criou o produto
 * @return array
 */
function produtos_adicionar(mysqli $conn, string $nome, int $quantidade_inicial = 0, ?int $usuario_id = null): array {
    if (trim($nome) === "") {
        return ["sucesso" => false, "mensagem" => "Nome do produto é obrigatório."];
    }

    $conn->begin_transaction();
    try {
        // Verifica duplicidade
        $stmtCheck = $conn->prepare("SELECT id FROM produtos WHERE nome = ?");
        $stmtCheck->bind_param("s", $nome);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        if ($resCheck->num_rows > 0) {
            $stmtCheck->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Já existe um produto com esse nome."];
        }
        $stmtCheck->close();

        // Insere produto (quantidade começa em 0)
        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade, ativo) VALUES (?, 0, 1)");
        $stmt->bind_param("s", $nome);
        if (!$stmt->execute()) {
            $erro = $conn->error;
            $stmt->close();
            $conn->rollback();
            return ["sucesso" => false, "mensagem" => "Erro ao adicionar produto: " . $erro];
        }
        $id = $conn->insert_id;
        $stmt->close();

        // Se quantidade inicial > 0 → gera movimentação de entrada
        if ($quantidade_inicial > 0) {
            $res = mov_entrada($conn, $id, $quantidade_inicial, $usuario_id);
            if (!$res["sucesso"]) {
                $conn->rollback();
                return $res; // já retorna o erro
            }
        }

        $conn->commit();
        return [
            "sucesso"  => true,
            "mensagem" => "Produto adicionado com sucesso.",
            "id"       => $id
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("produtos_adicionar erro: " . $e->getMessage());
        return ["sucesso" => false, "mensagem" => "Erro interno ao adicionar produto."];
    }
}
