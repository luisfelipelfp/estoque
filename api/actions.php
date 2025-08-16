<?php
header("Content-Type: text/html; charset=UTF-8");

// ==== CONFIGURAÇÃO BANCO ====
$host = "192.168.15.100";     // altere para o host do seu banco
$user = "root";          // usuário do banco
$pass = "#Shakka01";              // senha do banco
$db   = "estoque";       // nome do banco

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Erro de conexão: " . $conn->connect_error);
}

// ==== AÇÕES ====
$action = $_REQUEST['action'] ?? "";

// ======================
// CADASTRAR PRODUTO
// ======================
if ($action === "cadastrarProduto") {
    $nome = $_POST['nome'] ?? "";
    $quantidade = intval($_POST['quantidade'] ?? 0);

    if ($nome === "" || $quantidade <= 0) {
        echo "Dados inválidos!";
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
    $stmt->bind_param("si", $nome, $quantidade);

    if ($stmt->execute()) {
        echo "Produto cadastrado com sucesso!";
    } else {
        echo "Erro ao cadastrar produto!";
    }
    $stmt->close();
    exit;
}

// ======================
// LISTAR PRODUTOS
// ======================
if ($action === "listarProdutos") {
    $result = $conn->query("SELECT id, nome, quantidade FROM produtos ORDER BY nome ASC");
    $produtos = [];
    while ($row = $result->fetch_assoc()) {
        $produtos[] = $row;
    }
    echo json_encode($produtos);
    exit;
}

// ======================
// REGISTRAR MOVIMENTAÇÃO
// ======================
if ($action === "registrarMovimentacao") {
    $produtoId = intval($_POST['produto_id'] ?? 0);
    $tipo = $_POST['tipo'] ?? "";
    $quantidade = intval($_POST['quantidade'] ?? 0);

    if ($produtoId <= 0 || $quantidade <= 0 || !in_array($tipo, ["entrada", "saida"])) {
        echo "Dados inválidos!";
        exit;
    }

    // Atualizar estoque
    if ($tipo === "entrada") {
        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
    } else {
        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
    }
    $stmt->bind_param("ii", $quantidade, $produtoId);
    $stmt->execute();
    $stmt->close();

    // Registrar movimentação
    $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("isi", $produtoId, $tipo, $quantidade);
    $stmt->execute();
    $stmt->close();

    echo "Movimentação registrada!";
    exit;
}

// ======================
// RELATÓRIO
// ======================
if ($action === "relatorio") {
    $inicio = $_GET['inicio'] ?? "";
    $fim = $_GET['fim'] ?? "";

    if ($inicio === "" || $fim === "") {
        echo json_encode([]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT p.nome AS produto, m.tipo, m.quantidade, DATE_FORMAT(m.data, '%d/%m/%Y %H:%i') as data
        FROM movimentacoes m
        INNER JOIN produtos p ON p.id = m.produto_id
        WHERE m.data BETWEEN ? AND ?
        ORDER BY m.data DESC
    ");
    $stmt->bind_param("ss", $inicio, $fim);
    $stmt->execute();
    $result = $stmt->get_result();

    $movs = [];
    while ($row = $result->fetch_assoc()) {
        $movs[] = $row;
    }

    echo json_encode($movs);
    exit;
}

// ======================
// EXPORTAR PRODUTOS PDF
// ======================
if ($action === "exportarProdutosPDF") {
    require_once("../lib/fpdf.php");

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont("Arial", "B", 16);
    $pdf->Cell(190, 10, "Relatorio de Produtos", 0, 1, "C");
    $pdf->Ln(10);

    $pdf->SetFont("Arial", "B", 12);
    $pdf->Cell(100, 10, "Produto", 1);
    $pdf->Cell(40, 10, "Quantidade", 1);
    $pdf->Ln();

    $result = $conn->query("SELECT nome, quantidade FROM produtos ORDER BY nome ASC");
    $pdf->SetFont("Arial", "", 12);
    while ($row = $result->fetch_assoc()) {
        $pdf->Cell(100, 10, $row['nome'], 1);
        $pdf->Cell(40, 10, $row['quantidade'], 1);
        $pdf->Ln();
    }

    $pdf->Output();
    exit;
}

// ======================
// EXPORTAR RELATORIO PDF
// ======================
if ($action === "exportarRelatorioPDF") {
    require_once("../lib/fpdf.php");

    $inicio = $_GET['inicio'] ?? "2000-01-01";
    $fim = $_GET['fim'] ?? date("Y-m-d");

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont("Arial", "B", 16);
    $pdf->Cell(190, 10, "Relatorio de Movimentacoes", 0, 1, "C");
    $pdf->Ln(10);

    $pdf->SetFont("Arial", "B", 12);
    $pdf->Cell(60, 10, "Produto", 1);
    $pdf->Cell(30, 10, "Tipo", 1);
    $pdf->Cell(40, 10, "Quantidade", 1);
    $pdf->Cell(60, 10, "Data", 1);
    $pdf->Ln();

    $stmt = $conn->prepare("
        SELECT p.nome AS produto, m.tipo, m.quantidade, DATE_FORMAT(m.data, '%d/%m/%Y %H:%i') as data
        FROM movimentacoes m
        INNER JOIN produtos p ON p.id = m.produto_id
        WHERE m.data BETWEEN ? AND ?
        ORDER BY m.data DESC
    ");
    $stmt->bind_param("ss", $inicio, $fim);
    $stmt->execute();
    $result = $stmt->get_result();

    $pdf->SetFont("Arial", "", 12);
    while ($row = $result->fetch_assoc()) {
        $pdf->Cell(60, 10, $row['produto'], 1);
        $pdf->Cell(30, 10, ucfirst($row['tipo']), 1);
        $pdf->Cell(40, 10, $row['quantidade'], 1);
        $pdf->Cell(60, 10, $row['data'], 1);
        $pdf->Ln();
    }

    $pdf->Output();
    exit;
}

echo "Ação inválida!";
