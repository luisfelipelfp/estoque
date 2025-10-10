<?php
/**
 * movimentacoes.php
 * Funções para listar, registrar e remover movimentações.
 */

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/utils.php";

/**
 * Registrar movimentação (entrada, saída ou remoção)
 */
function mov_registrar(mysqli $conn, int $produto_id, string $tipo, int $quantidade, int $usuario_id): array {
    if ($quantidade <= 0) return resposta(false, "Quantidade inválida.");

    // Busca nome e quantidade atual
    $stmt = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $produto = $res->fetch_assoc();
    $stmt->close();

    if (!$produto) return resposta(false, "Produto não encontrado.");

    // Atualiza estoque
    if ($tipo === "entrada") {
        $sql = "UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?";
    } else {
        if ($produto["quantidade"] < $quantidade) {
            return resposta(false, "Quantidade insuficiente em estoque.");
        }
        $sql = "UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $quantidade, $produto_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return resposta(false, "Erro ao atualizar estoque.");
    }
    $stmt->close();

    // Registra a movimentação
    $sqlMov = "INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, usuario_id, data)
               VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sqlMov);
    $stmt->bind_param("issii", $produto_id, $produto["nome"], $tipo, $quantidade, $usuario_id);
    $stmt->execute();
    $stmt->close();

    return resposta(true, "Movimentação registrada com sucesso.");
}

/**
 * Listar movimentações
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
    if (!empty($f["tipo"]) && in_array($f["tipo"], ["entrada", "saida", "remocao"])) {
        $where[] = "m.tipo = ?";
        $params[] = $f["tipo"];
        $types .= "s";
    }
    if (!empty($f["data_inicio"])) {
        $where[] = "m.data >= ?";
        $params[] = $f["data_inicio"] . " 00:00:00";
        $types .= "s";
    }
    if (!empty($f["data_fim"])) {
        $where[] = "m.data <= ?";
        $params[] = $f["data_fim"] . " 23:59:59";
        $types .= "s";
    }

    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "
        SELECT 
            m.id,
            COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
            m.produto_id,
            m.tipo,
            m.quantidade,
            m.data,
            COALESCE(u.nome, 'Sistema') AS usuario
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        $whereSql
        ORDER BY m.data DESC
        LIMIT ? OFFSET ?
    ";

    $params[] = $limite;
    $params[] = $offset;
    $types .= "ii";

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($r = $res->fetch_assoc()) {
        $dados[] = [
            "id"           => (int)$r["id"],
            "produto_id"   => (int)$r["produto_id"],
            "produto_nome" => $r["produto_nome"],
            "tipo"         => $r["tipo"],
            "quantidade"   => (int)$r["quantidade"],
            "data"         => $r["data"],
            "usuario"      => $r["usuario"]
        ];
    }

    return resposta(true, "Movimentações listadas com sucesso.", $dados);
}
