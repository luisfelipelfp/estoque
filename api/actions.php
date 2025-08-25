<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/db.php";

// 🔹 Ativa exibição de erros (debug)
error_reporting(E_ALL);
ini_set("display_errors", 1);

// 🔹 Função helper para debug
function debugLog($msg) {
    file_put_contents(__DIR__ . "/debug.log", "[".date("Y-m-d H:i:s")."] ".$msg."\n", FILE_APPEND);
}

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
    "relatorio", "testeconexao",
    "exportarpdf", "exportarexcel"
];

// 🔹 Normaliza a ação recebida
$acao = strtolower($_GET['acao'] ?? $_POST['acao'] ?? '');
debugLog("Ação recebida: ".$acao);

// 🔹 Se ação inválida → erro
if (!$acao || !in_array($acao, $acoesAceitas)) {
    debugLog("Ação inválida: ".$acao);
    echo json_encode([
        "erro" => "Ação inválida",
        "recebido" => $acao,
        "acoesAceitas" => $acoesAceitas
    ]);
    exit;
}

// 🔹 Implementação de cada ação
try {
    switch ($acao) {
        case "testeconexao":
            echo json_encode(["sucesso" => true, "mensagem" => "Conexão OK"]);
            break;

        case "listar":
        case "listarprodutos":
            debugLog("Executando listarprodutos");
            $sql = "SELECT * FROM produtos ORDER BY nome ASC";
            $res = $conn->query($sql);
            if (!$res) {
                throw new Exception("Erro SQL: " . $conn->error);
            }
            $produtos = [];
            while ($row = $res->fetch_assoc()) {
                $produtos[] = $row;
            }
            echo json_encode(["sucesso" => true, "dados" => $produtos]);
            break;

        case "listarmovimentacoes":
            debugLog("Executando listarmovimentacoes");
            $sql = "SELECT m.*, p.nome AS produto_nome
                    FROM movimentacoes m
                    LEFT JOIN produtos p ON m.produto_id = p.id
                    ORDER BY m.data DESC";
            $res = $conn->query($sql);
            if (!$res) {
                throw new Exception("Erro SQL: " . $conn->error);
            }
            $movs = [];
            while ($row = $res->fetch_assoc()) {
                $movs[] = $row;
            }
            echo json_encode(["sucesso" => true, "dados" => $movs]);
            break;

        // ... (resto do seu código igual, sem mudança) ...

        default:
            debugLog("Ação não implementada: ".$acao);
            echo json_encode(["erro" => "Ação não implementada"]);
    }
} catch (Exception $e) {
    debugLog("Exceção: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(["sucesso" => false, "erro" => $e->getMessage()]);
}
