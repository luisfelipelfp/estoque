<?php
// =======================================
// api/exportar.php (PHP 8.5 adaptado)
// Gera relatórios PDF e Excel
// =======================================

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/utils.php";
require_once __DIR__ . "/relatorios.php";
require_once __DIR__ . "/../libs/fpdf.php"; // Ajuste o caminho se necessário

/**
 * Exporta um relatório em PDF ou Excel.
 */
function exportar_relatorio(mysqli $conn, array $filtros): array {
    $tipo = strtolower($filtros["tipo"] ?? "pdf");

    // Busca os dados
    $dados = relatorio($conn, $filtros);

    if (!$dados["sucesso"] || empty($dados["dados"])) {
        return resposta(false, "Nenhum dado encontrado para exportação.");
    }

    $registros = $dados["dados"];
    $arquivo = "";

    switch ($tipo) {
        case "pdf":
            $arquivo = gerar_pdf($registros);
            break;

        case "excel":
        case "csv":
            $arquivo = gerar_excel($registros);
            break;

        default:
            return resposta(false, "Formato de exportação inválido.");
    }

    $url = "http://192.168.15.100/estoque/tmp/" . basename($arquivo);

    return resposta(true, "Arquivo gerado com sucesso.", ["arquivo" => $url]);
}

/**
 * Gera um PDF usando FPDF.
 */
function gerar_pdf(array $registros): string {
    $tmpDir = __DIR__ . "/../tmp";

    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }

    $arquivo = "$tmpDir/relatorio_" . date("Ymd_His") . ".pdf";

    $pdf = new FPDF("L", "mm", "A4");
    $pdf->AddPage();
    $pdf->SetFont("Arial", "B", 14);
    $pdf->Cell(0, 10, "Relatorio de Movimentacoes", 0, 1, "C");
    $pdf->Ln(5);

    // Cabeçalho
    $pdf->SetFont("Arial", "B", 10);
    $headers = [
        ["ID", 20],
        ["Produto", 50],
        ["Tipo", 30],
        ["Quantidade", 30],
        ["Usuário", 50],
        ["Data", 40]
    ];

    foreach ($headers as [$titulo, $largura]) {
        $pdf->Cell($largura, 8, $titulo, 1);
    }
    $pdf->Ln();

    // Linhas
    $pdf->SetFont("Arial", "", 9);
    foreach ($registros as $r) {
        $pdf->Cell(20, 8, (string)$r["id"], 1);
        $pdf->Cell(50, 8, $r["produto_nome"], 1);
        $pdf->Cell(30, 8, $r["tipo"], 1);
        $pdf->Cell(30, 8, (string)$r["quantidade"], 1);
        $pdf->Cell(50, 8, $r["usuario"], 1);
        $pdf->Cell(40, 8, $r["data"], 1);
        $pdf->Ln();
    }

    $pdf->Output("F", $arquivo);
    return $arquivo;
}

/**
 * Gera um arquivo Excel (CSV).
 */
function gerar_excel(array $registros): string {
    $tmpDir = __DIR__ . "/../tmp";

    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }

    $arquivo = "$tmpDir/relatorio_" . date("Ymd_His") . ".csv";

    $fp = fopen($arquivo, "w");

    // Cabeçalho
    fputcsv($fp, ["ID", "Produto", "Tipo", "Quantidade", "Usuário", "Data"], ";");

    // Dados
    foreach ($registros as $r) {
        fputcsv($fp, [
            $r["id"],
            $r["produto_nome"],
            $r["tipo"],
            $r["quantidade"],
            $r["usuario"],
            $r["data"]
        ], ";");
    }

    fclose($fp);

    return $arquivo;
}
