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

/**
 * Estoque atual
 * Retorna lista de produtos com quantidade, preço de custo e valor estimado.
 */
function relatorio_estoque_atual(mysqli $conn): array
{
    try {
        $sql = "
            SELECT
                p.id,
                p.nome,
                COALESCE(p.quantidade, 0) AS quantidade,
                COALESCE(p.preco_custo, 0) AS preco_custo,
                (COALESCE(p.quantidade, 0) * COALESCE(p.preco_custo, 0)) AS valor_estimado
            FROM produtos p
            ORDER BY p.nome ASC
        ";

        $res = $conn->query($sql);

        $itens = [];
        $totalQtd = 0;
        $totalValor = 0.0;

        while ($row = $res->fetch_assoc()) {
            $quantidade = (int)($row['quantidade'] ?? 0);
            $precoCusto = (float)($row['preco_custo'] ?? 0);
            $valorEstimado = (float)($row['valor_estimado'] ?? 0);

            $itens[] = [
                'id'            => (int)$row['id'],
                'nome'          => (string)$row['nome'],
                'quantidade'    => $quantidade,
                'preco_custo'   => $precoCusto,
                'valor_estimado'=> $valorEstimado,
            ];

            $totalQtd += $quantidade;
            $totalValor += $valorEstimado;
        }

        return resposta(true, 'Estoque atual gerado com sucesso.', [
            'itens' => $itens,
            'totais' => [
                'total_qtd' => $totalQtd,
                'total_valor' => $totalValor,
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
    $limite = max(1, min(200, (int)($filtros['limite'] ?? 50)));
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
        $stmtT = $conn->prepare("SELECT COUNT(*) AS total FROM movimentacoes m $whereSql");
        if ($types !== '') {
            $stmtT->bind_param($types, ...$params);
        }
        $stmtT->execute();
        $total = (int)($stmtT->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtT->close();

        $stmtTot = $conn->prepare(
            "SELECT
                COALESCE(SUM(m.quantidade), 0) AS total_qtd,
                COALESCE(SUM(m.quantidade * COALESCE(m.valor_unitario,0)), 0) AS total_valor
             FROM movimentacoes m
             $whereSql"
        );
        if ($types !== '') {
            $stmtTot->bind_param($types, ...$params);
        }
        $stmtTot->execute();
        $totRow = $stmtTot->get_result()->fetch_assoc() ?: ['total_qtd' => 0, 'total_valor' => 0];
        $stmtTot->close();

        $total_qtd   = (int)($totRow['total_qtd'] ?? 0);
        $total_valor = (float)($totRow['total_valor'] ?? 0);

        $sql = "
            SELECT
                m.id,
                COALESCE(p.nome, m.produto_nome, '[Produto removido]') AS produto_nome,
                m.tipo,
                m.quantidade,
                m.valor_unitario,
                (m.quantidade * COALESCE(m.valor_unitario,0)) AS valor_total,
                m.data,
                COALESCE(u.nome, 'Sistema') AS usuario
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSql
            ORDER BY m.data DESC, m.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $conn->prepare($sql);
        $paramsPage = $params;
        $typesPage = $types . 'ii';
        $paramsPage[] = $limite;
        $paramsPage[] = $offset;
        $stmt->bind_param($typesPage, ...$paramsPage);
        $stmt->execute();

        $res = $stmt->get_result();
        $dados = [];

        while ($r = $res->fetch_assoc()) {
            $dados[] = [
                'id'             => (int)$r['id'],
                'produto_nome'   => (string)$r['produto_nome'],
                'tipo'           => (string)$r['tipo'],
                'quantidade'     => (int)$r['quantidade'],
                'valor_unitario' => $r['valor_unitario'] !== null ? (float)$r['valor_unitario'] : null,
                'valor_total'    => (float)$r['valor_total'],
                'data'           => date('d/m/Y H:i', strtotime((string)$r['data'])),
                'usuario'        => (string)$r['usuario']
            ];
        }
        $stmt->close();

        $stmtGT = $conn->prepare(
            "SELECT
                DATE(m.data) AS dia,
                m.tipo AS tipo_raw,
                COALESCE(SUM(m.quantidade),0) AS qtd
             FROM movimentacoes m
             $whereSql
             GROUP BY DATE(m.data), m.tipo
             ORDER BY dia ASC"
        );
        if ($types !== '') {
            $stmtGT->bind_param($types, ...$params);
        }
        $stmtGT->execute();
        $resGT = $stmtGT->get_result();

        $map = [];
        $tiposDesconhecidos = 0;

        while ($row = $resGT->fetch_assoc()) {
            $dia     = (string)$row['dia'];
            $tipoRaw = (string)$row['tipo_raw'];
            $qtd     = (int)$row['qtd'];

            $tipo = normalizaTipoMov($tipoRaw);

            if (!isset($map[$dia])) {
                $map[$dia] = ['entrada' => 0, 'saida' => 0, 'remocao' => 0, 'outros' => 0];
            }

            if ($tipo !== '') {
                $map[$dia][$tipo] += $qtd;
            } else {
                $map[$dia]['outros'] += $qtd;
                $tiposDesconhecidos++;
            }
        }
        $stmtGT->close();

        $labels  = array_keys($map);
        $entrada = [];
        $saida   = [];
        $remocao = [];
        $outros  = [];

        foreach ($labels as $d) {
            $entrada[] = (int)($map[$d]['entrada'] ?? 0);
            $saida[]   = (int)($map[$d]['saida'] ?? 0);
            $remocao[] = (int)($map[$d]['remocao'] ?? 0);
            $outros[]  = (int)($map[$d]['outros'] ?? 0);
        }

        $grafico_temporal = [
            'labels'  => $labels,
            'entrada' => $entrada,
            'saida'   => $saida,
            'remocao' => $remocao,
            'outros'  => $outros,
            'meta'    => [
                'tipos_desconhecidos' => $tiposDesconhecidos,
                'sum_entrada'         => array_sum($entrada),
                'sum_saida'           => array_sum($saida),
                'sum_remocao'         => array_sum($remocao),
                'sum_outros'          => array_sum($outros),
                'sum_total_grafico'   => array_sum($entrada) + array_sum($saida) + array_sum($remocao) + array_sum($outros),
                'debug_version'       => 'relatorios.php-ajustado-estoque'
            ]
        ];

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
            'totais'  => [
                'total_qtd'   => $total_qtd,
                'total_valor' => $total_valor
            ],
            'grafico_temporal' => $grafico_temporal
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