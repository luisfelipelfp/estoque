<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/log.php';

initLog('dashboard');

function dashboard_resposta(array $dados): void
{
    echo json_encode([
        'sucesso' => true,
        'dados'   => $dados
    ]);
    exit;
}

function dashboard_erro(string $mensagem): void
{
    http_response_code(500);
    echo json_encode([
        'sucesso'  => false,
        'mensagem' => $mensagem
    ]);
    exit;
}

try {
    $conn = getDbConnection();

    // =========================
    // Total de produtos
    // =========================
    $sqlProdutos = "SELECT COUNT(*) AS total FROM produtos WHERE ativo = 1";
    $resProdutos = $conn->query($sqlProdutos);
    $totalProdutos = (int)($resProdutos->fetch_assoc()['total'] ?? 0);

    // =========================
    // Total de movimentações
    // =========================
    $sqlMov = "SELECT COUNT(*) AS total FROM movimentacoes";
    $resMov = $conn->query($sqlMov);
    $totalMov = (int)($resMov->fetch_assoc()['total'] ?? 0);

    // =========================
    // Lucro total
    // =========================
    $sqlLucro = "
        SELECT COALESCE(SUM(lucro),0) AS lucro_total
        FROM movimentacoes
        WHERE tipo = 'saida'
    ";
    $resLucro = $conn->query($sqlLucro);
    $lucroTotal = (float)($resLucro->fetch_assoc()['lucro_total'] ?? 0);

    // =========================
    // Faturamento total
    // =========================
    $sqlFaturamento = "
        SELECT COALESCE(SUM(valor_total),0) AS faturamento
        FROM movimentacoes
        WHERE tipo = 'saida'
    ";
    $resFat = $conn->query($sqlFaturamento);
    $faturamento = (float)($resFat->fetch_assoc()['faturamento'] ?? 0);

    // =========================
    // Gráfico últimos 30 dias
    // =========================
    $sqlGrafico = "
        SELECT
            DATE(data) AS dia,
            SUM(CASE WHEN tipo='entrada' THEN quantidade ELSE 0 END) AS entradas,
            SUM(CASE WHEN tipo='saida' THEN quantidade ELSE 0 END) AS saidas
        FROM movimentacoes
        WHERE data >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(data)
        ORDER BY dia ASC
    ";

    $grafico = [];
    $resGraf = $conn->query($sqlGrafico);

    while ($row = $resGraf->fetch_assoc()) {
        $grafico[] = [
            'dia'      => $row['dia'],
            'entradas' => (int)$row['entradas'],
            'saidas'   => (int)$row['saidas']
        ];
    }

    // =========================
    // Top produtos vendidos
    // =========================
    $sqlTopProdutos = "
        SELECT
            produto_nome,
            SUM(quantidade) AS total
        FROM movimentacoes
        WHERE tipo='saida'
        GROUP BY produto_nome
        ORDER BY total DESC
        LIMIT 5
    ";

    $topProdutos = [];
    $resTop = $conn->query($sqlTopProdutos);

    while ($row = $resTop->fetch_assoc()) {
        $topProdutos[] = [
            'produto' => $row['produto_nome'],
            'total'   => (int)$row['total']
        ];
    }

    dashboard_resposta([
        'total_produtos' => $totalProdutos,
        'total_movimentacoes' => $totalMov,
        'lucro_total' => $lucroTotal,
        'faturamento_total' => $faturamento,
        'grafico' => $grafico,
        'top_produtos' => $topProdutos
    ]);

} catch (Throwable $e) {

    logError([
        'origem'  => 'dashboard.php',
        'mensagem'=> $e->getMessage(),
        'stack'   => $e->getTraceAsString()
    ]);

    dashboard_erro('Erro ao carregar dashboard.');
}