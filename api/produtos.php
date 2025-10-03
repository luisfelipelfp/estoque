<?php
/**
 * produtos.php
 * Funções para manipulação de produtos
 */

require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/utils.php";

/**
 * Lista produtos
 */
function produtos_listar(mysqli $conn, bool $incluir_inativos = false): array {
    $sql = $incluir_inativos
        ? "SELECT id, nome, quantidade, ativo FROM produtos ORDER BY id DESC"
        : "SELECT id, nome, quantidade, ativo FROM produtos WHERE ativo = 1 ORDER BY id DESC";

    $out = [];
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                "id"         => (int)$row["id"],
                "nome"       => (string)$row["nome"],
                "quantidade" => (int)$row["quantidade"],
                "ativo"      => (int)$row["ativo"],
            ];
        }
        $res->free();
    } else {
        error_log("produtos_listar falhou: " . $conn->error);
    }
    return $out;
}

/**
 * Adiciona um novo produto
 */
function produtos_adicionar(mysqli $conn, string $nome, int $quantidade_inicial = 0, ?int $usuario_id = null): array {
    $nome = trim($nome);
    if ($nome === "") {
        return resposta(false, "Nome do produto é obrigatório.");
    }

    $conn->begin_transaction();
    try {
        // Verifica duplicidade
        $stmtCheck = $conn->prepare("SELECT id FROM produtos WHERE nome = ?");
        if (!$stmtCheck) {
            $conn->rollback();
            return resposta(false, "Erro ao preparar consulta: " . $conn->error);
        }
        $stmtCheck->bind_param("s", $nome);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        if ($resCheck && $resCheck->num_rows > 0) {
            $stmtCheck->close();
            $conn->rollback();
            return resposta(false, "Já existe um produto com esse nome.");
        }
        $stmtCheck->close();

        // Insere produto
        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade, ativo) VALUES (?, 0, 1)");
        if (!$stmt) {
            $conn->rollback();
            return resposta(false, "Erro ao preparar inserção: " . $conn->error);
        }
        $stmt->bind_param("s", $nome);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            $conn->rollback();
            return resposta(false, "Erro ao adicionar produto: " . $erro);
        }
        $id = $conn->insert_id;
        $stmt->close();

        // Se quantidade inicial > 0 → registra movimentação
        if ($quantidade_inicial > 0) {
            $resMov = mov_registrar($conn, $id, "entrada", $quantidade_inicial, $usuario_id ?? 0);
            if (!$resMov["sucesso"]) {
                $conn->rollback();
                return $resMov;
            }
        }

        $conn->commit();
        return resposta(true, "Produto adicionado com sucesso.", ["id" => $id]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("produtos_adicionar erro: " . $e->getMessage());
        return resposta(false, "Erro interno ao adicionar produto.");
    }
}

/**
 * Remove (desativa) um produto
 */
function produtos_remover(mysqli $conn, int $produto_id, ?int $usuario_id = null): array {
    if ($produto_id <= 0) {
        return resposta(false, "ID inválido para remoção.");
    }

    $conn->begin_transaction();
    try {
        // Verifica se produto existe e está ativo
        $stmt = $conn->prepare("SELECT id, nome, quantidade, ativo FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $produto_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $produto = $res->fetch_assoc();
        $stmt->close();

        if (!$produto) {
            $conn->rollback();
            return resposta(false, "Produto não encontrado.");
        }
        if ((int)$produto["ativo"] === 0) {
            $conn->rollback();
            return resposta(false, "Produto já está inativo.");
        }

        // Marca produto como inativo
        $stmt = $conn->prepare("UPDATE produtos SET ativo = 0 WHERE id = ?");
        $stmt->bind_param("i", $produto_id);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            $conn->rollback();
            return resposta(false, "Erro ao remover produto: " . $erro);
        }
        $stmt->close();

        // Registra movimentação de remoção
        $resMov = mov_registrar($conn, $produto_id, "remocao", (int)$produto["quantidade"], $usuario_id ?? 0);
        if (!$resMov["sucesso"]) {
            $conn->rollback();
            return $resMov;
        }

        $conn->commit();
        return resposta(true, "Produto removido com sucesso.", ["id" => $produto_id]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("produtos_remover erro: " . $e->getMessage());
        return resposta(false, "Erro interno ao remover produto.");
    }
}
