<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

// Função padrão de saída JSON
function json_out($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$acao = strtolower($_GET['acao'] ?? $_POST['acao'] ?? '');
$params = $_POST + $_GET;

// Ações aceitas
$acoesAceitas = [
    "listar", "listarprodutos", "listarmovimentacoes",
    "cadastrar", "adicionar", "adicionarproduto",
    "entrada", "entradaproduto", "saida", "saidaproduto",
    "remover", "removerproduto", "relatorio",
    "testeconexao", "exportarpdf", "exportarexcel"
];

if (!$acao || !in_array($acao, $acoesAceitas)) {
    json_out([
        "erro" => "Ação inválida",
        "recebido" => $acao,
        "acoesAceitas" => $acoesAceitas
    ]);
}

$conn = db();

/**
 * Função para obter movimentações com filtros
 */
function getMovimentacoes($conn, $params) {
    $where = [];
    $binds = [];
    $types = "";

    if (!empty($params['tipo'])) {
        $where[] = "m.tipo = ?";
        $binds[] = $params['tipo'];
        $types .= "s";
    }

    if (!empty($params['produto'])) {
        $where[] = "(m.produto_nome LIKE ? OR p.nome LIKE ?)";
        $like = "%" . $params['produto'] . "%";
        $binds[] = $like;
        $binds[] = $like;
        $types .= "ss";
    }

    if (!empty($params['inicio']) && !empty($params['fim'])) {
        $where[] = "m.data BETWEEN ? AND ?";
        $binds[] = $params['inicio'] . " 00:00:00";
        $binds[] = $params['fim'] . " 23:59:59";
        $types .= "ss";
    }

    $sql = "
        SELECT m.id, COALESCE(m.produto_nome, p.nome) AS produto_nome,
               m.tipo, m.quantidade, m.data, m.usuario
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
    ";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY m.data DESC, m.id DESC";

    $limit = isset($params['limit']) ? (int)$params['limit'] : 50;
    $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
    $sql .= " LIMIT ? OFFSET ?";
    $types .= "ii";
    $binds[] = $limit;
    $binds[] = $offset;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ["sucesso" => false, "erro" => $conn->error];
    }

    if ($types) {
        $stmt->bind_param($types, ...$binds);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    $stmt->close();

    return ["sucesso" => true, "dados" => $out];
}

// ============ LISTAR PRODUTOS ============
if ($acao === "listar" || $acao === "listarprodutos") {
    $res = $conn->query("SELECT * FROM produtos ORDER BY nome ASC");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    json_out(["sucesso" => true, "dados" => $out]);
}

// ============ LISTAR MOVIMENTAÇÕES ============
if ($acao === "listarmovimentacoes") {
    $dados = getMovimentacoes($conn, $params);
    json_out($dados);
}

// ============ EXPORTAR MOVIMENTAÇÕES ============
if ($acao === "exportarpdf" || $acao === "exportarexcel") {
    $dados = getMovimentacoes($conn, $params);

    if (!$dados['sucesso']) json_out($dados);
    $dados = $dados['dados'];

    if ($acao === "exportarpdf") {
        require_once __DIR__ . "/fpdf.php";
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont("Arial", "B", 14);
        $pdf->Cell(0, 10, "Relatorio de Movimentacoes", 0, 1, "C");
        $pdf->Ln(5);

        $pdf->SetFont("Arial", "B", 10);
        $pdf->Cell(20, 8, "ID", 1);
        $pdf->Cell(60, 8, "Produto", 1);
        $pdf->Cell(30, 8, "Tipo", 1);
        $pdf->Cell(30, 8, "Qtd", 1);
        $pdf->Cell(50, 8, "Data", 1);
        $pdf->Ln();

        $pdf->SetFont("Arial", "", 10);
        foreach ($dados as $d) {
            $pdf->Cell(20, 8, $d['id'], 1);
            $pdf->Cell(60, 8, $d['produto_nome'], 1);
            $pdf->Cell(30, 8, $d['tipo'], 1);
            $pdf->Cell(30, 8, $d['quantidade'], 1);
            $pdf->Cell(50, 8, $d['data'], 1);
            $pdf->Ln();
        }

        $pdf->Output();
        exit;
    }

    if ($acao === "exportarexcel") {
        header("Content-Type: application/vnd.ms-excel");
        header("Content-Disposition: attachment; filename=relatorio.xls");

        echo "ID\tProduto\tTipo\tQuantidade\tData\n";
        foreach ($dados as $d) {
            echo "{$d['id']}\t{$d['produto_nome']}\t{$d['tipo']}\t{$d['quantidade']}\t{$d['data']}\n";
        }
        exit;
    }
}

// ============ TESTE DE CONEXÃO ============
if ($acao === "testeconexao") {
    json_out(["sucesso" => true, "msg" => "Conexao OK"]);
}

json_out(["erro" => "Nada executado"]);
