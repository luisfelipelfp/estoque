<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auditoria.php';

initLog('movimentacoes');

function coluna_existe_mov(mysqli $conn, string $tabela, string $coluna): bool
{
    static $cache = [];

    $dbRow = $conn->query("SELECT DATABASE() AS db");
    $db = '';

    if ($dbRow instanceof mysqli_result) {
        $assoc = $dbRow->fetch_assoc();
        $db = (string)($assoc['db'] ?? '');
        $dbRow->free();
    }

    $key = $db . '|' . $tabela . '|' . $coluna;

    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }

    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$key] = $ok;
    return $ok;
}

function mov_normalizar_observacao(?string $observacao): ?string
{
    $valor = trim((string)$observacao);
    $valor = preg_replace('/\s+/u', ' ', $valor) ?? $valor;
    return $valor !== '' ? $valor : null;
}

function mov_normalizar_texto(?string $valor): string
{
    $texto = trim((string)$valor);
    $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;
    return $texto;
}

function mov_data_valida(?string $data): bool
{
    $valor = trim((string)$data);
    if ($valor === '') {
        return false;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $valor);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $valor;
}

function mov_obter_produto_for_update(mysqli $conn, int $produto_id): ?array
{
    $hasAtivo = coluna_existe_mov($conn, 'produtos', 'ativo');

    $sql = "
        SELECT
            id,
            nome,
            quantidade,
            COALESCE(preco_custo, 0) AS preco_custo,
            COALESCE(preco_venda, 0) AS preco_venda
            " . ($hasAtivo ? ", COALESCE(ativo, 1) AS ativo" : ", 1 AS ativo") . "
        FROM produtos
        WHERE id = ?
        FOR UPDATE
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Erro ao bloquear produto para movimentação.');
    }

    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function mov_produto_snapshot(mysqli $conn, int $produto_id): ?array
{
    $hasAtivo = coluna_existe_mov($conn, 'produtos', 'ativo');

    $sql = "
        SELECT
            id,
            nome,
            quantidade,
            COALESCE(preco_custo, 0) AS preco_custo,
            COALESCE(preco_venda, 0) AS preco_venda
            " . ($hasAtivo ? ", COALESCE(ativo, 1) AS ativo" : ", 1 AS ativo") . "
        FROM produtos
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar snapshot do produto na movimentação.');
    }

    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'id'          => (int)$row['id'],
        'nome'        => (string)$row['nome'],
        'quantidade'  => (int)$row['quantidade'],
        'preco_custo' => (float)$row['preco_custo'],
        'preco_venda' => (float)$row['preco_venda'],
        'ativo'       => (int)($row['ativo'] ?? 1),
    ];
}

function mov_fornecedor_obter_para_entrada(mysqli $conn, int $fornecedor_id): ?array
{
    $stmt = $conn->prepare("
        SELECT
            id,
            nome,
            COALESCE(ativo, 1) AS ativo
        FROM fornecedores
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao validar fornecedor da entrada.');
    }

    $stmt->bind_param('i', $fornecedor_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function mov_auditoria_snapshot(mysqli $conn, int $movimentacaoId): ?array
{
    if ($movimentacaoId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            produto_id,
            produto_nome,
            fornecedor_id,
            tipo,
            quantidade,
            valor_unitario,
            custo_unitario,
            custo_total,
            valor_total,
            lucro,
            observacao,
            data,
            usuario_id
        FROM movimentacoes
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar snapshot da movimentação.');
    }

    $stmt->bind_param('i', $movimentacaoId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $dados = [
        'id'             => (int)$row['id'],
        'produto_id'     => $row['produto_id'] !== null ? (int)$row['produto_id'] : null,
        'produto_nome'   => (string)($row['produto_nome'] ?? ''),
        'fornecedor_id'  => $row['fornecedor_id'] !== null ? (int)$row['fornecedor_id'] : null,
        'tipo'           => (string)$row['tipo'],
        'quantidade'     => (int)$row['quantidade'],
        'valor_unitario' => $row['valor_unitario'] !== null ? (float)$row['valor_unitario'] : null,
        'custo_unitario' => $row['custo_unitario'] !== null ? (float)$row['custo_unitario'] : null,
        'custo_total'    => $row['custo_total'] !== null ? (float)$row['custo_total'] : null,
        'valor_total'    => $row['valor_total'] !== null ? (float)$row['valor_total'] : null,
        'lucro'          => $row['lucro'] !== null ? (float)$row['lucro'] : null,
        'observacao'     => (string)($row['observacao'] ?? ''),
        'data'           => (string)($row['data'] ?? ''),
        'usuario_id'     => $row['usuario_id'] !== null ? (int)$row['usuario_id'] : null,
        'lotes'          => [],
    ];

    $stmtLotes = $conn->prepare("
        SELECT
            msl.lote_id,
            msl.quantidade_consumida,
            msl.custo_unitario,
            msl.custo_total
        FROM movimentacao_saida_lotes msl
        WHERE msl.movimentacao_saida_id = ?
        ORDER BY msl.id ASC
    ");

    if ($stmtLotes) {
        $stmtLotes->bind_param('i', $movimentacaoId);
        $stmtLotes->execute();
        $resLotes = $stmtLotes->get_result();

        while ($l = $resLotes->fetch_assoc()) {
            $dados['lotes'][] = [
                'lote_id'              => (int)$l['lote_id'],
                'quantidade_consumida' => (int)$l['quantidade_consumida'],
                'custo_unitario'       => (float)$l['custo_unitario'],
                'custo_total'          => (float)$l['custo_total'],
            ];
        }

        $stmtLotes->close();
    }

    return $dados;
}

function mov_inserir_registro(
    mysqli $conn,
    int $produto_id,
    string $produto_nome,
    ?int $fornecedor_id,
    string $tipo,
    int $quantidade,
    ?float $valor_unitario,
    ?float $custo_unitario,
    ?float $custo_total,
    ?float $valor_total,
    ?float $lucro,
    ?int $usuario_id,
    ?string $observacao
): int {
    $hasFornecedorId = coluna_existe_mov($conn, 'movimentacoes', 'fornecedor_id');
    $hasObs          = coluna_existe_mov($conn, 'movimentacoes', 'observacao');
    $hasCustoUnit    = coluna_existe_mov($conn, 'movimentacoes', 'custo_unitario');
    $hasCustoTotal   = coluna_existe_mov($conn, 'movimentacoes', 'custo_total');
    $hasValorTotal   = coluna_existe_mov($conn, 'movimentacoes', 'valor_total');
    $hasLucro        = coluna_existe_mov($conn, 'movimentacoes', 'lucro');
    $hasValorUnit    = coluna_existe_mov($conn, 'movimentacoes', 'valor_unitario');
    $hasProdutoNome  = coluna_existe_mov($conn, 'movimentacoes', 'produto_nome');

    $cols  = ['produto_id'];
    $vals  = ['?'];
    $types = 'i';
    $bind  = [$produto_id];

    if ($hasProdutoNome) {
        $cols[] = 'produto_nome';
        $vals[] = '?';
        $types .= 's';
        $bind[] = $produto_nome;
    }

    if ($hasFornecedorId) {
        $cols[] = 'fornecedor_id';
        $vals[] = '?';
        $types .= 'i';
        $bind[] = $fornecedor_id;
    }

    $cols[] = 'tipo';
    $vals[] = '?';
    $types .= 's';
    $bind[] = $tipo;

    $cols[] = 'quantidade';
    $vals[] = '?';
    $types .= 'i';
    $bind[] = $quantidade;

    if ($hasValorUnit) {
        $cols[] = 'valor_unitario';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $valor_unitario;
    }

    if ($hasCustoUnit) {
        $cols[] = 'custo_unitario';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $custo_unitario;
    }

    if ($hasCustoTotal) {
        $cols[] = 'custo_total';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $custo_total;
    }

    if ($hasValorTotal) {
        $cols[] = 'valor_total';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $valor_total;
    }

    if ($hasLucro) {
        $cols[] = 'lucro';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $lucro;
    }

    $cols[] = 'data';
    $vals[] = 'NOW()';

    $cols[] = 'usuario_id';
    $vals[] = '?';
    $types .= 'i';
    $bind[] = $usuario_id;

    if ($hasObs) {
        $cols[] = 'observacao';
        $vals[] = '?';
        $types .= 's';
        $bind[] = $observacao;
    }

    $sql = 'INSERT INTO movimentacoes (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar inserção da movimentação.');
    }

    $stmt->bind_param($types, ...$bind);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Erro ao inserir movimentação.');
    }

    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
}

function mov_criar_lote_entrada(
    mysqli $conn,
    int $produto_id,
    ?int $fornecedor_id,
    int $movimentacao_entrada_id,
    int $quantidade,
    float $custo_unitario
): void {
    $stmt = $conn->prepare("
        INSERT INTO estoque_lotes
            (produto_id, fornecedor_id, movimentacao_entrada_id, quantidade_entrada, quantidade_restante, custo_unitario)
        VALUES
            (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao criar lote de entrada.');
    }

    $stmt->bind_param(
        'iiiiid',
        $produto_id,
        $fornecedor_id,
        $movimentacao_entrada_id,
        $quantidade,
        $quantidade,
        $custo_unitario
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Erro ao salvar lote de entrada.');
    }

    $stmt->close();
}

function mov_buscar_lotes_fifo(mysqli $conn, int $produto_id): array
{
    $stmt = $conn->prepare("
        SELECT
            id,
            quantidade_entrada,
            quantidade_restante,
            custo_unitario,
            criado_em
        FROM estoque_lotes
        WHERE produto_id = ?
          AND quantidade_restante > 0
        ORDER BY criado_em ASC, id ASC
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao buscar lotes FIFO.');
    }

    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $lotes = [];
    while ($row = $res->fetch_assoc()) {
        $lotes[] = [
            'id'                  => (int)$row['id'],
            'quantidade_entrada'  => (int)$row['quantidade_entrada'],
            'quantidade_restante' => (int)$row['quantidade_restante'],
            'custo_unitario'      => (float)$row['custo_unitario'],
            'criado_em'           => (string)$row['criado_em'],
        ];
    }

    $stmt->close();
    return $lotes;
}

function mov_obter_custo_referencia_atual(mysqli $conn, int $produto_id): float
{
    $stmt = $conn->prepare("
        SELECT COALESCE(custo_unitario, 0) AS custo_unitario
        FROM estoque_lotes
        WHERE produto_id = ?
          AND quantidade_restante > 0
        ORDER BY criado_em ASC, id ASC
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao obter custo de referência do produto.');
    }

    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['custo_unitario'] ?? 0);
}

function mov_planejar_consumo_fifo(mysqli $conn, int $produto_id, int $quantidade): array
{
    $lotes = mov_buscar_lotes_fifo($conn, $produto_id);

    if (empty($lotes)) {
        return [
            'ok' => false,
            'mensagem' => 'Este produto possui estoque sem lotes vinculados. Faça uma nova entrada para gerar lotes antes de registrar saídas.'
        ];
    }

    $disponivel = 0;
    foreach ($lotes as $lote) {
        $disponivel += (int)$lote['quantidade_restante'];
    }

    if ($disponivel < $quantidade) {
        return [
            'ok' => false,
            'mensagem' => 'Quantidade insuficiente em estoque por lote.'
        ];
    }

    $restante = $quantidade;
    $consumos = [];
    $custoTotal = 0.0;

    foreach ($lotes as $lote) {
        if ($restante <= 0) {
            break;
        }

        $saldoLote = (int)$lote['quantidade_restante'];
        if ($saldoLote <= 0) {
            continue;
        }

        $qtdConsumida = min($restante, $saldoLote);
        $custoUnitario = (float)$lote['custo_unitario'];
        $custoLinha = $qtdConsumida * $custoUnitario;

        $consumos[] = [
            'lote_id'              => (int)$lote['id'],
            'quantidade_consumida' => $qtdConsumida,
            'custo_unitario'       => $custoUnitario,
            'custo_total'          => $custoLinha
        ];

        $custoTotal += $custoLinha;
        $restante -= $qtdConsumida;
    }

    if ($restante > 0) {
        return [
            'ok' => false,
            'mensagem' => 'Não foi possível montar o consumo FIFO completo.'
        ];
    }

    return [
        'ok'             => true,
        'consumos'       => $consumos,
        'custo_total'    => $custoTotal,
        'custo_unitario' => $quantidade > 0 ? ($custoTotal / $quantidade) : 0.0
    ];
}

function mov_aplicar_consumo_fifo(
    mysqli $conn,
    int $movimentacao_saida_id,
    array $consumos
): void {
    $stmtUpd = $conn->prepare("
        UPDATE estoque_lotes
        SET quantidade_restante = quantidade_restante - ?
        WHERE id = ?
          AND quantidade_restante >= ?
    ");

    $stmtIns = $conn->prepare("
        INSERT INTO movimentacao_saida_lotes
            (movimentacao_saida_id, lote_id, quantidade_consumida, custo_unitario, custo_total)
        VALUES
            (?, ?, ?, ?, ?)
    ");

    if (!$stmtUpd || !$stmtIns) {
        throw new RuntimeException('Erro ao preparar consumo FIFO.');
    }

    foreach ($consumos as $item) {
        $qtd = (int)$item['quantidade_consumida'];
        $loteId = (int)$item['lote_id'];
        $custoUnit = (float)$item['custo_unitario'];
        $custoTotal = (float)$item['custo_total'];

        $stmtUpd->bind_param('iii', $qtd, $loteId, $qtd);

        if (!$stmtUpd->execute()) {
            throw new RuntimeException('Falha ao baixar saldo do lote ' . $loteId . '.');
        }

        if ($stmtUpd->affected_rows <= 0) {
            throw new RuntimeException('Falha ao baixar saldo do lote ' . $loteId . '.');
        }

        $stmtIns->bind_param(
            'iiidd',
            $movimentacao_saida_id,
            $loteId,
            $qtd,
            $custoUnit,
            $custoTotal
        );

        if (!$stmtIns->execute()) {
            throw new RuntimeException('Falha ao registrar consumo do lote ' . $loteId . '.');
        }
    }

    $stmtUpd->close();
    $stmtIns->close();
}

function mov_registrar(
    mysqli $conn,
    int $produto_id,
    string $tipo,
    int $quantidade,
    ?int $usuario_id,
    ?float $preco_custo = null,
    ?float $valor_unitario = null,
    ?string $observacao = null,
    ?int $fornecedor_id = null
): array {
    $observacao = mov_normalizar_observacao($observacao);

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

    if ($preco_custo !== null && $preco_custo < 0) {
        $preco_custo = null;
    }

    if ($valor_unitario !== null && $valor_unitario < 0) {
        $valor_unitario = null;
    }

    $conn->begin_transaction();

    try {
        $produtoAntes = mov_produto_snapshot($conn, $produto_id);
        $produto = mov_obter_produto_for_update($conn, $produto_id);

        if (!$produto) {
            $conn->rollback();
            logWarning('movimentacoes', 'Produto não encontrado', ['produto_id' => $produto_id]);
            return resposta(false, 'Produto não encontrado.');
        }

        if ((int)($produto['ativo'] ?? 1) !== 1) {
            $conn->rollback();
            return resposta(false, 'Este produto está inativo e não pode receber movimentações.');
        }

        $nomeProduto = (string)$produto['nome'];
        $estoqueAtual = (int)$produto['quantidade'];
        $precoVendaPadrao = (float)($produto['preco_venda'] ?? 0);

        $custoUnitarioMov = null;
        $custoTotalMov = null;
        $valorUnitarioMov = null;
        $valorTotalMov = null;
        $lucroMov = null;
        $consumosFIFO = [];

        if ($tipo === 'entrada') {
            if ($fornecedor_id === null || $fornecedor_id <= 0) {
                $conn->rollback();
                return resposta(false, 'Na entrada é obrigatório informar o fornecedor.');
            }

            $fornecedor = mov_fornecedor_obter_para_entrada($conn, $fornecedor_id);
            if (!$fornecedor) {
                $conn->rollback();
                return resposta(false, 'Fornecedor informado não foi encontrado.');
            }

            if ((int)($fornecedor['ativo'] ?? 1) !== 1) {
                $conn->rollback();
                return resposta(false, 'Fornecedor informado está inativo e não pode ser utilizado na entrada.');
            }

            if ($preco_custo === null || $preco_custo <= 0) {
                $conn->rollback();
                return resposta(false, 'Na entrada é obrigatório informar um preço de custo válido.');
            }

            $custoUnitarioMov = (float)$preco_custo;
            $custoTotalMov = $quantidade * $custoUnitarioMov;
            $valorUnitarioMov = $custoUnitarioMov;
            $valorTotalMov = $quantidade * $valorUnitarioMov;
            $lucroMov = null;

            $stmtUpd = $conn->prepare('UPDATE produtos SET quantidade = quantidade + ?, preco_custo = ? WHERE id = ?');
            if (!$stmtUpd) {
                throw new RuntimeException('Erro ao atualizar estoque do produto na entrada.');
            }

            $stmtUpd->bind_param('idi', $quantidade, $custoUnitarioMov, $produto_id);

            if (!$stmtUpd->execute()) {
                $stmtUpd->close();
                throw new RuntimeException('Falha ao atualizar estoque do produto na entrada.');
            }

            $stmtUpd->close();
        } else {
            if ($estoqueAtual < $quantidade) {
                $conn->rollback();
                logWarning('movimentacoes', 'Estoque insuficiente', [
                    'produto_id' => $produto_id,
                    'estoque'    => $estoqueAtual,
                    'solicitado' => $quantidade
                ]);
                return resposta(false, 'Quantidade insuficiente em estoque.');
            }

            $plano = mov_planejar_consumo_fifo($conn, $produto_id, $quantidade);
            if (!(bool)($plano['ok'] ?? false)) {
                $conn->rollback();
                return resposta(false, (string)($plano['mensagem'] ?? 'Falha ao calcular consumo FIFO.'));
            }

            $consumosFIFO = $plano['consumos'] ?? [];
            $custoTotalMov = (float)($plano['custo_total'] ?? 0);
            $custoUnitarioMov = (float)($plano['custo_unitario'] ?? 0);

            if ($tipo === 'saida') {
                if ($valor_unitario !== null && $valor_unitario > 0) {
                    $valorUnitarioMov = (float)$valor_unitario;
                } elseif ($precoVendaPadrao > 0) {
                    $valorUnitarioMov = $precoVendaPadrao;
                } else {
                    $valorUnitarioMov = null;
                }

                $valorTotalMov = $valorUnitarioMov !== null ? ($quantidade * $valorUnitarioMov) : null;
                $lucroMov = $valorTotalMov !== null ? ($valorTotalMov - $custoTotalMov) : null;
            } else {
                $valorUnitarioMov = null;
                $valorTotalMov = null;
                $lucroMov = null;
            }

            $novoSaldo = $estoqueAtual - $quantidade;

            $stmtUpd = $conn->prepare('UPDATE produtos SET quantidade = ? WHERE id = ?');
            if (!$stmtUpd) {
                throw new RuntimeException('Erro ao atualizar estoque do produto na saída/remoção.');
            }

            $stmtUpd->bind_param('ii', $novoSaldo, $produto_id);

            if (!$stmtUpd->execute()) {
                $stmtUpd->close();
                throw new RuntimeException('Falha ao atualizar estoque do produto na saída/remoção.');
            }

            $stmtUpd->close();
        }

        $movimentacaoId = mov_inserir_registro(
            $conn,
            $produto_id,
            $nomeProduto,
            $fornecedor_id,
            $tipo,
            $quantidade,
            $valorUnitarioMov,
            $custoUnitarioMov,
            $custoTotalMov,
            $valorTotalMov,
            $lucroMov,
            $usuario_id,
            $observacao
        );

        if ($tipo === 'entrada') {
            mov_criar_lote_entrada(
                $conn,
                $produto_id,
                $fornecedor_id,
                $movimentacaoId,
                $quantidade,
                (float)$custoUnitarioMov
            );
        } else {
            mov_aplicar_consumo_fifo($conn, $movimentacaoId, $consumosFIFO);

            $novoPrecoCustoReferencia = mov_obter_custo_referencia_atual($conn, $produto_id);

            $stmtRef = $conn->prepare('UPDATE produtos SET preco_custo = ? WHERE id = ?');
            if (!$stmtRef) {
                throw new RuntimeException('Erro ao atualizar custo de referência do produto após saída/remoção.');
            }

            $stmtRef->bind_param('di', $novoPrecoCustoReferencia, $produto_id);

            if (!$stmtRef->execute()) {
                $stmtRef->close();
                throw new RuntimeException('Falha ao atualizar custo de referência do produto após saída/remoção.');
            }

            $stmtRef->close();
        }

        $produtoDepois = mov_produto_snapshot($conn, $produto_id);
        $movDepois = mov_auditoria_snapshot($conn, $movimentacaoId);

        auditoria_registrar(
            $conn,
            $usuario_id,
            'registrar_movimentacao',
            'movimentacao',
            $movimentacaoId,
            [
                'produto_antes' => $produtoAntes,
            ],
            [
                'movimentacao'   => $movDepois,
                'produto_depois' => $produtoDepois,
            ]
        );

        $conn->commit();

        logInfo('movimentacoes', 'Movimentação registrada com FIFO/lote', [
            'movimentacao_id' => $movimentacaoId,
            'produto_id'      => $produto_id,
            'fornecedor_id'   => $fornecedor_id,
            'tipo'            => $tipo,
            'quantidade'      => $quantidade,
            'usuario_id'      => $usuario_id,
            'custo_unitario'  => $custoUnitarioMov,
            'custo_total'     => $custoTotalMov,
            'valor_unitario'  => $valorUnitarioMov,
            'valor_total'     => $valorTotalMov,
            'lucro'           => $lucroMov,
            'observacao'      => $observacao
        ]);

        return resposta(true, 'Movimentação registrada com sucesso.', [
            'movimentacao_id' => $movimentacaoId,
            'custo_unitario'  => $custoUnitarioMov,
            'custo_total'     => $custoTotalMov,
            'valor_unitario'  => $valorUnitarioMov,
            'valor_total'     => $valorTotalMov,
            'lucro'           => $lucroMov
        ]);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }

        logError('movimentacoes', 'Erro ao registrar movimentação', [
            'arquivo'       => $e->getFile(),
            'linha'         => $e->getLine(),
            'erro'          => $e->getMessage(),
            'produto_id'    => $produto_id,
            'fornecedor_id' => $fornecedor_id,
            'tipo'          => $tipo,
            'quantidade'    => $quantidade,
            'usuario_id'    => $usuario_id
        ]);

        return resposta(false, 'Erro ao registrar movimentação.', null);
    }
}

function mov_resumo_filtrado(mysqli $conn, string $whereSql, string $types, array $params): array
{
    $resumo = [
        'total_registros'    => 0,
        'quantidade_total'   => 0,
        'custo_total'        => 0.0,
        'valor_total'        => 0.0,
        'lucro_total'        => 0.0,
        'quantidade_entrada' => 0,
        'quantidade_saida'   => 0,
        'quantidade_remocao' => 0,
    ];

    $sqlResumoBase = "
        SELECT
            COUNT(*) AS total_registros,
            COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.quantidade ELSE 0 END), 0) AS quantidade_entrada,
            COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN m.quantidade ELSE 0 END), 0) AS quantidade_saida,
            COALESCE(SUM(CASE WHEN m.tipo = 'remocao' THEN m.quantidade ELSE 0 END), 0) AS quantidade_remocao,
            COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN COALESCE(m.custo_total, 0) ELSE 0 END), 0) AS custo_total,
            COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN COALESCE(m.valor_total, 0) ELSE 0 END), 0) AS valor_total,
            COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN COALESCE(m.lucro, 0) ELSE 0 END), 0) AS lucro_total
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        $whereSql
    ";

    $stmtResumoBase = $conn->prepare($sqlResumoBase);
    if (!$stmtResumoBase) {
        throw new RuntimeException('Erro ao calcular resumo das movimentações.');
    }

    if ($types !== '') {
        $stmtResumoBase->bind_param($types, ...$params);
    }

    $stmtResumoBase->execute();
    $rowBase = $stmtResumoBase->get_result()->fetch_assoc();
    $stmtResumoBase->close();

    $resumo['total_registros']    = (int)($rowBase['total_registros'] ?? 0);
    $resumo['custo_total']        = (float)($rowBase['custo_total'] ?? 0);
    $resumo['valor_total']        = (float)($rowBase['valor_total'] ?? 0);
    $resumo['lucro_total']        = (float)($rowBase['lucro_total'] ?? 0);
    $resumo['quantidade_entrada'] = (int)($rowBase['quantidade_entrada'] ?? 0);
    $resumo['quantidade_saida']   = (int)($rowBase['quantidade_saida'] ?? 0);
    $resumo['quantidade_remocao'] = (int)($rowBase['quantidade_remocao'] ?? 0);

    $sqlQtdEstoque = "
        SELECT COALESCE(SUM(produtos_filtrados.quantidade_atual), 0) AS quantidade_total
        FROM (
            SELECT DISTINCT
                p.id,
                COALESCE(p.quantidade, 0) AS quantidade_atual
            FROM movimentacoes m
            INNER JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSql
        ) AS produtos_filtrados
    ";

    $stmtQtdEstoque = $conn->prepare($sqlQtdEstoque);
    if (!$stmtQtdEstoque) {
        throw new RuntimeException('Erro ao calcular quantidade total em estoque.');
    }

    if ($types !== '') {
        $stmtQtdEstoque->bind_param($types, ...$params);
    }

    $stmtQtdEstoque->execute();
    $rowQtd = $stmtQtdEstoque->get_result()->fetch_assoc();
    $stmtQtdEstoque->close();

    $resumo['quantidade_total'] = (int)($rowQtd['quantidade_total'] ?? 0);

    return $resumo;
}

function mov_listar(mysqli $conn, array $f): array
{
    $pagina = max(1, (int)($f['pagina'] ?? 1));
    $limite = max(1, min(5000, (int)($f['limite'] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where  = [];
    $params = [];
    $types  = '';

    $produtoId  = (int)($f['produto_id'] ?? 0);
    $produto    = mov_normalizar_texto((string)($f['produto'] ?? ''));
    $tipo       = mov_normalizar_texto((string)($f['tipo'] ?? ''));
    $dataInicio = mov_normalizar_texto((string)($f['data_inicio'] ?? ''));
    $dataFim    = mov_normalizar_texto((string)($f['data_fim'] ?? ''));
    $usuarioId  = (int)($f['usuario_id'] ?? 0);
    $usuario    = mov_normalizar_texto((string)($f['usuario'] ?? ''));

    if ($produtoId > 0) {
        $where[]  = 'm.produto_id = ?';
        $params[] = $produtoId;
        $types   .= 'i';
    }

    if ($produto !== '') {
        $termo = '%' . $produto . '%';
        $where[] = '(COALESCE(m.produto_nome, p.nome, "") LIKE ?)';
        $params[] = $termo;
        $types .= 's';
    }

    if (!empty($f['fornecedor_id'])) {
        $where[]  = 'm.fornecedor_id = ?';
        $params[] = (int)$f['fornecedor_id'];
        $types   .= 'i';
    }

    if ($tipo !== '' && in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
        $where[]  = 'm.tipo = ?';
        $params[] = $tipo;
        $types   .= 's';
    }

    if ($usuarioId > 0) {
        $where[]  = 'm.usuario_id = ?';
        $params[] = $usuarioId;
        $types   .= 'i';
    }

    if ($usuario !== '') {
        $termoUsuario = '%' . $usuario . '%';
        $where[]  = 'COALESCE(u.nome, "Sistema") LIKE ?';
        $params[] = $termoUsuario;
        $types   .= 's';
    }

    if ($dataInicio !== '') {
        if (!mov_data_valida($dataInicio)) {
            return resposta(false, 'Data inicial inválida.', []);
        }

        $where[]  = 'm.data >= ?';
        $params[] = $dataInicio . ' 00:00:00';
        $types   .= 's';
    }

    if ($dataFim !== '') {
        if (!mov_data_valida($dataFim)) {
            return resposta(false, 'Data final inválida.', []);
        }

        $where[]  = 'm.data <= ?';
        $params[] = $dataFim . ' 23:59:59';
        $types   .= 's';
    }

    if ($dataInicio !== '' && $dataFim !== '' && strcmp($dataInicio, $dataFim) > 0) {
        return resposta(false, 'A data inicial não pode ser maior que a data final.', []);
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    try {
        $sqlCount = "
            SELECT COUNT(*) AS total
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSql
        ";

        $stmtT = $conn->prepare($sqlCount);
        if (!$stmtT) {
            return resposta(false, 'Erro ao listar movimentações.', []);
        }

        if ($types !== '') {
            $stmtT->bind_param($types, ...$params);
        }

        $stmtT->execute();
        $total = (int)($stmtT->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtT->close();

        $resumo = mov_resumo_filtrado($conn, $whereSql, $types, $params);

        $sql = "
            SELECT
                m.id,
                COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                m.produto_id,
                m.fornecedor_id,
                COALESCE(f.nome, '') AS fornecedor_nome,
                m.tipo,
                m.quantidade,
                m.valor_unitario,
                m.custo_unitario,
                m.custo_total,
                m.valor_total,
                m.lucro,
                m.observacao,
                m.data,
                m.usuario_id,
                COALESCE(u.nome, 'Sistema') AS usuario
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSql
            ORDER BY m.data DESC, m.id DESC
            LIMIT ? OFFSET ?
        ";

        $paramsPage = $params;
        $typesPage = $types . 'ii';
        $paramsPage[] = $limite;
        $paramsPage[] = $offset;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return resposta(false, 'Erro ao listar movimentações.', []);
        }

        $stmt->bind_param($typesPage, ...$paramsPage);
        $stmt->execute();

        $res = $stmt->get_result();
        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $dados[] = [
                'id'              => (int)$row['id'],
                'produto_id'      => $row['produto_id'] !== null ? (int)$row['produto_id'] : null,
                'produto_nome'    => (string)$row['produto_nome'],
                'fornecedor_id'   => $row['fornecedor_id'] !== null ? (int)$row['fornecedor_id'] : null,
                'fornecedor_nome' => (string)$row['fornecedor_nome'],
                'tipo'            => (string)$row['tipo'],
                'quantidade'      => (int)$row['quantidade'],
                'valor_unitario'  => $row['valor_unitario'] !== null ? (float)$row['valor_unitario'] : null,
                'custo_unitario'  => $row['custo_unitario'] !== null ? (float)$row['custo_unitario'] : null,
                'custo_total'     => $row['custo_total'] !== null ? (float)$row['custo_total'] : null,
                'valor_total'     => $row['valor_total'] !== null ? (float)$row['valor_total'] : null,
                'lucro'           => $row['lucro'] !== null ? (float)$row['lucro'] : null,
                'observacao'      => (string)($row['observacao'] ?? ''),
                'data'            => (string)$row['data'],
                'usuario_id'      => $row['usuario_id'] !== null ? (int)$row['usuario_id'] : null,
                'usuario'         => (string)$row['usuario']
            ];
        }

        $stmt->close();

        return resposta(true, '', [
            'total'   => $total,
            'pagina'  => $pagina,
            'limite'  => $limite,
            'paginas' => $limite > 0 ? (int)ceil($total / $limite) : 0,
            'resumo'  => $resumo,
            'dados'   => $dados
        ]);
    } catch (Throwable $e) {
        logError('movimentacoes', 'Erro ao listar movimentações', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return resposta(false, 'Erro ao listar movimentações.', []);
    }
}

function mov_obter(mysqli $conn, int $movimentacaoId): array
{
    if ($movimentacaoId <= 0) {
        return resposta(false, 'Movimentação inválida.');
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                m.id,
                m.produto_id,
                COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                m.fornecedor_id,
                COALESCE(f.nome, '') AS fornecedor_nome,
                m.tipo,
                m.quantidade,
                m.valor_unitario,
                m.custo_unitario,
                m.custo_total,
                m.valor_total,
                m.lucro,
                m.observacao,
                m.data,
                m.usuario_id,
                COALESCE(u.nome, 'Sistema') AS usuario
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return resposta(false, 'Erro ao obter movimentação.');
        }

        $stmt->bind_param('i', $movimentacaoId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return resposta(false, 'Movimentação não encontrada.');
        }

        $dados = [
            'id'              => (int)$row['id'],
            'produto_id'      => $row['produto_id'] !== null ? (int)$row['produto_id'] : null,
            'produto_nome'    => (string)$row['produto_nome'],
            'fornecedor_id'   => $row['fornecedor_id'] !== null ? (int)$row['fornecedor_id'] : null,
            'fornecedor_nome' => (string)$row['fornecedor_nome'],
            'tipo'            => (string)$row['tipo'],
            'quantidade'      => (int)$row['quantidade'],
            'valor_unitario'  => $row['valor_unitario'] !== null ? (float)$row['valor_unitario'] : null,
            'custo_unitario'  => $row['custo_unitario'] !== null ? (float)$row['custo_unitario'] : null,
            'custo_total'     => $row['custo_total'] !== null ? (float)$row['custo_total'] : null,
            'valor_total'     => $row['valor_total'] !== null ? (float)$row['valor_total'] : null,
            'lucro'           => $row['lucro'] !== null ? (float)$row['lucro'] : null,
            'observacao'      => (string)($row['observacao'] ?? ''),
            'data'            => (string)$row['data'],
            'usuario_id'      => $row['usuario_id'] !== null ? (int)$row['usuario_id'] : null,
            'usuario'         => (string)$row['usuario'],
            'lotes'           => []
        ];

        $stmtLotes = $conn->prepare("
            SELECT
                msl.lote_id,
                msl.quantidade_consumida,
                msl.custo_unitario,
                msl.custo_total,
                el.fornecedor_id,
                COALESCE(f.nome, '') AS fornecedor_nome,
                el.quantidade_entrada,
                el.criado_em AS lote_criado_em
            FROM movimentacao_saida_lotes msl
            INNER JOIN estoque_lotes el ON el.id = msl.lote_id
            LEFT JOIN fornecedores f ON f.id = el.fornecedor_id
            WHERE msl.movimentacao_saida_id = ?
            ORDER BY msl.id ASC
        ");

        if ($stmtLotes) {
            $stmtLotes->bind_param('i', $movimentacaoId);
            $stmtLotes->execute();
            $resLotes = $stmtLotes->get_result();

            while ($l = $resLotes->fetch_assoc()) {
                $dados['lotes'][] = [
                    'lote_id'              => (int)$l['lote_id'],
                    'quantidade_consumida' => (int)$l['quantidade_consumida'],
                    'custo_unitario'       => (float)$l['custo_unitario'],
                    'custo_total'          => (float)$l['custo_total'],
                    'fornecedor_id'        => $l['fornecedor_id'] !== null ? (int)$l['fornecedor_id'] : null,
                    'fornecedor_nome'      => (string)($l['fornecedor_nome'] ?? ''),
                    'quantidade_entrada'   => (int)($l['quantidade_entrada'] ?? 0),
                    'lote_criado_em'       => (string)($l['lote_criado_em'] ?? '')
                ];
            }

            $stmtLotes->close();
        }

        return resposta(true, '', $dados);
    } catch (Throwable $e) {
        logError('movimentacoes', 'Erro ao obter movimentação', [
            'arquivo'         => $e->getFile(),
            'linha'           => $e->getLine(),
            'erro'            => $e->getMessage(),
            'movimentacao_id' => $movimentacaoId
        ]);

        return resposta(false, 'Erro ao obter movimentação.');
    }
}   