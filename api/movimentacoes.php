<?php
/**
 * api/movimentacoes.php
 * CRUD de movimentações de estoque
 * Compatível com PHP 8.2+ / 8.5
 */

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

// Inicializa log específico
initLog('movimentacoes');

/**
 * Registrar movimentação (entrada, saída ou remoção)
 */
function mov_registrar(
    mysqli $conn,
    int $produto_id,
    string $tipo,
    int $quantidade,
    ?int $usuario_id
): array {

    if ($produto_id <= 0 || $quantidade <= 0) {
        logWarning('movimentacoes', 'Dados inválidos para movimentação', [
            'produto_id' => $produto_id,
            'quantidade' => $quantidade,
            'tipo'       => $tipo
        ]);
        return resposta(false, 'Dados inválidos.');
    }

    if (!in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
        logWarning('movimentacoes', 'Tipo de movimentação inválido', [
            'tipo' => $tipo
        ]);
        return resposta(false, 'Tipo de movimentação inválido.');
    }

    // Inicia transação
    $conn->begin_transaction();

    try {

        // 🔹 Busca produto com lock
        $stmt = $conn->prepare(
            'SELECT nome, quantidade
             FROM produtos
             WHERE id = ?
             FOR UPDATE'
        );

        $stmt->bind_param('i', $produto_id);
        $stmt->execute();
        $produto = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$produto) {
            $conn->rollback();
            logWarning('movimentacoes', 'Produto não encontrado', [
                'produto_id' => $produto_id
            ]);
            return resposta(false, 'Produto não encontrado.');
        }

        // 🔹 Regra de estoque
        if ($tipo === 'entrada') {

            $sqlUpdate = '
                UPDATE produtos
                SET quantidade = quantidade + ?
                WHERE id = ?
            ';

        } else {

            if ((int)$produto['quantidade'] < $quantidade) {
                $conn->rollback();
                logWarning('movimentacoes', 'Estoque insuficiente', [
                    'produto_id' => $produto_id,
                    'estoque'    => $produto['quantidade'],
                    'solicitado' => $quantidade
                ]);
                return resposta(false, 'Quantidade insuficiente em estoque.');
            }

            $sqlUpdate = '
                UPDATE produtos
                SET quantidade = quantidade - ?
                WHERE id = ?
            ';
        }

        // 🔹 Atualiza estoque
        $stmtUpd = $conn->prepare($sqlUpdate);
        $stmtUpd->bind_param('ii', $quantidade, $produto_id);
        $stmtUpd->execute();
        $stmtUpd->close();

        // 🔹 Registra movimentação
        $stmtMov = $conn->prepare(
            'INSERT INTO movimentacoes
                (produto_id, produto_nome, tipo, quantidade, usuario_id, data)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );

        $nomeProduto = $produto['nome'];
        $stmtMov->bind_param(
            'issii',
            $produto_id,
            $nomeProduto,
            $tipo,
            $quantidade,
            $usuario_id
        );

        $stmtMov->execute();
        $stmtMov->close();

        // Commit
        $conn->commit();

        logInfo('movimentacoes', 'Movimentação registrada', [
            'produto_id' => $produto_id,
            'tipo'       => $tipo,
            'quantidade' => $quantidade,
            'usuario_id' => $usuario_id
        ]);

        return resposta(true, 'Movimentação registrada com sucesso.');

    } catch (Throwable $e) {

        $conn->rollback();

        logError(
            'movimentacoes',
            'Erro ao registrar movimentação',
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        );

        return resposta(false, 'Erro interno ao registrar movimentação.');
    }
}

/**
 * Listar movimentações com filtros e paginação
 */
function mov_listar(mysqli $conn, array $f): array
{
    $pagina = max(1, (int)($f['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($f['limite'] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where  = [];
    $params = [];
    $types  = '';

    // 🔹 Filtros
    if (!empty($f['produto_id'])) {
        $where[]  = 'm.produto_id = ?';
        $params[] = (int)$f['produto_id'];
        $types   .= 'i';
    }

    if (!empty($f['tipo']) && in_array($f['tipo'], ['entrada', 'saida', 'remocao'], true)) {
        $where[]  = 'm.tipo = ?';
        $params[] = $f['tipo'];
        $types   .= 's';
    }

    if (!empty($f['data_inicio'])) {
        $where[]  = 'm.data >= ?';
        $params[] = $f['data_inicio'] . ' 00:00:00';
        $types   .= 's';
    }

    if (!empty($f['data_fim'])) {
        $where[]  = 'm.data <= ?';
        $params[] = $f['data_fim'] . ' 23:59:59';
        $types   .= 's';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // 🔹 Total
    $stmtT = $conn->prepare(
        "SELECT COUNT(*) AS total FROM movimentacoes m $whereSql"
    );

    if ($types) {
        $stmtT->bind_param($types, ...$params);
    }

    $stmtT->execute();
    $total = (int)$stmtT->get_result()->fetch_assoc()['total'];
    $stmtT->close();

    // 🔹 Dados
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

    $params[] = $limite;
    $params[] = $offset;
    $types   .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $res   = $stmt->get_result();
    $dados = [];

    while ($row = $res->fetch_assoc()) {
        $dados[] = [
            'id'           => (int)$row['id'],
            'produto_id'   => (int)$row['produto_id'],
            'produto_nome' => $row['produto_nome'],
            'tipo'         => $row['tipo'],
            'quantidade'   => (int)$row['quantidade'],
            'data'         => $row['data'],
            'usuario'      => $row['usuario']
        ];
    }

    $stmt->close();

    return resposta(true, '', [
        'total'   => $total,
        'pagina'  => $pagina,
        'limite'  => $limite,
        'paginas' => (int)ceil($total / $limite),
        'dados'   => $dados
    ]);
}
