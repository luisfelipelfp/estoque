<?php
// =======================================
// api/exportar.php
// Gera relatórios PDF e Excel
// =======================================

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/utils.php";
require_once __DIR__ . "/relatorios.php";
require_once __DIR__ . "/../libs/fpdf.php"; // Ajuste o caminho se necessário

function exportar_relatorio(mysqli $conn, array $filtros): array {
    $tipo = strtolower($filtros["tipo"] ?? "pdf");

    // Busca os dados do relatório
    $dados = relatorio($conn, $filtros);
    if (!$dados["sucesso"] || empty($dados["dados"])) {
        return resposta(false, "Nenhum dado encontrado para exportação.");
    }

    $registros = $dados["dados"];
    $arquivo = "";

    if ($tipo === "pdf") {
        $arquivo = gerar_pdf($registros);
        $url = "http://192.168.15.100/estoque/tmp/" . basename($arquivo);
        return resposta(true, "Relatório PDF gerado com sucesso.", ["arquivo" => $url]);
    } elseif ($tipo === "excel") {
        $arquivo = gerar_excel($registros);
        $url = "http://192.168.15.100/estoque/tmp/" . basename($arquivo);
        return resposta(true, "Relatório Excel gerado com sucesso.", ["arquivo" => $url]);
    }

    return resposta(false, "Formato de exportação inválido.");
}

// ----------------------
// Gera PDF
// ----------------------
function gerar_pdf(array $registros): string {
    $tmpDir = __DIR__ . "/../tmp";
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
    $arquivo = "$tmpDir/relatorio_" . date("Ymd_His") . ".pdf";

    $pdf = new FPDF("L", "mm", "A4");
    $pdf->AddPage();
    $pdf->SetFont("Arial", "B", 14);
    $pdf->Cell(0, 10, utf8_decode("Relatório de Movimentações"), 0, 1, "C");
    $pdf->Ln(5);

    $pdf->SetFont("Arial", "B", 10);
    $pdf->Cell(20, 8, "ID", 1);
    $pdf->Cell(50, 8, "Produto", 1);
    $pdf->Cell(30, 8, "Tipo", 1);
    $pdf->Cell(30, 8, "Quantidade", 1);
    $pdf->Cell(50, 8, "Usuário", 1);
    $pdf->Cell(40, 8, "Data", 1);
    $pdf->Ln();

    $pdf->SetFont("Arial", "", 9);
    foreach ($registros as $r) {
        $pdf->Cell(20, 8, $r["id"], 1);
        $pdf->Cell(50, 8, utf8_decode($r["produto_nome"]), 1);
        $pdf->Cell(30, 8, $r["tipo"], 1);
        $pdf->Cell(30, 8, $r["quantidade"], 1);
        $pdf->Cell(50, 8, utf8_decode($r["usuario"]), 1);
        $pdf->Cell(40, 8, $r["data"], 1);
        $pdf->Ln();
    }

    $pdf->Output("F", $arquivo);
    return $arquivo;
}

// ----------------------
// Gera Excel (CSV)
// ----------------------
function gerar_excel(array $registros): string {
    $tmpDir = __DIR__ . "/../tmp";
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
    $arquivo = "$tmpDir/relatorio_" . date("Ymd_His") . ".csv";

    $fp = fopen($arquivo, "w");
    fputcsv($fp, ["ID", "Produto", "Tipo", "Quantidade", "Usuário", "Data"], ";");

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
