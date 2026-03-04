<?php
/**
 * api/relatorios.php
 * Relatórios e estatísticas de movimentações
 * Compatível com PHP 8.2+ / 8.5
 */

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Sao_Paulo');

// Inicializa log específico
initLog('relatorios');

/**
 * Normaliza o tipo vindo do banco para chaves padronizadas do sistema:
 * entrada / saida / remocao
 */
function normalizaTipoMov(?string $tipo): string
{
    $t = strtolower(trim((string)$tipo));

    // Normaliza acentos mais comuns (sem depender de extensão)
    $t = str_replace(
        ['á','à','ã','â','ä','é','ê','ë','í','î','ï','ó','ô','õ','ö','ú','û','ü','ç'],
        ['a','a','a','a','a','e','e','e','i','i','i','o','o','o','o','u','u','u','c'],
        $t
    );

    // Sinônimos/variações (se existirem registros antigos)
    if ($t === 'saida' || $t === 'saidas') return 'saida';
    if ($t === 'entrada' || $t === 'entradas') return 'entrada';

    if ($t === 'remocao' || $t === 'remocoes' || $t === 'remover' || $t === 'remove' || $t === 'deletar' || $t === 'delete') {
        return 'remocao';
    }

    // Se já estiver certo, mantém
    if ($t === 'entrada' || $t === 'saida' || $t === 'remocao') return $t;

    return ''; // desconhecido
}

/**
 * Gera relatório de movimentações (com filtros, paginação e totalizadores)
 * + gráfico temporal (quantidade por dia)
 */
function relatorio(mysqli $conn, array $filtros): array
{
    $pagina = max(1, (int)($filtros['pagina'] ?? 1));
    $limite = max(1, min(200, (int)($filtros['limite'] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where  = [];
    $params = [];
    $types  = '';

    // 🔹 Filtros dinâmicos
    if (!empty($filtros['tipo']) && in_array($filtros['tipo'], ['entrada', 'saida', 'remocao'], true)) {
        // OBS: Em collation unicode_ci, "saída" costuma comparar igual a "saida",
        // mas mantemos o filtro simples e normalizamos no gráfico.
        $where[]  = 'm.tipo = ?';
        $params[] = $filtros['tipo'];
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
        $params[] = $filtros['data_inicio'] . ' 00:00:00';
        $types   .= 's';
    }

    if (!empty($filtros['data_fim'])) {
        $where[]  = 'm.data <= ?';
        $params[] = $filtros['data_fim'] . ' 23:59:59';
        $types   .= 's';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    try {
        // =====================================================
        // 🔹 TOTAL (registros)
        // =====================================================
        $stmtT = $conn->prepare(
            "SELECT COUNT(*) AS total FROM movimentacoes m $whereSql"
        );

        if ($types) {
            $stmtT->bind_param($types, ...$params);
        }

        $stmtT->execute();
        $total = (int)($stmtT->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtT->close();

        // =====================================================
        // 🔹 TOTALIZADORES (qtd e valor)
        // =====================================================
        $stmtTot = $conn->prepare(
            "SELECT
                COALESCE(SUM(m.quantidade), 0) AS total_qtd,
                COALESCE(SUM(m.quantidade * COALESCE(m.valor_unitario,0)), 0) AS total_valor
             FROM movimentacoes m
             $whereSql"
        );

        if ($types) {
            $stmtTot->bind_param($types, ...$params);
        }

        $stmtTot->execute();
        $totRow = $stmtTot->get_result()->fetch_assoc() ?: ['total_qtd' => 0, 'total_valor' => 0];
        $stmtTot->close();

        $total_qtd   = (int)($totRow['total_qtd'] ?? 0);
        $total_valor = (float)($totRow['total_valor'] ?? 0);

        // =====================================================
        // 🔹 LISTAGEM
        // =====================================================
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

        $paramsPage   = $params;
        $typesPage    = $types . 'ii';
        $paramsPage[] = $limite;
        $paramsPage[] = $offset;

        $stmt->bind_param($typesPage, ...$paramsPage);

        $stmt->execute();
        $res = $stmt->get_result();

        $dados = [];
        while ($r = $res->fetch_assoc()) {
            $dados[] = [
                'id'             => (int)$r['id'],
                'produto_nome'   => $r['produto_nome'],
                'tipo'           => $r['tipo'],
                'quantidade'     => (int)$r['quantidade'],
                'valor_unitario' => $r['valor_unitario'] !== null ? (float)$r['valor_unitario'] : null,
                'valor_total'    => (float)$r['valor_total'],
                'data'           => date('d/m/Y H:i', strtotime($r['data'])),
                'usuario'        => $r['usuario']
            ];
        }
        $stmt->close();

        // =====================================================
        // 🔹 GRÁFICO TEMPORAL (quantidade por dia e tipo)
        // =====================================================
        $stmtGT = $conn->prepare(
            "SELECT
                DATE(m.data) AS dia,
                m.tipo,
                COALESCE(SUM(m.quantidade),0) AS qtd
             FROM movimentacoes m
             $whereSql
             GROUP BY DATE(m.data), m.tipo
             ORDER BY dia ASC"
        );

        if ($types) {
            $stmtGT->bind_param($types, ...$params);
        }

        $stmtGT->execute();
        $resGT = $stmtGT->get_result();

        $map = []; // dia => ['entrada'=>x,'saida'=>y,'remocao'=>z]
        $tiposDesconhecidos = 0;

        while ($row = $resGT->fetch_assoc()) {
            $dia  = (string)$row['dia'];     // YYYY-MM-DD
            $tipoRaw = $row['tipo'];        // pode vir com acento/variação
            $tipo = normalizaTipoMov(is_string($tipoRaw) ? $tipoRaw : (string)$tipoRaw);
            $qtd  = (int)$row['qtd'];

            if (!isset($map[$dia])) {
                $map[$dia] = ['entrada' => 0, 'saida' => 0, 'remocao' => 0];
            }

            if ($tipo !== '') {
                $map[$dia][$tipo] = $qtd;
            } else {
                $tiposDesconhecidos++;
            }
        }
        $stmtGT->close();

        if ($tiposDesconhecidos > 0) {
            logInfo('relatorios', 'Aviso: tipos desconhecidos no gráfico (normalização)', [
                'qtd_tipos_desconhecidos' => $tiposDesconhecidos
            ]);
        }

        $labels = array_keys($map);
        $entrada = [];
        $saida   = [];
        $remocao = [];

        foreach ($labels as $d) {
            $entrada[] = $map[$d]['entrada'] ?? 0;
            $saida[]   = $map[$d]['saida'] ?? 0;
            $remocao[] = $map[$d]['remocao'] ?? 0;
        }

        $grafico_temporal = [
            'labels'  => $labels,
            'entrada' => $entrada,
            'saida'   => $saida,
            'remocao' => $remocao
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