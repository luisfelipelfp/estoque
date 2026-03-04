<?php
/**
 * api/exportar_csv.php
 * Exporta movimentações em CSV (compatível com Excel)
 * PHP 8.2+
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/log.php';

date_default_timezone_set('America/Sao_Paulo');
initLog('exportar_csv');

try {
    $conn = db(); // ✅ no seu projeto a função é db()

    // ====== Lê filtros (mesmos nomes do relatorios.js) ======
    $tipo       = isset($_GET['tipo']) ? trim((string)$_GET['tipo']) : '';
    $dataInicio = isset($_GET['data_inicio']) ? trim((string)$_GET['data_inicio']) : '';
    $dataFim    = isset($_GET['data_fim']) ? trim((string)$_GET['data_fim']) : '';

    // (opcionais, se você for usar depois)
    $produtoId  = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;
    $usuarioId  = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;

    $where  = [];
    $params = [];
    $types  = '';

    if ($tipo !== '' && in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
        $where[]  = 'm.tipo = ?';
        $params[] = $tipo;
        $types   .= 's';
    }

    if ($produtoId > 0) {
        $where[]  = 'm.produto_id = ?';
        $params[] = $produtoId;
        $types   .= 'i';
    }

    if ($usuarioId > 0) {
        $where[]  = 'm.usuario_id = ?';
        $params[] = $usuarioId;
        $types   .= 'i';
    }

    if ($dataInicio !== '') {
        $where[]  = 'm.data >= ?';
        $params[] = $dataInicio . ' 00:00:00';
        $types   .= 's';
    }

    if ($dataFim !== '') {
        $where[]  = 'm.data <= ?';
        $params[] = $dataFim . ' 23:59:59';
        $types   .= 's';
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            m.id,
            COALESCE(p.nome, m.produto_nome, '[Produto removido]') AS produto,
            m.tipo,
            m.quantidade,
            m.valor_unitario,
            (m.quantidade * COALESCE(m.valor_unitario,0)) AS valor_total,
            DATE_FORMAT(m.data, '%d/%m/%Y %H:%i') AS data,
            COALESCE(u.nome, 'Sistema') AS usuario
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        $whereSql
        ORDER BY m.data DESC, m.id DESC
    ";

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    // ====== Headers do download ======
    $filename = 'movimentacoes_' . date('Y-m-d_H-i') . '.csv';

    // limpa buffers antes de mandar header
    while (ob_get_level() > 0) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // BOM UTF-8 (Excel pt-BR agradece)
    fwrite($out, "\xEF\xBB\xBF");

    // separador ; (melhor no Excel PT-BR)
    fputcsv($out, ['ID','Produto','Tipo','Quantidade','Valor Unitário','Valor Total','Data','Usuário'], ';');

    while ($row = $res->fetch_assoc()) {
        // formata dinheiro com vírgula
        $valorUnit = ($row['valor_unitario'] === null) ? '' : number_format((float)$row['valor_unitario'], 2, ',', '.');
        $valorTot  = number_format((float)$row['valor_total'], 2, ',', '.');

        fputcsv($out, [
            $row['id'],
            $row['produto'],
            $row['tipo'],
            $row['quantidade'],
            $valorUnit,
            $valorTot,
            $row['data'],
            $row['usuario']
        ], ';');
    }

    fclose($out);
    $stmt->close();
    $conn->close();
    exit;

} catch (Throwable $e) {
    logError('exportar_csv', 'Erro ao exportar CSV', [
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
        'erro' => $e->getMessage()
    ]);

    // se já mandou header de CSV, não dá pra responder JSON com segurança
    if (!headers_sent()) {
        json_response(false, 'Erro ao exportar CSV.', null, 500);
    }

    echo "Erro ao exportar CSV.";
    exit;
}