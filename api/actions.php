<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/db.php";

// 🔹 Ativa exibição de erros (debug)
error_reporting(E_ALL);
ini_set("display_errors", 1);

// 🔹 Função helper para debug
function debugLog($msg) {
    error_log("[".date("Y-m-d H:i:s")."] ".$msg."\n", 3, __DIR__ . "/debug.log");
}

// 🔹 Abre conexão
$conn = db();

// 🔹 Lê JSON cru no corpo e mescla no $_POST
$raw = file_get_contents("php://input");
if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $_POST = array_merge($_POST, $json);
    }
}

// 🔹 Lista de ações aceitas
$acoesAceitas = [
    "listar", "listarprodutos", "listarmovimentacoes",
    "cadastrar", "adicionar", "adicionarproduto",
    "entrada", "entradaproduto",
    "saida", "saidaproduto",
    "remover", "removerproduto",
    "relatorio", "testeconexao"
];

// 🔹 Normaliza a ação recebida
$acao = strtolower($_GET['acao'] ?? $_POST['acao'] ?? '');
debugLog("Ação recebida: ".$acao);

// 🔹 Se ação inválida → erro
if (!$acao || !in_array($acao, $acoesAceitas)) {
    debugLog("Ação inválida: ".$acao);
    echo json_encode([
        "sucesso" => false,
        "erro" => "Ação inválida",
        "recebido" => $acao,
        "acoesAceitas" => $acoesAceitas
    ]);
    exit;
}

// 🔹 Implementação das ações
try {
    switch ($acao) {
        case "testeconexao":
            echo json_encode(["sucesso" => true, "mensagem" => "Conexão OK"]);
            break;

        case "listar":
        case "listarprodutos":
            $sql = "SELECT * FROM produtos ORDER BY nome ASC";
            $res = $conn->query($sql);
            if (!$res) throw new Exception("Erro SQL: " . $conn->error);

            $produtos = [];
            while ($row = $res->fetch_assoc()) {
                $produtos[] = $row;
            }
            echo json_encode(["sucesso" => true, "dados" => $produtos]);
            break;

        case "listarmovimentacoes":
            $sql = "SELECT m.*, p.nome AS produto_nome
                    FROM movimentacoes m
                    LEFT JOIN produtos p ON m.produto_id = p.id
                    ORDER BY m.data DESC";
            $res = $conn->query($sql);
            if (!$res) throw new Exception("Erro SQL: " . $conn->error);

            $movs = [];
            while ($row = $res->fetch_assoc()) {
                $movs[] = $row;
            }
            echo json_encode(["sucesso" => true, "dados" => $movs]);
            break;

        case "cadastrar":
        case "adicionar":
        case "adicionarproduto":
            $nome = trim($_POST['nome'] ?? '');
            $quantidade = intval($_POST['quantidade'] ?? 0);
            if (!$nome) throw new Exception("Nome do produto é obrigatório");

            $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?) ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)");
            $stmt->bind_param("si", $nome, $quantidade);
            if (!$stmt->execute()) throw new Exception("Erro ao cadastrar produto: " . $stmt->error);

            $produtoId = $conn->insert_id ?: $conn->query("SELECT id FROM produtos WHERE nome='$nome'")->fetch_assoc()['id'];

            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data) VALUES (?, ?, 'entrada', ?, NOW())");
            $stmt->bind_param("isi", $produtoId, $nome, $quantidade);
            $stmt->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Produto cadastrado/atualizado"]);
            break;

        case "entrada":
        case "entradaproduto":
            $produtoId = intval($_POST['produto_id'] ?? $_POST['id'] ?? 0);
            $quantidade = intval($_POST['quantidade'] ?? 0);
            if ($produtoId <= 0 || $quantidade <= 0) throw new Exception("Dados inválidos para entrada");

            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
            $stmt->bind_param("ii", $quantidade, $produtoId);
            if (!$stmt->execute()) throw new Exception("Erro na entrada: " . $stmt->error);

            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'entrada', ?, NOW())");
            $stmt->bind_param("ii", $produtoId, $quantidade);
            $stmt->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Entrada registrada"]);
            break;

        case "saida":
        case "saidaproduto":
            $produtoId = intval($_POST['produto_id'] ?? $_POST['id'] ?? 0);
            $quantidade = intval($_POST['quantidade'] ?? 0);
            if ($produtoId <= 0 || $quantidade <= 0) throw new Exception("Dados inválidos para saída");

            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
            $stmt->bind_param("iii", $quantidade, $produtoId, $quantidade);
            if (!$stmt->execute()) throw new Exception("Erro na saída: " . $stmt->error);

            if ($stmt->affected_rows == 0) throw new Exception("Estoque insuficiente");

            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'saida', ?, NOW())");
            $stmt->bind_param("ii", $produtoId, $quantidade);
            $stmt->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
            break;

        case "remover":
        case "removerproduto":
            $produtoId = intval($_POST['produto_id'] ?? $_POST['id'] ?? 0);
            if ($produtoId <= 0) throw new Exception("ID inválido para remoção");

            $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
            $stmt->bind_param("i", $produtoId);
            if (!$stmt->execute()) throw new Exception("Erro ao remover produto: " . $stmt->error);

            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) VALUES (?, 'remocao', 0, NOW())");
            $stmt->bind_param("i", $produtoId);
            $stmt->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Produto removido"]);
            break;

        case "relatorio":
            $sql = "SELECT nome, quantidade FROM produtos ORDER BY nome ASC";
            $res = $conn->query($sql);
            if (!$res) throw new Exception("Erro SQL: " . $conn->error);

            $relatorio = [];
            while ($row = $res->fetch_assoc()) {
                $relatorio[] = $row;
            }
            echo json_encode(["sucesso" => true, "dados" => $relatorio]);
            break;

        default:
            echo json_encode(["sucesso" => false, "erro" => "Ação não implementada"]);
    }
} catch (Exception $e) {
    debugLog("Exceção: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(["sucesso" => false, "erro" => $e->getMessage()]);
}

// 🔹 Fecha conexão
$conn->close();
?>
