<?php
/**
 * api/exportar_csv.php
 * Exporta movimentações em CSV (Excel abre perfeito)
 */

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Sao_Paulo');
initLog('exportar_csv');

/**
 * Escape simples para CSV
 */
function csvCell(string $s): string {
    // troca CR/LF e aspas
    $s = str_replace(["\r\n", "\r", "\n"], " ", $s);
    $s = str_replace('"', '""', $s);
    return '"' . $s . '"';
}

try {
    $conn = dbConn(); // ajuste se seu db.php usa outro nome (ex: conectar())
    // se no seu db.php for $conn global, substitua conforme seu projeto

    // filtros
    $tipo = $_GET['tipo'] ?? '';
    $data_inicio = $_GET['data_inicio'] ?? '';
    $data_fim = $_GET['data_fim'] ?? '';
    $produto_id = $_GET['produto_id'] ?? '';
    $usuario_id = $_GET['usuario_id'] ?? '';

    $where = [];
    $params = [];
    $types = '';

    if ($tipo !== '' && in_array($tipo, ['entrada','saida','remocao'], true)) {
        $where[] = 'm.tipo = ?';
        $params[] = $tipo;
        $types .= 's';
    }

    if ($produto_id !== '' && ctype_digit((string)$produto_id)) {
        $where[] = 'm.produto_id = ?';
        $params[] = (int)$produto_id;
        $types .= 'i';
    }

    if ($usuario_id !== '' && ctype_digit((string)$usuario_id)) {
        $where[] = 'm.usuario_id = ?';
        $params[] = (int)$usuario_id;
        $types .= 'i';
    }

    if ($data_inicio !== '') {
        $where[] = 'm.data >= ?';
        $params[] = $data_inicio . ' 00:00:00';
        $types .= 's';
    }

    if ($data_fim !== '') {
        $where[] = 'm.data <= ?';
        $params[] = $data_fim . ' 23:59:59';
        $types .= 's';
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
        m.data,
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

    // headers CSV
    $nomeArquivo = 'relatorio_movimentacoes_' . date('Y-m-d_H-i') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM UTF-8 (Excel ama isso)
    echo "\xEF\xBB\xBF";

    // cabeçalho
    echo implode(';', [
        csvCell('ID'),
        csvCell('Produto'),
        csvCell('Tipo'),
        csvCell('Quantidade'),
        csvCell('Valor Unitário'),
        csvCell('Valor Total'),
        csvCell('Data'),
        csvCell('Usuário'),
    ]) . "\n";

    while ($r = $res->fetch_assoc()) {
        $dataFmt = date('d/m/Y H:i', strtotime((string)$r['data']));
        $vu = $r['valor_unitario'] !== null ? number_format((float)$r['valor_unitario'], 2, ',', '.') : '';
        $vt = number_format((float)$r['valor_total'], 2, ',', '.');

        echo implode(';', [
            csvCell((string)$r['id']),
            csvCell((string)$r['produto']),
            csvCell((string)$r['tipo']),
            csvCell((string)$r['quantidade']),
            csvCell($vu),
            csvCell($vt),
            csvCell($dataFmt),
            csvCell((string)$r['usuario']),
        ]) . "\n";
    }

    $stmt->close();
    exit;

} catch (Throwable $e) {
    // se der erro, devolve texto simples
    http_response_code(500);
    echo "Erro ao exportar CSV: " . $e->getMessage();
    exit;
}