<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

date_default_timezone_set('America/Sao_Paulo');
initLog('relatorios');

const REL_MAX_TEXTO_FILTRO = 150;
const REL_MAX_LIMITE = 5000;
const REL_MAX_INTERVALO_DIAS = 3660; // ~10 anos
const REL_MAX_RANKING = 10;

function removeAcentos(string $s): string
{
    return str_replace(
        ['á', 'à', 'ã', 'â', 'ä', 'é', 'ê', 'ë', 'í', 'î', 'ï', 'ó', 'ô', 'õ', 'ö', 'ú', 'û', 'ü', 'ç',
         'Á', 'À', 'Ã', 'Â', 'Ä', 'É', 'Ê', 'Ë', 'Í', 'Î', 'Ï', 'Ó', 'Ô', 'Õ', 'Ö', 'Ú', 'Û', 'Ü', 'Ç'],
        ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'c',
         'A', 'A', 'A', 'A', 'A', 'E', 'E', 'E', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'C'],
        $s
    );
}

function rel_strlen(string $valor): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($valor, 'UTF-8')
        : strlen($valor);
}

function rel_validar_tamanho_texto(?string $valor, int $max, string $campo): void
{
    $texto = trim((string)$valor);
    if (rel_strlen($texto) > $max) {
        throw new InvalidArgumentException("O campo {$campo} excede o limite permitido.");
    }
}

function rel_normalizar_texto(?string $valor): string
{
    $texto = trim((string)$valor);
    $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;

    if ($texto !== '') {
        rel_validar_tamanho_texto($texto, REL_MAX_TEXTO_FILTRO, 'filtro');
    }

    return $texto;
}

function rel_data_valida(?string $data): bool
{
    $valor = trim((string)$data);
    if ($valor === '') {
        return false;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $valor);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $valor;
}

function rel_validar_intervalo_datas(?string $dataInicio, ?string $dataFim): void
{
    $ini = trim((string)$dataInicio);
    $fim = trim((string)$dataFim);

    if ($ini === '' || $fim === '') {
        return;
    }

    $dtIni = DateTime::createFromFormat('Y-m-d', $ini);
    $dtFim = DateTime::createFromFormat('Y-m-d', $fim);

    if (!$dtIni || !$dtFim) {
        throw new InvalidArgumentException('Intervalo de datas inválido.');
    }

    if ($dtIni > $dtFim) {
        throw new InvalidArgumentException('A data inicial não pode ser maior que a data final.');
    }

    $dias = (int)$dtIni->diff($dtFim)->days;
    if ($dias > REL_MAX_INTERVALO_DIAS) {
        throw new InvalidArgumentException('O intervalo de datas excede o limite permitido.');
    }
}

function rel_parse_positive_int($value, int $default = 0): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    if (is_string($value) && !preg_match('/^\d+$/', trim($value))) {
        return $default;
    }

    $n = (int)$value;
    return $n > 0 ? $n : $default;
}

function normalizaTipoMov(?string $tipo): string
{
    $t = strtolower(trim((string)$tipo));
    $t = removeAcentos($t);
    $t = str_replace(['-', '_', '.', '/', '\\'], ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t) ?: $t;

    if ($t === 'e') {
        return 'entrada';
    }

    if ($t === 's') {
        return 'saida';
    }

    if ($t === 'r') {
        return 'remocao';
    }

    if (in_array($t, ['entrada', 'saida', 'remocao'], true)) {
        return $t;
    }

    if ($t === 'entradas') {
        return 'entrada';
    }

    if ($t === 'saidas') {
        return 'saida';
    }

    if ($t === 'remocoes') {
        return 'remocao';
    }

    if (str_contains($t, 'entr')) {
        return 'entrada';
    }

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

function rel_bind_execute(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao executar consulta preparada.');
    }
}

function rel_montar_where(array $filtros): array
{
    $where  = [];
    $params = [];
    $types  = '';

    $tipo         = normalizaTipoMov((string)($filtros['tipo'] ?? ''));
    $produtoId    = rel_parse_positive_int($filtros['produto_id'] ?? null, 0);
    $produto      = rel_normalizar_texto((string)($filtros['produto'] ?? ''));
    $fornecedorId = rel_parse_positive_int($filtros['fornecedor_id'] ?? null, 0);
    $usuarioId    = rel_parse_positive_int($filtros['usuario_id'] ?? null, 0);
    $usuario      = rel_normalizar_texto((string)($filtros['usuario'] ?? ''));
    $dataInicio   = rel_normalizar_texto((string)($filtros['data_inicio'] ?? ''));
    $dataFim      = rel_normalizar_texto((string)($filtros['data_fim'] ?? ''));

    rel_validar_intervalo_datas($dataInicio, $dataFim);

    if ((string)($filtros['tipo'] ?? '') !== '' && $tipo === '') {
        throw new InvalidArgumentException('Tipo de movimentação inválido.');
    }

    if ($tipo !== '') {
        $where[]  = 'm.tipo = ?';
        $params[] = $tipo;
        $types   .= 's';
    }

    if ($produtoId > 0) {
        $where[]  = 'm.produto_id = ?';
        $params[] = $produtoId;
        $types   .= 'i';
    }

    if ($produto !== '') {
        $where[]  = 'COALESCE(m.produto_nome, p.nome, "") LIKE ?';
        $params[] = '%' . $produto . '%';
        $types   .= 's';
    }

    if ($fornecedorId > 0) {
        $where[]  = 'm.fornecedor_id = ?';
        $params[] = $fornecedorId;
        $types   .= 'i';
    }

    if ($usuarioId > 0) {
        $where[]  = 'm.usuario_id = ?';
        $params[] = $usuarioId;
        $types   .= 'i';
    }

    if ($usuario !== '') {
        $where[]  = 'COALESCE(u.nome, "Sistema") LIKE ?';
        $params[] = '%' . $usuario . '%';
        $types   .= 's';
    }

    if ($dataInicio !== '') {
        if (!rel_data_valida($dataInicio)) {
            throw new InvalidArgumentException('Data inicial inválida.');
        }

        $where[]  = 'm.data >= ?';
        $params[] = $dataInicio . ' 00:00:00';
        $types   .= 's';
    }

    if ($dataFim !== '') {
        if (!rel_data_valida($dataFim)) {
            throw new InvalidArgumentException('Data final inválida.');
        }

        $where[]  = 'm.data <= ?';
        $params[] = $dataFim . ' 23:59:59';
        $types   .= 's';
    }

    return [
        'whereSql' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'params'   => $params,
        'types'    => $types,
    ];
}

function rel_obter_quantidade_estoque_filtrado(mysqli $conn, string $whereSql, string $types, array $params): int
{
    $sql = "
        SELECT
            COALESCE(SUM(produtos_filtrados.quantidade_atual), 0) AS quantidade_atual
        FROM (
            SELECT DISTINCT
                p.id,
                COALESCE(p.quantidade, 0) AS quantidade_atual
            FROM movimentacoes m
            INNER JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSql
        ) AS produtos_filtrados
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar quantidade atual em estoque.');
    }

    rel_bind_execute($stmt, $types, $params);
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $quantidade = (int)($row['quantidade_atual'] ?? 0);
    if ($quantidade < 0) {
        throw new RuntimeException('Quantidade de estoque filtrado inconsistente.');
    }

    return $quantidade;
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
                COALESCE(p.estoque_minimo, 0) AS estoque_minimo,
                (COALESCE(p.quantidade, 0) * COALESCE(p.preco_custo, 0)) AS valor_custo_estimado,
                (COALESCE(p.quantidade, 0) * COALESCE(p.preco_venda, 0)) AS valor_venda_estimado
            FROM produtos p
            ORDER BY p.nome ASC, p.id ASC
        ";

        $res = $conn->query($sql);
        if (!$res instanceof mysqli_result) {
            throw new RuntimeException('Erro ao consultar estoque atual.');
        }

        $itens = [];
        $totalQtd = 0;
        $totalCusto = 0.0;
        $totalVenda = 0.0;
        $totalAbaixoMinimo = 0;
        $totalSemEstoque = 0;

        while ($row = $res->fetch_assoc()) {
            $quantidade = max(0, (int)($row['quantidade'] ?? 0));
            $estoqueMinimo = max(0, (int)($row['estoque_minimo'] ?? 0));
            $precoCusto = max(0.0, (float)($row['preco_custo'] ?? 0));
            $precoVenda = max(0.0, (float)($row['preco_venda'] ?? 0));
            $valorCusto = max(0.0, (float)($row['valor_custo_estimado'] ?? 0));
            $valorVenda = max(0.0, (float)($row['valor_venda_estimado'] ?? 0));

            $status = 'normal';
            if ($quantidade <= 0) {
                $status = 'sem_estoque';
                $totalSemEstoque++;
            } elseif ($quantidade <= $estoqueMinimo) {
                $status = 'abaixo_minimo';
                $totalAbaixoMinimo++;
            }

            $itens[] = [
                'id'                   => (int)$row['id'],
                'nome'                 => (string)$row['nome'],
                'quantidade'           => $quantidade,
                'estoque_minimo'       => $estoqueMinimo,
                'preco_custo'          => $precoCusto,
                'preco_venda'          => $precoVenda,
                'valor_custo_estimado' => $valorCusto,
                'valor_venda_estimado' => $valorVenda,
                'status'               => $status,
            ];

            $totalQtd += $quantidade;
            $totalCusto += $valorCusto;
            $totalVenda += $valorVenda;
        }

        $res->free();

        return resposta(true, 'Estoque atual gerado com sucesso.', [
            'itens' => $itens,
            'totais' => [
                'total_qtd'           => $totalQtd,
                'total_custo'         => $totalCusto,
                'total_venda'         => $totalVenda,
                'total_lucro'         => $totalVenda - $totalCusto,
                'total_abaixo_minimo' => $totalAbaixoMinimo,
                'total_sem_estoque'   => $totalSemEstoque,
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
    $pagina = max(1, rel_parse_positive_int($filtros['pagina'] ?? null, 1));
    $limite = max(1, min(REL_MAX_LIMITE, rel_parse_positive_int($filtros['limite'] ?? null, 50)));
    $offset = ($pagina - 1) * $limite;

    try {
        $whereData = rel_montar_where($filtros);
        $whereSql = $whereData['whereSql'];
        $params   = $whereData['params'];
        $types    = $whereData['types'];

        $sqlBaseFrom = "
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSql
        ";

        $stmtCount = $conn->prepare("SELECT COUNT(*) AS total $sqlBaseFrom");
        if (!$stmtCount) {
            throw new RuntimeException('Erro ao preparar contagem do relatório.');
        }

        rel_bind_execute($stmtCount, $types, $params);
        $total = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtCount->close();

        $stmtMov = $conn->prepare("
            SELECT
                COUNT(*) AS total_registros,

                COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.quantidade ELSE 0 END), 0) AS entrada_qtd,
                COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN COALESCE(m.custo_total, 0) ELSE 0 END), 0) AS entrada_custo,
                COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN COALESCE(m.valor_total, 0) ELSE 0 END), 0) AS entrada_valor,

                COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN m.quantidade ELSE 0 END), 0) AS saida_qtd,
                COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN COALESCE(m.custo_total, 0) ELSE 0 END), 0) AS saida_custo,
                COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN COALESCE(m.valor_total, 0) ELSE 0 END), 0) AS saida_valor,
                COALESCE(SUM(CASE WHEN m.tipo = 'saida' THEN COALESCE(m.lucro, 0) ELSE 0 END), 0) AS saida_lucro,

                COALESCE(SUM(CASE WHEN m.tipo = 'remocao' THEN m.quantidade ELSE 0 END), 0) AS remocao_qtd,
                COALESCE(SUM(CASE WHEN m.tipo = 'remocao' THEN COALESCE(m.custo_total, 0) ELSE 0 END), 0) AS remocao_custo
            $sqlBaseFrom
        ");
        if (!$stmtMov) {
            throw new RuntimeException('Erro ao preparar totais de movimentação do relatório.');
        }

        rel_bind_execute($stmtMov, $types, $params);
        $movRow = $stmtMov->get_result()->fetch_assoc() ?: [];
        $stmtMov->close();

        $quantidadeAtual = rel_obter_quantidade_estoque_filtrado($conn, $whereSql, $types, $params);

        $totais = [
            'total_registros' => max(0, (int)($movRow['total_registros'] ?? 0)),

            'total_qtd'       => $quantidadeAtual,
            'total_custo'     => max(0.0, (float)($movRow['entrada_custo'] ?? 0)),
            'total_valor'     => max(0.0, (float)($movRow['saida_valor'] ?? 0)),
            'total_lucro'     => (float)($movRow['saida_lucro'] ?? 0),

            'entrada_qtd'     => max(0, (int)($movRow['entrada_qtd'] ?? 0)),
            'entrada_custo'   => max(0.0, (float)($movRow['entrada_custo'] ?? 0)),
            'entrada_valor'   => max(0.0, (float)($movRow['entrada_valor'] ?? 0)),

            'saida_qtd'       => max(0, (int)($movRow['saida_qtd'] ?? 0)),
            'saida_custo'     => max(0.0, (float)($movRow['saida_custo'] ?? 0)),
            'saida_valor'     => max(0.0, (float)($movRow['saida_valor'] ?? 0)),
            'saida_lucro'     => (float)($movRow['saida_lucro'] ?? 0),

            'remocao_qtd'     => max(0, (int)($movRow['remocao_qtd'] ?? 0)),
            'remocao_custo'   => max(0.0, (float)($movRow['remocao_custo'] ?? 0)),
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
                m.usuario_id,
                COALESCE(u.nome, 'Sistema') AS usuario
            $sqlBaseFrom
            ORDER BY m.data DESC, m.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmtDados = $conn->prepare($sqlDados);
        if (!$stmtDados) {
            throw new RuntimeException('Erro ao preparar dados do relatório.');
        }

        $paramsPage = $params;
        $typesPage  = $types . 'ii';
        $paramsPage[] = $limite;
        $paramsPage[] = $offset;

        $stmtDados->bind_param($typesPage, ...$paramsPage);
        if (!$stmtDados->execute()) {
            $stmtDados->close();
            throw new RuntimeException('Erro ao executar dados do relatório.');
        }

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
                'quantidade'      => max(0, (int)$r['quantidade']),
                'valor_unitario'  => $r['valor_unitario'] !== null ? (float)$r['valor_unitario'] : null,
                'custo_unitario'  => $r['custo_unitario'] !== null ? (float)$r['custo_unitario'] : null,
                'custo_total'     => $r['custo_total'] !== null ? (float)$r['custo_total'] : null,
                'valor_total'     => $r['valor_total'] !== null ? (float)$r['valor_total'] : null,
                'lucro'           => $r['lucro'] !== null ? (float)$r['lucro'] : null,
                'observacao'      => (string)($r['observacao'] ?? ''),
                'data'            => (string)$r['data'],
                'usuario_id'      => $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null,
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
        if (!$stmtGraf) {
            throw new RuntimeException('Erro ao preparar gráfico temporal do relatório.');
        }

        rel_bind_execute($stmtGraf, $types, $params);
        $resGraf = $stmtGraf->get_result();

        $graficoLabels = [];
        $graficoQuantidade = [];
        $graficoCusto = [];
        $graficoValor = [];
        $graficoLucro = [];

        while ($row = $resGraf->fetch_assoc()) {
            $graficoLabels[]     = (string)$row['dia'];
            $graficoQuantidade[] = max(0, (int)($row['quantidade'] ?? 0));
            $graficoCusto[]      = max(0.0, (float)($row['custo_total'] ?? 0));
            $graficoValor[]      = max(0.0, (float)($row['valor_total'] ?? 0));
            $graficoLucro[]      = (float)($row['lucro'] ?? 0);
        }
        $stmtGraf->close();

        $graficoTemporal = [
            'labels'      => $graficoLabels,
            'quantidade'  => $graficoQuantidade,
            'custo_total' => $graficoCusto,
            'valor_total' => $graficoValor,
            'lucro'       => $graficoLucro,
        ];

        $whereSqlSaida = $whereSql . ($whereSql ? ' AND ' : ' WHERE ') . "m.tipo = 'saida'";

        $sqlRankProdutosQtd = "
            SELECT
                COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                COALESCE(SUM(m.valor_total), 0) AS valor_total,
                COALESCE(SUM(m.lucro), 0) AS lucro_total
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSqlSaida
            GROUP BY COALESCE(m.produto_nome, p.nome, '[Produto removido]')
            ORDER BY quantidade_total DESC, produto_nome ASC
            LIMIT " . REL_MAX_RANKING;

        $stmtRankProdutosQtd = $conn->prepare($sqlRankProdutosQtd);
        if (!$stmtRankProdutosQtd) {
            throw new RuntimeException('Erro ao preparar ranking de produtos por quantidade.');
        }

        rel_bind_execute($stmtRankProdutosQtd, $types, $params);
        $resRankProdutosQtd = $stmtRankProdutosQtd->get_result();
        $rankingProdutosQtd = [];

        while ($row = $resRankProdutosQtd->fetch_assoc()) {
            $rankingProdutosQtd[] = [
                'produto_nome'     => (string)$row['produto_nome'],
                'quantidade_total' => max(0, (int)($row['quantidade_total'] ?? 0)),
                'valor_total'      => max(0.0, (float)($row['valor_total'] ?? 0)),
                'lucro_total'      => (float)($row['lucro_total'] ?? 0),
            ];
        }
        $stmtRankProdutosQtd->close();

        $sqlRankLucro = "
            SELECT
                COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                COALESCE(SUM(m.lucro), 0) AS lucro_total,
                COALESCE(SUM(m.quantidade), 0) AS quantidade_total,
                COALESCE(SUM(m.valor_total), 0) AS valor_total
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSqlSaida
            GROUP BY COALESCE(m.produto_nome, p.nome, '[Produto removido]')
            ORDER BY lucro_total DESC, produto_nome ASC
            LIMIT " . REL_MAX_RANKING;

        $stmtRankLucro = $conn->prepare($sqlRankLucro);
        if (!$stmtRankLucro) {
            throw new RuntimeException('Erro ao preparar ranking de produtos por lucro.');
        }

        rel_bind_execute($stmtRankLucro, $types, $params);
        $resRankLucro = $stmtRankLucro->get_result();
        $rankingProdutosLucro = [];

        while ($row = $resRankLucro->fetch_assoc()) {
            $rankingProdutosLucro[] = [
                'produto_nome'     => (string)$row['produto_nome'],
                'lucro_total'      => (float)($row['lucro_total'] ?? 0),
                'quantidade_total' => max(0, (int)($row['quantidade_total'] ?? 0)),
                'valor_total'      => max(0.0, (float)($row['valor_total'] ?? 0)),
            ];
        }
        $stmtRankLucro->close();

        $sqlRankFornecedor = "
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
            LIMIT " . REL_MAX_RANKING;

        $stmtRankFornecedor = $conn->prepare($sqlRankFornecedor);
        if (!$stmtRankFornecedor) {
            throw new RuntimeException('Erro ao preparar ranking de fornecedores.');
        }

        rel_bind_execute($stmtRankFornecedor, $types, $params);
        $resRankFornecedor = $stmtRankFornecedor->get_result();
        $rankingFornecedores = [];

        while ($row = $resRankFornecedor->fetch_assoc()) {
            $rankingFornecedores[] = [
                'fornecedor_nome'     => (string)$row['fornecedor_nome'],
                'quantidade_total'    => max(0, (int)($row['quantidade_total'] ?? 0)),
                'custo_total'         => max(0.0, (float)($row['custo_total'] ?? 0)),
                'total_movimentacoes' => max(0, (int)($row['total_movimentacoes'] ?? 0)),
            ];
        }
        $stmtRankFornecedor->close();

        logInfo('relatorios', 'Relatório gerado', [
            'total'   => $total,
            'pagina'  => $pagina,
            'limite'  => $limite,
            'filtros' => [
                'tipo'          => $filtros['tipo'] ?? '',
                'produto_id'    => $filtros['produto_id'] ?? '',
                'produto'       => $filtros['produto'] ?? '',
                'fornecedor_id' => $filtros['fornecedor_id'] ?? '',
                'usuario_id'    => $filtros['usuario_id'] ?? '',
                'usuario'       => $filtros['usuario'] ?? '',
                'data_inicio'   => $filtros['data_inicio'] ?? '',
                'data_fim'      => $filtros['data_fim'] ?? '',
            ],
            'totais_topo' => [
                'total_registros'      => $totais['total_registros'],
                'quantidade_estoque'   => $totais['total_qtd'],
                'custo_total_entradas' => $totais['total_custo'],
                'valor_total_saidas'   => $totais['total_valor'],
                'lucro_total_saidas'   => $totais['total_lucro'],
            ]
        ]);

        return resposta(true, 'Relatório gerado com sucesso.', [
            'total'    => $total,
            'pagina'   => $pagina,
            'limite'   => $limite,
            'paginas'  => $limite > 0 ? (int)ceil($total / $limite) : 0,
            'dados'    => $dados,
            'totais'   => $totais,
            'grafico_temporal'        => $graficoTemporal,
            'ranking_produtos_qtd'    => $rankingProdutosQtd,
            'ranking_produtos_lucro'  => $rankingProdutosLucro,
            'ranking_fornecedores'    => $rankingFornecedores,
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
    } catch (InvalidArgumentException $e) {
        logWarning('relatorios', 'Filtro inválido no relatório', [
            'erro'    => $e->getMessage(),
            'filtros' => $filtros
        ]);

        return resposta(false, $e->getMessage(), []);
    } catch (Throwable $e) {
        logError('relatorios', 'Erro ao gerar relatório', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return resposta(false, 'Erro interno ao gerar relatório.', []);
    }
}
