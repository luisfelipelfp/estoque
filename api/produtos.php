<?php
/**
 * produtos.php
 * Funções para manipulação de produtos (CRUD + integração com movimentações)
 */

require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/utils.php";

/**
 * Lista produtos
 */
function produtos_listar(mysqli $conn, bool $incluir_inativos = false): array {
    $sql = $incluir_inativos
        ? "SELECT id, nome, quantidade, ativo FROM produtos ORDER BY nome ASC"
        : "SELECT id, nome, quantidade, ativo FROM produtos WHERE ativo = 1 ORDER BY nome ASC";

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
    if ($quantidade_inicial < 0) {
        return resposta(false, "Quantidade inicial inválida.");
    }

    $conn->begin_transaction();
    try {
        // 🔎 Verifica duplicidade
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

        // 🟢 Insere o novo produto
        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade, ativo) VALUES (?, ?, 1)");
        if (!$stmt) {
            $conn->rollback();
            return resposta(false, "Erro ao preparar inserção: " . $conn->error);
        }

        $stmt->bind_param("si", $nome, $quantidade_inicial);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            $conn->rollback();
            return resposta(false, "Erro ao adicionar produto: " . $erro);
        }

        $produto_id = $conn->insert_id;
        $stmt->close();

        // 🔹 Registra movimentação inicial (se aplicável)
        if ($quantidade_inicial > 0) {
            $resMov = mov_registrar($conn, $produto_id, "entrada", $quantidade_inicial, $usuario_id ?? 0);
            if (!$resMov["sucesso"]) {
                $conn->rollback();
                return $resMov;
            }
        }

        $conn->commit();
        return resposta(true, "Produto adicionado com sucesso.", [
            "id"         => $produto_id,
            "quantidade" => $quantidade_inicial
        ]);

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
        // 🔍 Verifica se o produto existe e está ativo
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

        // ⚠️ Registra movimentação de remoção *antes* da atualização, para garantir histórico
        if ((int)$produto["quantidade"] > 0) {
            $resMov = mov_registrar($conn, $produto_id, "remocao", (int)$produto["quantidade"], $usuario_id ?? 0);
            if (!$resMov["sucesso"]) {
                $conn->rollback();
                return $resMov;
            }
        }

        // 🚫 Marca produto como inativo e zera quantidade
        $stmt = $conn->prepare("UPDATE produtos SET ativo = 0, quantidade = 0 WHERE id = ?");
        if (!$stmt) {
            $conn->rollback();
            return resposta(false, "Erro ao preparar atualização: " . $conn->error);
        }

        $stmt->bind_param("i", $produto_id);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            $conn->rollback();
            return resposta(false, "Erro ao remover produto: " . $erro);
        }
        $stmt->close();

        $conn->commit();
        return resposta(true, "Produto removido com sucesso.", [
            "id" => $produto_id,
            "nome" => $produto["nome"]
        ]);

    } catch (Throwable $e) {
        $conn->rollback();
        error_log("produtos_remover erro: " . $e->getMessage());
        return resposta(false, "Erro interno ao remover produto.");
    }
}
