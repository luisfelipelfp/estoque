<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auditoria.php';

initLog('movimentacoes');

function produto_obter_quantidade(mysqli $conn, int $produtoId): int
{
    $stmt = $conn->prepare("
        SELECT quantidade
        FROM produtos
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao obter quantidade do produto.');
    }

    $stmt->bind_param('i', $produtoId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Produto não encontrado.');
    }

    return (int)$row['quantidade'];
}

function produto_atualizar_quantidade(mysqli $conn, int $produtoId, int $novaQtd): void
{
    $stmt = $conn->prepare("
        UPDATE produtos
        SET quantidade = ?
        WHERE id = ?
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao atualizar estoque.');
    }

    $stmt->bind_param('ii', $novaQtd, $produtoId);
    $stmt->execute();
    $stmt->close();
}

function mov_registrar(
    mysqli $conn,
    int $produtoId,
    string $tipo,
    int $quantidade,
    int $usuarioId,
    ?float $precoCusto,
    ?float $valorUnitario,
    ?string $observacao,
    ?int $fornecedorId
): array {

    try {

        if ($produtoId <= 0 || $quantidade <= 0) {
            return resposta(false, 'Dados inválidos.', null);
        }

        $conn->begin_transaction();

        $quantidadeAtual = produto_obter_quantidade($conn, $produtoId);

        $antes = [
            'produto_antes' => [
                'id' => $produtoId,
                'quantidade' => $quantidadeAtual
            ]
        ];

        $custoTotal = 0;
        $valorTotal = 0;
        $lucro = 0;

        if ($tipo === 'entrada') {

            $novaQuantidade = $quantidadeAtual + $quantidade;

            $custoUnitario = (float)$precoCusto;

            $stmtLote = $conn->prepare("
                INSERT INTO estoque_lotes
                (produto_id, quantidade, custo_unitario, fornecedor_id)
                VALUES (?, ?, ?, ?)
            ");

            if (!$stmtLote) {
                throw new RuntimeException('Erro ao criar lote.');
            }

            $stmtLote->bind_param(
                'iidi',
                $produtoId,
                $quantidade,
                $custoUnitario,
                $fornecedorId
            );

            $stmtLote->execute();
            $loteId = (int)$stmtLote->insert_id;
            $stmtLote->close();

            $custoTotal = $custoUnitario * $quantidade;

        }

        elseif ($tipo === 'saida') {

            if ($quantidade > $quantidadeAtual) {
                $conn->rollback();
                return resposta(false, 'Estoque insuficiente.', null);
            }

            $novaQuantidade = $quantidadeAtual - $quantidade;

            $restante = $quantidade;

            $stmtLotes = $conn->prepare("
                SELECT id, quantidade, custo_unitario
                FROM estoque_lotes
                WHERE produto_id = ?
                ORDER BY id ASC
            ");

            $stmtLotes->bind_param('i', $produtoId);
            $stmtLotes->execute();

            $res = $stmtLotes->get_result();

            $lotesConsumidos = [];

            while ($row = $res->fetch_assoc()) {

                if ($restante <= 0) break;

                $loteQtd = (int)$row['quantidade'];
                $custoUnitario = (float)$row['custo_unitario'];

                $consumir = min($loteQtd, $restante);

                $restante -= $consumir;

                $novoSaldo = $loteQtd - $consumir;

                $stmtUpd = $conn->prepare("
                    UPDATE estoque_lotes
                    SET quantidade = ?
                    WHERE id = ?
                ");

                $stmtUpd->bind_param(
                    'ii',
                    $novoSaldo,
                    $row['id']
                );

                $stmtUpd->execute();
                $stmtUpd->close();

                $custoTotal += $consumir * $custoUnitario;

                $lotesConsumidos[] = [
                    'lote_id' => (int)$row['id'],
                    'quantidade_consumida' => $consumir,
                    'custo_unitario' => $custoUnitario,
                    'custo_total' => $consumir * $custoUnitario
                ];
            }

            $valorTotal = (float)$valorUnitario * $quantidade;

            $lucro = $valorTotal - $custoTotal;

        }

        else {
            throw new RuntimeException('Tipo de movimentação inválido.');
        }

        produto_atualizar_quantidade(
            $conn,
            $produtoId,
            $novaQuantidade
        );

        $stmtMov = $conn->prepare("
            INSERT INTO movimentacoes
            (
                produto_id,
                fornecedor_id,
                tipo,
                quantidade,
                valor_unitario,
                custo_total,
                valor_total,
                lucro,
                observacao,
                usuario_id
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmtMov) {
            throw new RuntimeException('Erro ao registrar movimentação.');
        }

        $stmtMov->bind_param(
            'iisiddddsi',
            $produtoId,
            $fornecedorId,
            $tipo,
            $quantidade,
            $valorUnitario,
            $custoTotal,
            $valorTotal,
            $lucro,
            $observacao,
            $usuarioId
        );

        $stmtMov->execute();
        $movId = (int)$stmtMov->insert_id;
        $stmtMov->close();

        $depois = [
            'movimentacao' => [
                'id' => $movId,
                'produto_id' => $produtoId,
                'tipo' => $tipo,
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario,
                'custo_total' => $custoTotal,
                'valor_total' => $valorTotal,
                'lucro' => $lucro,
                'usuario_id' => $usuarioId
            ],
            'produto_depois' => [
                'id' => $produtoId,
                'quantidade' => $novaQuantidade
            ]
        ];

        auditoria_registrar(
            $conn,
            $usuarioId,
            'registrar_movimentacao',
            'movimentacao',
            $movId,
            $antes,
            $depois
        );

        $conn->commit();

        return resposta(true, 'Movimentação registrada com sucesso', [
            'id' => $movId
        ]);

    }

    catch (Throwable $e) {

        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {}

        logError('movimentacoes', 'Erro ao registrar movimentação', [
            'arquivo' => $e->getFile(),
            'linha' => $e->getLine(),
            'erro' => $e->getMessage()
        ]);

        return resposta(false, 'Erro ao registrar movimentação.', null);
    }
}

function mov_listar(mysqli $conn, array $filtros): array
{

    try {

        $sql = "
            SELECT
                m.id,
                p.nome AS produto,
                m.tipo,
                m.quantidade,
                m.valor_unitario,
                m.custo_total,
                m.valor_total,
                m.lucro,
                m.data,
                u.nome AS usuario
            FROM movimentacoes m
            INNER JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            ORDER BY m.data DESC
            LIMIT 200
        ";

        $res = $conn->query($sql);

        if (!$res) {
            return resposta(false, 'Erro ao listar movimentações.', []);
        }

        $dados = [];

        while ($row = $res->fetch_assoc()) {

            $dados[] = [
                'id' => (int)$row['id'],
                'produto' => (string)$row['produto'],
                'tipo' => (string)$row['tipo'],
                'quantidade' => (int)$row['quantidade'],
                'valor_unitario' => (float)$row['valor_unitario'],
                'custo_total' => (float)$row['custo_total'],
                'valor_total' => (float)$row['valor_total'],
                'lucro' => (float)$row['lucro'],
                'data' => (string)$row['data'],
                'usuario' => (string)$row['usuario']
            ];

        }

        $res->free();

        return resposta(true, 'OK', $dados);

    }

    catch (Throwable $e) {

        logError('movimentacoes', 'Erro ao listar movimentações', [
            'arquivo' => $e->getFile(),
            'linha' => $e->getLine(),
            'erro' => $e->getMessage()
        ]);

        return resposta(false, 'Erro ao listar movimentações.', []);
    }

}