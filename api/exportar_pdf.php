<?php
/**
 * api/exportar_pdf.php
 * Exporta relatório de movimentações em PDF (Dompdf)
 * Compatível com o seu projeto (db() + filtros iguais ao relatório)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('America/Sao_Paulo');
initLog('exportar_pdf');

function esc(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $conn = db();

    // ===== filtros (mesmos nomes do relatorios.js) =====
    $tipo       = isset($_GET['tipo']) ? trim((string)$_GET['tipo']) : '';
    $dataInicio = isset($_GET['data_inicio']) ? trim((string)$_GET['data_inicio']) : '';
    $dataFim    = isset($_GET['data_fim']) ? trim((string)$_GET['data_fim']) : '';

    $where  = [];
    $params = [];
    $types  = '';

    if ($tipo !== '' && in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
        $where[]  = 'm.tipo = ?';
        $params[] = $tipo;
        $types   .= 's';
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

    // ===== totais =====
    $stmtTot = $conn->prepare("
        SELECT
            COALESCE(SUM(m.quantidade), 0) AS total_qtd,
            COALESCE(SUM(m.quantidade * COALESCE(m.valor_unitario,0)), 0) AS total_valor
        FROM movimentacoes m
        $whereSql
    ");
    if ($types !== '') $stmtTot->bind_param($types, ...$params);
    $stmtTot->execute();
    $tot = $stmtTot->get_result()->fetch_assoc() ?: ['total_qtd' => 0, 'total_valor' => 0];
    $stmtTot->close();

    $totalQtd = (int)($tot['total_qtd'] ?? 0);
    $totalVal = (float)($tot['total_valor'] ?? 0);

    // ===== dados =====
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
        LIMIT 2000
    ";

    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    // ===== cabeçalho do relatório =====
    $filtrosTxt = [];
    if ($dataInicio !== '') $filtrosTxt[] = 'De: ' . esc($dataInicio);
    if ($dataFim !== '')    $filtrosTxt[] = 'Até: ' . esc($dataFim);
    if ($tipo !== '')       $filtrosTxt[] = 'Tipo: ' . esc($tipo);
    $filtrosTxt = $filtrosTxt ? implode(' | ', $filtrosTxt) : 'Sem filtros (padrão)';

    $html = '
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
  h1 { font-size: 18px; margin: 0 0 6px; }
  .sub { color: #555; margin: 0 0 12px; }
  .cards { width:100%; margin: 10px 0 14px; }
  .card { display:inline-block; padding:10px; border:1px solid #ddd; border-radius:8px; margin-right:10px; }
  .k { color:#666; font-size:11px; }
  .v { font-size:16px; font-weight:bold; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
  th { background: #f3f4f6; text-align: left; }
  .muted { color:#666; }
</style>
</head>
<body>
  <h1>Relatório de Movimentações</h1>
  <div class="sub">Gerado em: '.esc(date('d/m/Y H:i')).' — <span class="muted">'.esc($filtrosTxt).'</span></div>

  <div class="cards">
    <div class="card">
      <div class="k">Total Quantidade</div>
      <div class="v">'.esc((string)$totalQtd).'</div>
    </div>
    <div class="card">
      <div class="k">Total Valor</div>
      <div class="v">R$ '.esc(number_format($totalVal, 2, ',', '.')).'</div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:50px;">ID</th>
        <th>Produto</th>
        <th style="width:85px;">Tipo</th>
        <th style="width:60px;">Qtd</th>
        <th style="width:90px;">Vlr Unit.</th>
        <th style="width:90px;">Vlr Total</th>
        <th style="width:110px;">Data</th>
        <th style="width:110px;">Usuário</th>
      </tr>
    </thead>
    <tbody>
';

    while ($row = $res->fetch_assoc()) {
        $vu = $row['valor_unitario'];
        $vuFmt = ($vu === null || $vu === '') ? '' : number_format((float)$vu, 2, ',', '.');

        $html .= '
      <tr>
        <td>'.esc((string)$row['id']).'</td>
        <td>'.esc((string)$row['produto']).'</td>
        <td>'.esc((string)$row['tipo']).'</td>
        <td>'.esc((string)$row['quantidade']).'</td>
        <td>'.esc($vuFmt).'</td>
        <td>'.esc(number_format((float)$row['valor_total'], 2, ',', '.')).'</td>
        <td>'.esc((string)$row['data']).'</td>
        <td>'.esc((string)$row['usuario']).'</td>
      </tr>
';
    }

    $html .= '
    </tbody>
  </table>
</body>
</html>
';

    $stmt->close();
    $conn->close();

    // ===== Dompdf options =====
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans'); // evita problema com acentos
    $options->set('isRemoteEnabled', false);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'relatorio_movimentacoes_' . date('Y-m-d_H-i') . '.pdf';

    // força download
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;

} catch (Throwable $e) {
    logError('exportar_pdf', 'Erro ao exportar PDF', [
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
        'erro' => $e->getMessage()
    ]);

    http_response_code(500);
    echo "Erro ao exportar PDF.";
    exit;
}