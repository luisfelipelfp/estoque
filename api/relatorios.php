<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

date_default_timezone_set('America/Sao_Paulo');
initLog('relatorios');

function removeAcentos(string $s): string
{
    return str_replace(
        ['á','à','ã','â','ä','é','ê','ë','í','î','ï','ó','ô','õ','ö','ú','û','ü','ç',
         'Á','À','Ã','Â','Ä','É','Ê','Ë','Í','Î','Ï','Ó','Ô','Õ','Ö','Ú','Û','Ü','Ç'],
        ['a','a','a','a','a','e','e','e','i','i','i','o','o','o','o','u','u','u','c',
         'A','A','A','A','A','E','E','E','I','I','I','O','O','O','O','U','U','U','C'],
        $s
    );
}

function normalizaTipoMov(?string $tipo): string
{
    $t = strtolower(trim((string)$tipo));
    $t = removeAcentos($t);
    $t = str_replace(['-', '_', '.', '/', '\\'], ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t) ?: $t;

    if ($t === 'e') return 'entrada';
    if ($t === 's') return 'saida';
    if ($t === 'r') return 'remocao';

    if ($t === 'entrada' || $t === 'saida' || $t === 'remocao') return $t;
    if ($t === 'entradas') return 'entrada';
    if ($t === 'saidas') return 'saida';
    if ($t === 'remocoes') return 'remocao';

    if (str_contains($t, 'entr')) return 'entrada';

    if (
        str_contains($t, 'sai') ||
        str_contains($t, 'retir') ||
        str_contains($t, 'consum') ||
        str_contains($t, 'vend') ||
        str_contains($t, 'baixa')
    ) {
        return 'saida';
    }

    if (
        str_contains($t, 'remo') ||
        str_contains($t, 'excl') ||
        str_contains($t, 'apag') ||
        str_contains($t, 'delet') ||
        str_contains($t, 'delete')
    ) {
        return 'remocao';
    }

    return '';
}

function relatorio_estoque_atual(mysqli $conn): array
{
    try {
        $sql = "
            SELECT
                p.id,
                p.nome,
                COALESCE(p.quantidade, 0) AS quantidade,
                COALESCE(p.preco_custo, 0) AS preco_custo,
                COALESCE(p.preco_venda, 0) AS preco_venda,
                (COALESCE(p.quantidade, 0) * COALESCE(p.preco_custo, 0)) AS valor_custo_estimado,
                (COALESCE(p.quantidade, 0) * COALESCE(p.preco_venda, 0)) AS valor_venda_estimado
            FROM produtos p
            ORDER BY p.nome ASC
        ";

        $res = $conn->query($sql);

        $itens = [];
        $totalQtd = 0;
        $totalCusto = 0.0;
        $totalVenda = 0.0;

        while ($row = $res->fetch_assoc()) {
            $quantidade = (int)($row['quantidade'] ?? 0);
            $precoCusto = (float)($row['preco_custo'] ?? 0);
            $precoVenda = (float)($row['preco_venda'] ?? 0);
            $valorCusto = (float)($row['valor_custo_estimado'] ?? 0);
            $valorVenda = (float)($row['valor_venda_estimado'] ?? 0);

            $itens[] = [
                'id'                   => (int)$row['id'],
                'nome'                 => (string)$row['nome'],
                'quantidade'           => $quantidade,
                'preco_custo'          => $precoCusto,
                'preco_venda'          => $precoVenda,
                'valor_custo_estimado' => $valorCusto,
                'valor_venda_estimado' => $valorVenda,
            ];

            $totalQtd += $quantidade;
            $totalCusto += $valorCusto;
            $totalVenda += $valorVenda;
        }

        return resposta(true, 'Estoque atual gerado com sucesso.', [
            'itens' => $itens,
            'totais' => [
                'total_qtd'   => $totalQtd,
                'total_custo' => $totalCusto,
                'total_venda' => $totalVenda,
            ],
        ]);
    } catch (Throwable $e) {
        logError('relatorios', 'Erro ao gerar estoque atual', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage(),
        ]);

        return resposta(false, 'Erro interno ao gerar estoque atual.', null);
    }
}

function relatorio(mysqli $conn, array $filtros): array
{
    $pagina = max(1, (int)($filtros['pagina'] ?? 1));
    $limite = max(1, min(5000, (int)($filtros['limite'] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where  = [];
    $params = [];
    $types  = '';

    if (!empty($filtros['tipo']) && in_array($filtros['tipo'], ['entrada', 'saida', 'remocao'], true)) {
        $where[]  = 'm.tipo = ?';
        $params[] = (string)$filtros['tipo'];
        $types   .= 's';
    }

    if (!empty($filtros['produto_id'])) {
        $where[]  = 'm.produto_id = ?';
        $params[] = (int)$filtros['produto_id'];
        $types   .= 'i';
    }

    if (!empty($filtros['produto'])) {
        $where[]  = 'COALESCE(m.produto_nome, p.nome, "") LIKE ?';
        $params[] = '%' . trim((string)$filtros['produto']) . '%';
        $types   .= 's';
    }

    if (!empty($filtros['fornecedor_id'])) {
        $where[]  = 'm.fornecedor_id = ?';
        $params[] = (int)$filtros['fornecedor_id'];
        $types   .= 'i';
    }

    if (!empty($filtros['usuario_id'])) {
        $where[]  = 'm.usuario_id = ?';
        $params[] = (int)$filtros['usuario_id'];
        $types   .= 'i';
    }

    if (!empty($filtros['data_inicio'])) {
        $where[]  = 'm.data >= ?';
        $params[] = (string)$filtros['data_inicio'] . ' 00:00:00';
        $types   .= 's';
    }

    if (!empty($filtros['data_fim'])) {
        $where[]  = 'm.data <= ?';
        $params[] = (string)$filtros['data_fim'] . ' 23:59:59';
        $types   .= 's';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    try {
        $sqlBaseFrom = "
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSql
        ";

        $stmtCount = $conn->prepare("SELECT COUNT(*) AS total $sqlBaseFrom");
        if ($types !== '') {
            $stmtCount->bind_param($types, ...$params);
        }
        $stmtCount->execute();
        $total = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtCount->close();

        $stmtTot = $conn->prepare("
            SELECT
                COALESCE(SUM(m.quantidade), 0) AS total_qtd,
                COALESCE(SUM(m.custo_total), 0) AS total_custo,
                COALESCE(SUM(m.valor_total), 0) AS total_valor,
                COALESCE(SUM(m.lucro), 0) AS total_lucro,

                COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.quantidade ELSE 0 END), 0) AS entrada_qtd,
                COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.custo_total ELSE 0 END), 0) AS entrada_custo,
                COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.valor_total ELSE 0 END), 0) AS entrada_valor,

                COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN m.quantidade ELSE 0 END), 0) AS saida_qtd,
                COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN m.custo_total ELSE 0 END), 0) AS saida_custo,
                COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN m.valor_total ELSE 0 END), 0) AS saida_valor,
                COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN m.lucro ELSE 0 END), 0) AS saida_lucro,

                COALESCE(SUM(CASE WHEN m.tipo = 'remocao' THEN m.quantidade ELSE 0 END), 0) AS remocao_qtd,
                COALESCE(SUM(CASE WHEN m.tipo = 'remocao' THEN m.custo_total ELSE 0 END), 0) AS remocao_custo
            $sqlBaseFrom
        ");
        if ($types !== '') {
            $stmtTot->bind_param($types, ...$params);
        }
        $stmtTot->execute();
        $totRow = $stmtTot->get_result()->fetch_assoc() ?: [];
        $stmtTot->close();

        $totais = [
            'total_qtd'     => (int)($totRow['total_qtd'] ?? 0),
            'total_custo'   => (float)($totRow['total_custo'] ?? 0),
            'total_valor'   => (float)($totRow['total_valor'] ?? 0),
            'total_lucro'   => (float)($totRow['total_lucro'] ?? 0),

            'entrada_qtd'   => (int)($totRow['entrada_qtd'] ?? 0),
            'entrada_custo' => (float)($totRow['entrada_custo'] ?? 0),
            'entrada_valor' => (float)($totRow['entrada_valor'] ?? 0),

            'saida_qtd'     => (int)($totRow['saida_qtd'] ?? 0),
            'saida_custo'   => (float)($totRow['saida_custo'] ?? 0),
            'saida_valor'   => (float)($totRow['saida_valor'] ?? 0),
            'saida_lucro'   => (float)($totRow['saida_lucro'] ?? 0),

            'remocao_qtd'   => (int)($totRow['remocao_qtd'] ?? 0),
            'remocao_custo' => (float)($totRow['remocao_custo'] ?? 0),
        ];

        $sqlDados = "
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
                COALESCE(u.nome, 'Sistema') AS usuario
            $sqlBaseFrom
            ORDER BY m.data DESC, m.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmtDados = $conn->prepare($sqlDados);
        $paramsPage = $params;
        $typesPage  = $types . 'ii';
        $paramsPage[] = $limite;
        $paramsPage[] = $offset;

        $stmtDados->bind_param($typesPage, ...$paramsPage);
        $stmtDados->execute();

        $resDados = $stmtDados->get_result();
        $dados = [];

        while ($r = $resDados->fetch_assoc()) {
            $dados[] = [
                'id'              => (int)$r['id'],
                'produto_id'      => $r['produto_id'] !== null ? (int)$r['produto_id'] : null,
                'produto_nome'    => (string)$r['produto_nome'],
                'fornecedor_id'   => $r['fornecedor_id'] !== null ? (int)$r['fornecedor_id'] : null,
                'fornecedor_nome' => (string)($r['fornecedor_nome'] ?? ''),
                'tipo'            => (string)$r['tipo'],
                'quantidade'      => (int)$r['quantidade'],
                'valor_unitario'  => $r['valor_unitario'] !== null ? (float)$r['valor_unitario'] : null,
                'custo_unitario'  => $r['custo_unitario'] !== null ? (float)$r['custo_unitario'] : null,
                'custo_total'     => $r['custo_total'] !== null ? (float)$r['custo_total'] : null,
                'valor_total'     => $r['valor_total'] !== null ? (float)$r['valor_total'] : null,
                'lucro'           => $r['lucro'] !== null ? (float)$r['lucro'] : null,
                'observacao'      => (string)($r['observacao'] ?? ''),
                'data'            => (string)$r['data'],
                'usuario'         => (string)$r['usuario']
            ];
        }
        $stmtDados->close();

        $stmtGraf = $conn->prepare("
            SELECT
                DATE(m.data) AS dia,
                COALESCE(SUM(m.quantidade), 0) AS quantidade,
                COALESCE(SUM(m.custo_total), 0) AS custo_total,
                COALESCE(SUM(m.valor_total), 0) AS valor_total,
                COALESCE(SUM(m.lucro), 0) AS lucro
            $sqlBaseFrom
            GROUP BY DATE(m.data)
            ORDER BY dia ASC
        ");
        if ($types !== '') {
            $stmtGraf->bind_param($types, ...$params);
        }
        $stmtGraf->execute();
        $resGraf = $stmtGraf->get_result();

        $graficoLabels = [];
        $graficoQuantidade = [];
        $graficoCusto = [];
        $graficoValor = [];
        $graficoLucro = [];

        while ($row = $resGraf->fetch_assoc()) {
            $graficoLabels[]     = (string)$row['dia'];
            $graficoQuantidade[] = (int)($row['quantidade'] ?? 0);
            $graficoCusto[]      = (float)($row['custo_total'] ?? 0);
            $graficoValor[]      = (float)($row['valor_total'] ?? 0);
            $graficoLucro[]      = (float)($row['lucro'] ?? 0);
        }
        $stmtGraf->close();

        $grafico_temporal = [
            'labels'      => $graficoLabels,
            'quantidade'  => $graficoQuantidade,
            'custo_total' => $graficoCusto,
            'valor_total' => $graficoValor,
            'lucro'       => $graficoLucro,
        ];

        $stmtRankProdutosQtd = $conn->prepare("
            SELECT
                COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                COALESCE(SUM(m.valor_total), 0) AS valor_total,
                COALESCE(SUM(m.lucro), 0) AS lucro_total
            $sqlBaseFrom
            AND m.tipo = 'saida'
            GROUP BY COALESCE(m.produto_nome, p.nome, '[Produto removido]')
            ORDER BY quantidade_total DESC, produto_nome ASC
            LIMIT 10
        ");

        if ($types !== '') {
            $typesRank = $types . 's';
            $paramsRank = $params;
            $paramsRank[] = 'saida';
            $stmtRankProdutosQtd = $conn->prepare("
                SELECT
                    COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                    COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                    COALESCE(SUM(m.valor_total), 0) AS valor_total,
                    COALESCE(SUM(m.lucro), 0) AS lucro_total
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
                LEFT JOIN usuarios u ON u.id = m.usuario_id
                $whereSql " . ($whereSql ? "AND" : "WHERE") . " m.tipo = ?
                GROUP BY COALESCE(m.produto_nome, p.nome, '[Produto removido]')
                ORDER BY quantidade_total DESC, produto_nome ASC
                LIMIT 10
            ");
            $stmtRankProdutosQtd->bind_param($typesRank, ...$paramsRank);
        } else {
            $stmtRankProdutosQtd = $conn->prepare("
                SELECT
                    COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                    COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                    COALESCE(SUM(m.valor_total), 0) AS valor_total,
                    COALESCE(SUM(m.lucro), 0) AS lucro_total
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
                LEFT JOIN usuarios u ON u.id = m.usuario_id
                WHERE m.tipo = 'saida'
                GROUP BY COALESCE(m.produto_nome, p.nome, '[Produto removido]')
                ORDER BY quantidade_total DESC, produto_nome ASC
                LIMIT 10
            ");
        }

        $stmtRankProdutosQtd->execute();
        $resRankProdutosQtd = $stmtRankProdutosQtd->get_result();
        $ranking_produtos_qtd = [];

        while ($row = $resRankProdutosQtd->fetch_assoc()) {
            $ranking_produtos_qtd[] = [
                'produto_nome'     => (string)$row['produto_nome'],
                'quantidade_total' => (int)($row['quantidade_total'] ?? 0),
                'valor_total'      => (float)($row['valor_total'] ?? 0),
                'lucro_total'      => (float)($row['lucro_total'] ?? 0),
            ];
        }
        $stmtRankProdutosQtd->close();

        if ($types !== '') {
            $typesRank = $types . 's';
            $paramsRank = $params;
            $paramsRank[] = 'saida';
            $stmtRankLucro = $conn->prepare("
                SELECT
                    COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                    COALESCE(SUM(m.lucro), 0) AS lucro_total,
                    COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                    COALESCE(SUM(m.valor_total), 0) AS valor_total
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
                LEFT JOIN usuarios u ON u.id = m.usuario_id
                $whereSql " . ($whereSql ? "AND" : "WHERE") . " m.tipo = ?
                GROUP BY COALESCE(m.produto_nome, p.nome, '[Produto removido]')
                ORDER BY lucro_total DESC, produto_nome ASC
                LIMIT 10
            ");
            $stmtRankLucro->bind_param($typesRank, ...$paramsRank);
        } else {
            $stmtRankLucro = $conn->prepare("
                SELECT
                    COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                    COALESCE(SUM(m.lucro), 0) AS lucro_total,
                    COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                    COALESCE(SUM(m.valor_total), 0) AS valor_total
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
                LEFT JOIN usuarios u ON u.id = m.usuario_id
                WHERE m.tipo = 'saida'
                GROUP BY COALESCE(m.produto_nome, p.nome, '[Produto removido]')
                ORDER BY lucro_total DESC, produto_nome ASC
                LIMIT 10
            ");
        }

        $stmtRankLucro->execute();
        $resRankLucro = $stmtRankLucro->get_result();
        $ranking_produtos_lucro = [];

        while ($row = $resRankLucro->fetch_assoc()) {
            $ranking_produtos_lucro[] = [
                'produto_nome'     => (string)$row['produto_nome'],
                'lucro_total'      => (float)($row['lucro_total'] ?? 0),
                'quantidade_total' => (int)($row['quantidade_total'] ?? 0),
                'valor_total'      => (float)($row['valor_total'] ?? 0),
            ];
        }
        $stmtRankLucro->close();

        if ($types !== '') {
            $stmtRankFornecedor = $conn->prepare("
                SELECT
                    COALESCE(f.nome, '[Sem fornecedor]') AS fornecedor_nome,
                    COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                    COALESCE(SUM(m.custo_total), 0) AS custo_total,
                    COUNT(*) AS total_movimentacoes
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
                LEFT JOIN usuarios u ON u.id = m.usuario_id
                $whereSql
                GROUP BY COALESCE(f.nome, '[Sem fornecedor]')
                ORDER BY quantidade_total DESC, fornecedor_nome ASC
                LIMIT 10
            ");
            $stmtRankFornecedor->bind_param($types, ...$params);
        } else {
            $stmtRankFornecedor = $conn->prepare("
                SELECT
                    COALESCE(f.nome, '[Sem fornecedor]') AS fornecedor_nome,
                    COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                    COALESCE(SUM(m.custo_total), 0) AS custo_total,
                    COUNT(*) AS total_movimentacoes
                FROM movimentacoes m
                LEFT JOIN produtos p ON p.id = m.produto_id
                LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
                LEFT JOIN usuarios u ON u.id = m.usuario_id
                GROUP BY COALESCE(f.nome, '[Sem fornecedor]')
                ORDER BY quantidade_total DESC, fornecedor_nome ASC
                LIMIT 10
            ");
        }

        $stmtRankFornecedor->execute();
        $resRankFornecedor = $stmtRankFornecedor->get_result();
        $ranking_fornecedores = [];

        while ($row = $resRankFornecedor->fetch_assoc()) {
            $ranking_fornecedores[] = [
                'fornecedor_nome'    => (string)$row['fornecedor_nome'],
                'quantidade_total'   => (int)($row['quantidade_total'] ?? 0),
                'custo_total'        => (float)($row['custo_total'] ?? 0),
                'total_movimentacoes'=> (int)($row['total_movimentacoes'] ?? 0),
            ];
        }
        $stmtRankFornecedor->close();

        logInfo('relatorios', 'Relatório gerado', [
            'total'  => $total,
            'pagina' => $pagina,
            'limite' => $limite
        ]);

        return resposta(true, 'Relatório gerado com sucesso.', [
            'total'   => $total,
            'pagina'  => $pagina,
            'limite'  => $limite,
            'paginas' => (int)ceil($total / $limite),
            'dados'   => $dados,
            'totais'  => $totais,
            'grafico_temporal'      => $grafico_temporal,
            'ranking_produtos_qtd'  => $ranking_produtos_qtd,
            'ranking_produtos_lucro'=> $ranking_produtos_lucro,
            'ranking_fornecedores'  => $ranking_fornecedores,
            'cards' => [
                'entradas' => [
                    'quantidade' => $totais['entrada_qtd'],
                    'custo'      => $totais['entrada_custo'],
                    'valor'      => $totais['entrada_valor'],
                ],
                'saidas' => [
                    'quantidade' => $totais['saida_qtd'],
                    'custo'      => $totais['saida_custo'],
                    'valor'      => $totais['saida_valor'],
                    'lucro'      => $totais['saida_lucro'],
                ],
                'remocoes' => [
                    'quantidade' => $totais['remocao_qtd'],
                    'custo'      => $totais['remocao_custo'],
                ]
            ]
        ]);
    } catch (Throwable $e) {
        logError('relatorios', 'Erro ao gerar relatório', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return resposta(false, 'Erro interno ao gerar relatório.');
    }
}