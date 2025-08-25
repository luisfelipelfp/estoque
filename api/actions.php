<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "db.php";

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

// 🔹 Se ação inválida → erro
if (!$acao || !in_array($acao, $acoesAceitas)) {
    echo json_encode([
        "erro" => "Ação inválida",
        "recebido" => $acao,
        "acoesAceitas" => $acoesAceitas
    ]);
    exit;
}

// 🔹 Implementação de cada ação
switch ($acao) {
    case "testeconexao":
        echo json_encode(["sucesso" => true, "mensagem" => "Conexão OK"]);
        break;

    case "listar":
    case "listarprodutos":
        $sql = "SELECT * FROM produtos ORDER BY nome ASC";
        $res = $conn->query($sql);
        $produtos = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $produtos[] = $row;
            }
        }
        echo json_encode(["sucesso" => true, "dados" => $produtos]);
        break;

    case "listarmovimentacoes":
        $sql = "SELECT m.*, p.nome AS produto_nome
                FROM movimentacoes m
                LEFT JOIN produtos p ON m.produto_id = p.id
                ORDER BY m.data DESC";
        $res = $conn->query($sql);
        $movs = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $movs[] = $row;
            }
        }
        echo json_encode(["sucesso" => true, "dados" => $movs]);
        break;

    case "adicionar":
    case "adicionarproduto":
    case "cadastrar":
        $nome = $_POST['nome'] ?? '';
        $quantidade = intval($_POST['quantidade'] ?? 0);

        if (!$nome) {
            echo json_encode(["sucesso" => false, "erro" => "Nome do produto obrigatório"]);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $quantidade);
        if ($stmt->execute()) {
            echo json_encode(["sucesso" => true, "mensagem" => "Produto cadastrado"]);
        } else {
            echo json_encode(["sucesso" => false, "erro" => $stmt->error]);
        }
        break;

    case "entrada":
    case "entradaproduto":
        $id = intval($_POST['id'] ?? 0);
        $quantidade = intval($_POST['quantidade'] ?? 0);

        if ($id && $quantidade > 0) {
            $conn->query("UPDATE produtos SET quantidade = quantidade + $quantidade WHERE id = $id");
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) 
                          VALUES ($id, 'entrada', $quantidade, NOW())");
            echo json_encode(["sucesso" => true, "mensagem" => "Entrada registrada"]);
        } else {
            echo json_encode(["sucesso" => false, "erro" => "Dados inválidos"]);
        }
        break;

    case "saida":
    case "saidaproduto":
        $id = intval($_POST['id'] ?? 0);
        $quantidade = intval($_POST['quantidade'] ?? 0);

        if ($id && $quantidade > 0) {
            $conn->query("UPDATE produtos SET quantidade = quantidade - $quantidade WHERE id = $id");
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) 
                          VALUES ($id, 'saida', $quantidade, NOW())");
            echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
        } else {
            echo json_encode(["sucesso" => false, "erro" => "Dados inválidos"]);
        }
        break;

    case "remover":
    case "removerproduto":
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("DELETE FROM produtos WHERE id = $id");
            // Para não dar erro no ENUM, registramos a remoção como 'saida' de quantidade atual = 0
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) 
                          VALUES ($id, 'saida', 0, NOW())");
            echo json_encode(["sucesso" => true, "mensagem" => "Produto removido"]);
        } else {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
        }
        break;

    case "relatorio":
        echo json_encode(["sucesso" => true, "mensagem" => "Relatório em construção"]);
        break;

    case "exportarpdf":
        echo json_encode(["sucesso" => true, "mensagem" => "Exportar PDF ainda não implementado"]);
        break;

    case "exportarexcel":
        echo json_encode(["sucesso" => true, "mensagem" => "Exportar Excel ainda não implementado"]);
        break;

    default:
        echo json_encode(["erro" => "Ação não implementada"]);
}
