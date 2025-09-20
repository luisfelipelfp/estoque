<?php
/**
 * movimentacoes.php
 * Funções para listar, registrar e remover movimentações.
 */

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";

/**
 * Listar movimentações com filtros
 */
function mov_listar(mysqli $conn, array $f): array {
    $pagina = max(1, (int)($f["pagina"] ?? 1));
    $limite = max(1, min(100, (int)($f["limite"] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where = [];
    $params = [];
    $types = "";

    if (!empty($f["produto_id"])) {
        $where[] = "m.produto_id = ?";
        $params[] = (int)$f["produto_id"];
        $types .= "i";
    }
    if (!empty($f["tipo"])) {
        $where[] = "m.tipo = ?";
        $params[] = $f["tipo"];
        $types .= "s";
    }
    if (!empty($f["usuario_id"])) {
        $where[] = "m.usuario_id = ?";
        $params[] = (int)$f["usuario_id"];
        $types .= "i";
    }
    if (!empty($f["usuario"])) {
        $where[] = "u.nome LIKE ?";
        $params[] = "%" . $f["usuario"] . "%";
        $types .= "s";
    }
    if (!empty($f["data_inicio"] ?? $f["data_ini"])) {
        $dataInicio = $f["data_inicio"] ?? $f["data_ini"];
        $where[] = "m.data >= ?";
        $params[] = $dataInicio . " 00:00:00";
        $types .= "s";
    }
    if (!empty($f["data_fim"])) {
        $where[] = "m.data <= ?";
        $params[] = $f["data_fim"] . " 23:59:59";
        $types .= "s";
    }

    $sql = "
        SELECT 
            m.id,
            COALESCE(m.produto_nome, p.nome) AS produto_nome,
            m.produto_id,
            m.tipo,
            m.quantidade,
            m.data,
            COALESCE(u.nome, 'Sistema') AS usuario
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        LEFT JOIN usuarios u ON u.id = m.usuario_id
    ";
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY m.data DESC, m.id DESC LIMIT ? OFFSET ?";

    $params[] = $limite;
    $types .= "i";
    $params[] = $offset;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = [
            "id"           => (int)$row["id"],
            "produto_id"   => (int)$row["produto_id"],
            "produto_nome" => $row["produto_nome"] ?? "",
            "tipo"         => $row["tipo"],
            "quantidade"   => (int)$row["quantidade"],
            "data"         => $row["data"],
            "usuario"      => $row["usuario"] ?? "Sistema",
        ];
    }

    $res->free();
    $stmt->close();

    return $dados;
}

/**
 * Registrar movimentação (entrada / saída)
 */
function mov_registrar(mysqli $conn, int $produto_id, string $tipo, int $quantidade, int $usuario_id): array {
    if ($produto_id <= 0 || $quantidade <= 0 || !in_array($tipo, ["entrada","saida"])) {
        return ["sucesso" => false, "mensagem" => "Dados inválidos."];
    }

    if ($tipo === "saida") {
        $sql = "UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $quantidade, $produto_id, $quantidade);
    } else {
        $sql = "UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $quantidade, $produto_id);
    }

    if (!$stmt->execute() || $stmt->affected_rows <= 0) {
        return ["sucesso" => false, "mensagem" => "Falha ao atualizar estoque."];
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, usuario_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isii", $produto_id, $tipo, $quantidade, $usuario_id);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok
        ? ["sucesso" => true, "mensagem" => "Movimentação registrada."]
        : ["sucesso" => false, "mensagem" => "Erro ao registrar movimentação."];
}

/**
 * Remover produto (somente admin)
 */
function mov_remover(mysqli $conn, int $produto_id, int $usuario_id): array {
    if ($produto_id <= 0) {
        return ["sucesso" => false, "mensagem" => "ID inválido."];
    }

    // Captura o nome antes de remover
    $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $nomeProduto = $res->fetch_assoc()["nome"] ?? null;
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, usuario_id) VALUES (?, ?, 'remocao', 0, ?)");
        $stmt->bind_param("isi", $produto_id, $nomeProduto, $usuario_id);
        $stmt->execute();
        $stmt->close();

        return ["sucesso" => true, "mensagem" => "Produto removido com sucesso."];
    }
    return ["sucesso" => false, "mensagem" => "Erro ao remover produto."];
}
