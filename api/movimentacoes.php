<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

initLog('movimentacoes');

function coluna_existe_mov(mysqli $conn, string $tabela, string $coluna): bool
{
    static $cache = [];
    $db = (string)(($conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? ''));

    $key = $db . '|' . $tabela . '|' . $coluna;
    if (array_key_exists($key, $cache)) return (bool)$cache[$key];

    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$key] = $ok;
    return $ok;
}

function mov_registrar(
    mysqli $conn,
    int $produto_id,
    string $tipo,
    int $quantidade,
    ?int $usuario_id,
    ?float $preco_custo = null,
    ?float $valor_unitario = null,
    ?string $observacao = null
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
        logWarning('movimentacoes', 'Tipo de movimentação inválido', ['tipo' => $tipo]);
        return resposta(false, 'Tipo de movimentação inválido.');
    }

    // normaliza
    if ($preco_custo !== null && $preco_custo < 0) $preco_custo = null;
    if ($valor_unitario !== null && $valor_unitario < 0) $valor_unitario = null;

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare('SELECT nome, quantidade FROM produtos WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $produto_id);
        $stmt->execute();
        $produto = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$produto) {
            $conn->rollback();
            logWarning('movimentacoes', 'Produto não encontrado', ['produto_id' => $produto_id]);
            return resposta(false, 'Produto não encontrado.');
        }

        if ($tipo === 'entrada') {
            $sqlUpdate = 'UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?';
        } else {
            if ((int)$produto['quantidade'] < $quantidade) {
                $conn->rollback();
                logWarning('movimentacoes', 'Estoque insuficiente', [
                    'produto_id' => $produto_id,
                    'estoque'    => (int)$produto['quantidade'],
                    'solicitado' => $quantidade
                ]);
                return resposta(false, 'Quantidade insuficiente em estoque.');
            }
            $sqlUpdate = 'UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?';
        }

        $stmtUpd = $conn->prepare($sqlUpdate);
        $stmtUpd->bind_param('ii', $quantidade, $produto_id);
        $stmtUpd->execute();
        $stmtUpd->close();

        // atualiza custo do produto
        if ($preco_custo !== null && coluna_existe_mov($conn, 'produtos', 'preco_custo')) {
            $stmtC = $conn->prepare('UPDATE produtos SET preco_custo = ? WHERE id = ?');
            $stmtC->bind_param('di', $preco_custo, $produto_id);
            $stmtC->execute();
            $stmtC->close();
        }

        $nomeProduto = (string)$produto['nome'];

        $hasValorUnit = coluna_existe_mov($conn, 'movimentacoes', 'valor_unitario');
        $hasObs       = coluna_existe_mov($conn, 'movimentacoes', 'observacao');

        $cols  = ['produto_id', 'produto_nome', 'tipo', 'quantidade', 'usuario_id', 'data'];
        $vals  = ['?', '?', '?', '?', '?', 'NOW()'];
        $types = 'issii';
        $bind  = [$produto_id, $nomeProduto, $tipo, $quantidade, $usuario_id];

        if ($hasValorUnit) {
            $cols[] = 'valor_unitario';
            $vals[] = '?';
            $types .= 'd';
            // se não informou, grava 0? NÃO: melhor refletir "não informado"
            $bind[] = (float)($valor_unitario ?? 0.0);
        }

        if ($hasObs) {
            $cols[] = 'observacao';
            $vals[] = '?';
            $types .= 's';
            $bind[] = (string)($observacao ?? '');
        }

        $sqlIns = 'INSERT INTO movimentacoes (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        $stmtMov = $conn->prepare($sqlIns);
        $stmtMov->bind_param($types, ...$bind);
        $stmtMov->execute();
        $stmtMov->close();

        $conn->commit();

        logInfo('movimentacoes', 'Movimentação registrada', [
            'produto_id' => $produto_id,
            'tipo'       => $tipo,
            'quantidade' => $quantidade,
            'usuario_id' => $usuario_id,
            'preco_custo'=> $preco_custo,
            'valor_unitario' => $valor_unitario,
            'observacao' => $observacao
        ]);

        return resposta(true, 'Movimentação registrada com sucesso.');

    } catch (Throwable $e) {
        $conn->rollback();

        logError('movimentacoes', 'Erro ao registrar movimentação', [
            'arquivo'    => $e->getFile(),
            'linha'      => $e->getLine(),
            'erro'       => $e->getMessage(),
            'produto_id' => $produto_id,
            'tipo'       => $tipo,
            'quantidade' => $quantidade,
            'usuario_id' => $usuario_id
        ]);

        return resposta(false, 'Erro interno ao registrar movimentação.');
    }
}

function mov_listar(mysqli $conn, array $f): array
{
    $pagina = max(1, (int)($f['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($f['limite'] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where  = [];
    $params = [];
    $types  = '';

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

    try {
        $stmtT = $conn->prepare("SELECT COUNT(*) AS total FROM movimentacoes m $whereSql");
        if ($types) $stmtT->bind_param($types, ...$params);
        $stmtT->execute();
        $total = (int)($stmtT->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtT->close();

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
            ORDER BY m.data DESC, m.id DESC
            LIMIT ? OFFSET ?
        ";

        $paramsPage   = $params;
        $typesPage    = $types . 'ii';
        $paramsPage[] = $limite;
        $paramsPage[] = $offset;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($typesPage, ...$paramsPage);
        $stmt->execute();

        $res   = $stmt->get_result();
        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $dados[] = [
                'id'           => (int)$row['id'],
                'produto_id'   => (int)$row['produto_id'],
                'produto_nome' => (string)$row['produto_nome'],
                'tipo'         => (string)$row['tipo'],
                'quantidade'   => (int)$row['quantidade'],
                'data'         => (string)$row['data'],
                'usuario'      => (string)$row['usuario']
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

    } catch (Throwable $e) {
        logError('movimentacoes', 'Erro ao listar movimentações', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return resposta(false, 'Erro interno ao listar movimentações.');
    }
}