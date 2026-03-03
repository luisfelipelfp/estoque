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
 * Gera relatório de movimentações
 */
function relatorio(mysqli $conn, array $filtros): array
{
    $pagina = max(1, (int)($filtros['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($filtros['limite'] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where  = [];
    $params = [];
    $types  = '';

    // 🔹 Filtros dinâmicos
    if (!empty($filtros['tipo']) && in_array($filtros['tipo'], ['entrada', 'saida', 'remocao'], true)) {
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
        // 🔹 TOTAL
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
        // 🔹 LISTAGEM
        // =====================================================
        $sql = "
            SELECT
                m.id,
                COALESCE(p.nome, m.produto_nome, '[Produto removido]') AS produto_nome,
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

        $stmt = $conn->prepare($sql);

        $paramsPage = $params;
        $typesPage  = $types . 'ii';
        $paramsPage[] = $limite;
        $paramsPage[] = $offset;

        if ($typesPage) {
            $stmt->bind_param($typesPage, ...$paramsPage);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $dados = [];
        while ($r = $res->fetch_assoc()) {
            $dados[] = [
                'id'           => (int)$r['id'],
                'produto_nome' => $r['produto_nome'],
                'tipo'         => $r['tipo'],
                'quantidade'   => (int)$r['quantidade'],
                'data'         => date('d/m/Y H:i', strtotime($r['data'])),
                'usuario'      => $r['usuario']
            ];
        }
        $stmt->close();

        // =====================================================
        // 🔹 DADOS PARA GRÁFICO
        // =====================================================
        $stmtG = $conn->prepare(
            "SELECT tipo, COUNT(*) AS total FROM movimentacoes m $whereSql GROUP BY tipo"
        );

        if ($types) {
            $stmtG->bind_param($types, ...$params);
        }

        $stmtG->execute();
        $resG = $stmtG->get_result();

        $grafico = [
            'entrada' => 0,
            'saida'   => 0,
            'remocao' => 0
        ];

        while ($g = $resG->fetch_assoc()) {
            $grafico[$g['tipo']] = (int)$g['total'];
        }
        $stmtG->close();

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
            'grafico' => $grafico
        ]);

    } catch (Throwable $e) {

        logError(
            'relatorios',
            'Erro ao gerar relatório',
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        );

        return resposta(false, 'Erro interno ao gerar relatório.');
    }
}
