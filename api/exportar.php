<?php
/**
 * api/exportar.php
 * Exportação de relatórios (PDF / CSV)
 * Compatível com PHP 8.2+ / 8.5
 */

declare(strict_types=1);

// =====================================================
// Dependências
// =====================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/relatorios.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/../libs/fpdf.php';

// Inicializa log
initLog('exportar');

/**
 * Exporta relatório em PDF ou CSV
 */
function exportar_relatorio(mysqli $conn, array $filtros): array
{
    $tipo = strtolower($filtros['tipo'] ?? 'pdf');

    logInfo('exportar', 'Solicitação de exportação', [
        'tipo'    => $tipo,
        'filtros' => $filtros
    ]);

    // Busca dados do relatório
    $resultado = relatorio($conn, $filtros);

    if (!$resultado['sucesso'] || empty($resultado['dados'])) {
        logInfo('exportar', 'Nenhum dado encontrado para exportação');
        return resposta(false, 'Nenhum dado encontrado para exportação.');
    }

    try {
        switch ($tipo) {
            case 'pdf':
                $arquivo = gerar_pdf($resultado['dados']);
                break;

            case 'excel':
            case 'csv':
                $arquivo = gerar_csv($resultado['dados']);
                break;

            default:
                logInfo('exportar', 'Formato inválido solicitado', ['tipo' => $tipo]);
                return resposta(false, 'Formato de exportação inválido.');
        }

        $url = 'http://192.168.15.100/estoque/tmp/' . basename($arquivo);

        logInfo('exportar', 'Arquivo gerado com sucesso', [
            'arquivo' => $arquivo
        ]);

        return resposta(true, 'Arquivo gerado com sucesso.', [
            'arquivo' => $url
        ]);

    } catch (Throwable $e) {

        logError(
            'exportar',
            'Erro ao gerar arquivo de exportação',
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        );

        return resposta(false, 'Erro interno ao gerar arquivo.');
    }
}

/**
 * Gera PDF usando FPDF
 */
function gerar_pdf(array $registros): string
{
    $tmpDir = __DIR__ . '/../tmp';

    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }

    $arquivo = $tmpDir . '/relatorio_' . date('Ymd_His') . '.pdf';

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Relatório de Movimentações', 0, 1, 'C');
    $pdf->Ln(5);

    // Cabeçalho
    $pdf->SetFont('Arial', 'B', 10);
    $headers = [
        ['ID', 20],
        ['Produto', 50],
        ['Tipo', 30],
        ['Quantidade', 30],
        ['Usuário', 50],
        ['Data', 40]
    ];

    foreach ($headers as [$titulo, $largura]) {
        $pdf->Cell($largura, 8, $titulo, 1);
    }
    $pdf->Ln();

    // Conteúdo
    $pdf->SetFont('Arial', '', 9);
    foreach ($registros as $r) {
        $pdf->Cell(20, 8, (string)$r['id'], 1);
        $pdf->Cell(50, 8, $r['produto_nome'], 1);
        $pdf->Cell(30, 8, $r['tipo'], 1);
        $pdf->Cell(30, 8, (string)$r['quantidade'], 1);
        $pdf->Cell(50, 8, $r['usuario'], 1);
        $pdf->Cell(40, 8, $r['data'], 1);
        $pdf->Ln();
    }

    $pdf->Output('F', $arquivo);

    return $arquivo;
}

/**
 * Gera CSV (Excel compatível)
 */
function gerar_csv(array $registros): string
{
    $tmpDir = __DIR__ . '/../tmp';

    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }

    $arquivo = $tmpDir . '/relatorio_' . date('Ymd_His') . '.csv';

    $fp = fopen($arquivo, 'w');

    // Cabeçalho
    fputcsv($fp, ['ID', 'Produto', 'Tipo', 'Quantidade', 'Usuário', 'Data'], ';');

    // Dados
    foreach ($registros as $r) {
        fputcsv($fp, [
            $r['id'],
            $r['produto_nome'],
            $r['tipo'],
            $r['quantidade'],
            $r['usuario'],
            $r['data']
        ], ';');
    }

    fclose($fp);

    return $arquivo;
}
