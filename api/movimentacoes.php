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
    if ($quantidade <= 0) {
        return resposta(false, "Quantidade inválida.");
    }

    // Busca nome do produto
    $stmt = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $produto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $produto = $res->fetch_assoc();
    $stmt->close();

    if (!$produto) {
        return resposta(false, "Produto não encontrado.");
    }

    // Atualiza estoque
    if ($tipo === "entrada") {
        $sqlUpdate = "UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?";
        $stmt = $conn->prepare($sqlUpdate);
        $stmt->bind_param("ii", $quantidade, $produto_id);
    } else {
        // saída ou remoção
        if ($produto["quantidade"] < $quantidade) {
            return resposta(false, "Quantidade insuficiente em estoque.");
        }
        $sqlUpdate = "UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?";
        $stmt = $conn->prepare($sqlUpdate);
        $stmt->bind_param("ii", $quantidade, $produto_id);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return resposta(false, "Erro ao atualizar estoque.");
    }
    $stmt->close();

    // Registrar na tabela movimentacoes
    $sqlMov = "INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, usuario_id, data) 
               VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sqlMov);
    $stmt->bind_param("issii", $produto_id, $produto["nome"], $tipo, $quantidade, $usuario_id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return resposta(false, "Erro ao registrar movimentação.");
    }

    return resposta(true, "Movimentação registrada com sucesso.");
}

/**
 * Listar movimentações (uso operacional do dia a dia)
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

    $whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

    // 🔹 Total de registros
    $sqlTotal = "
        SELECT COUNT(*) AS total
        FROM movimentacoes m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        $whereSql
    ";
    $stmtT = $conn->prepare($sqlTotal);
    if ($types) {
        $stmtT->bind_param($types, ...$params);
    }
    $stmtT->execute();
    $total = (int)($stmtT->get_result()->fetch_assoc()["total"] ?? 0);
    $stmtT->close();

    // 🔹 Dados paginados
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
        $whereSql
        ORDER BY m.data DESC, m.id DESC
        LIMIT ? OFFSET ?
    ";

    $paramsPage = $params;
    $typesPage  = $types . "ii";
    $paramsPage[] = $limite;
    $paramsPage[] = $offset;

    $stmt = $conn->prepare($sql);
    if ($typesPage) {
        $stmt->bind_param($typesPage, ...$paramsPage);
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

    return resposta(true, $total === 0 ? "Nenhuma movimentação encontrada." : "", [
        "total"   => $total,
        "pagina"  => $pagina,
        "limite"  => $limite,
        "paginas" => (int)ceil($total / $limite),
        "dados"   => $dados
    ]);
}
