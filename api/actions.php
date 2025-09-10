<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

// 🔧 DEBUG: logar erros
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");

// Funções utilitárias
function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}
function read_body() {
    $body = file_get_contents("php://input");
    return json_decode($body, true) ?? [];
}

// Usa conexão centralizada
require_once __DIR__ . "/db.php";
$conn = db();

$acao = $_GET["acao"] ?? $_POST["acao"] ?? "";

switch ($acao) {

    // 🔑 CHECA SE ESTÁ LOGADO
    case "check_session":
        if (isset($_SESSION["usuario"])) {
            echo json_encode(resposta(true, "Usuário logado.", ["usuario" => $_SESSION["usuario"]]));
        } else {
            echo json_encode(resposta(false, "Não logado."));
        }
        break;

    // ======================
    // PRODUTOS
    // ======================
    case "listar_produtos":
        $result = $conn->query("SELECT * FROM produtos ORDER BY nome ASC");
        $produtos = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(resposta(true, "", $produtos));
        break;

    case "adicionar_produto":
        $body = read_body();
        $nome = trim($body["nome"] ?? "");
        $quantidade = (int)($body["quantidade"] ?? 0);

        if ($nome === "" || $quantidade < 0) {
            echo json_encode(resposta(false, "Dados inválidos."));
            break;
        }

        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
        $stmt->bind_param("si", $nome, $quantidade);
        if ($stmt->execute()) {
            echo json_encode(resposta(true, "Produto adicionado."));
        } else {
            echo json_encode(resposta(false, "Erro ao adicionar produto: " . $conn->error));
        }
        break;

    case "remover_produto":
        $id = (int)($_GET["id"] ?? 0);
        if ($id <= 0) {
            echo json_encode(resposta(false, "ID inválido."));
            break;
        }

        $conn->begin_transaction();
        try {
            // registra movimentação de remoção
            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) 
                                    VALUES (?, 'remocao', 0, NOW())");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            // deleta produto
            $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new Exception("Produto não encontrado.");
            }

            $conn->commit();
            echo json_encode(resposta(true, "Produto removido."));
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(resposta(false, "Erro ao remover produto: " . $e->getMessage()));
        }
        break;

    // ======================
    // MOVIMENTAÇÕES
    // ======================
    case "listar_movimentacoes":
        $result = $conn->query("SELECT m.id, p.nome AS produto, m.tipo, m.quantidade, m.data 
                                FROM movimentacoes m 
                                JOIN produtos p ON m.produto_id = p.id 
                                ORDER BY m.data DESC");
        $movs = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(resposta(true, "", $movs));
        break;

    case "registrar_movimentacao":
        $body = read_body();
        $produto_id = (int)($body["produto_id"] ?? 0);
        $tipo = $body["tipo"] ?? "";
        $quantidade = (int)($body["quantidade"] ?? 0);

        if ($produto_id <= 0 || !in_array($tipo, ["entrada", "saida"]) || $quantidade <= 0) {
            echo json_encode(resposta(false, "Dados inválidos."));
            break;
        }

        $conn->begin_transaction();
        try {
            if ($tipo === "entrada") {
                $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
                $stmt->bind_param("ii", $quantidade, $produto_id);
                $stmt->execute();
            } else {
                $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
                $stmt->bind_param("iii", $quantidade, $produto_id, $quantidade);
                $stmt->execute();

                if ($stmt->affected_rows === 0) {
                    throw new Exception("Estoque insuficiente.");
                }
            }

            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) 
                                    VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("isi", $produto_id, $tipo, $quantidade);
            $stmt->execute();

            $conn->commit();
            echo json_encode(resposta(true, "Movimentação registrada."));
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(resposta(false, $e->getMessage()));
        }
        break;

    // ======================
    // DEFAULT
    // ======================
    default:
        echo json_encode(resposta(false, "Ação inválida."));
        break;
}
?>
