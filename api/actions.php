<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

// Abre conexão
$conn = db();

/**
 * Helper seguro para obter nome do produto
 */
function obterNomeProduto(mysqli $conn, int $id): string {
    $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    return $row && !empty($row['nome']) ? $row['nome'] : "Produto removido";
}

/**
 * Lê a ação (GET/POST) — mantemos 'acao' como seu front já faz
 */
$acao = $_GET["acao"] ?? $_POST["acao"] ?? null;

if (!$acao) {
    echo json_encode(["sucesso" => false, "mensagem" => "Nenhuma ação especificada"]);
    exit;
}

try {
    switch ($acao) {
        case "testeconexao":
            echo json_encode(["sucesso" => true, "mensagem" => "Conexão OK"]);
            break;

        case "listarprodutos":
            $sql = "SELECT * FROM produtos ORDER BY nome ASC";
            $res = $conn->query($sql);
            $produtos = [];
            while ($row = $res->fetch_assoc()) {
                $produtos[] = $row;
            }
            // Retorna array puro porque o seu front já espera assim
            echo json_encode($produtos);
            break;

        case "listarmovimentacoes":
            $sql = "SELECT 
                        m.id,
                        COALESCE(m.produto_nome, p.nome, 'Produto removido') AS produto_nome,
                        m.tipo,
                        m.quantidade,
                        m.data,
                        m.usuario,
                        m.responsavel
                    FROM movimentacoes m
                    LEFT JOIN produtos p ON m.produto_id = p.id
                    ORDER BY m.data DESC";
            $res = $conn->query($sql);
            $movs = [];
            while ($row = $res->fetch_assoc()) {
                $movs[] = $row;
            }
            echo json_encode($movs);
            break;

        case "adicionar":
            $nome        = isset($_POST["nome"]) ? trim($_POST["nome"]) : null;
            $quantidade  = (int) ($_POST["quantidade"] ?? 0);
            $usuario     = $_POST["usuario"] ?? "sistema";
            $responsavel = $_POST["responsavel"] ?? "admin";

            if (!$nome || $quantidade <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
                break;
            }

            // Inserir/atualizar produto
            $stmt = $conn->prepare("
                INSERT INTO produtos (nome, quantidade) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)
            ");
            $stmt->bind_param("si", $nome, $quantidade);

            if (!$stmt->execute()) {
                echo json_encode(["sucesso" => false, "mensagem" => "Erro: " . $stmt->error]);
                break;
            }

            // Descobrir id do produto de forma segura
            $produto_id = $conn->insert_id;
            if ($produto_id <= 0) {
                $stmt2 = $conn->prepare("SELECT id FROM produtos WHERE nome = ? LIMIT 1");
                $stmt2->bind_param("s", $nome);
                $stmt2->execute();
                $res = $stmt2->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $produto_id = $row ? (int)$row['id'] : 0;
            }

            // Log movimentação (entrada)
            $stmtMov = $conn->prepare("
                INSERT INTO movimentacoes 
                    (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel) 
                VALUES (?, ?, 'entrada', ?, NOW(), ?, ?)
            ");
            $stmtMov->bind_param("isiss", $produto_id, $nome, $quantidade, $usuario, $responsavel);
            $stmtMov->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado com sucesso"]);
            break;

        case "entrada":
            $id          = (int) ($_POST["id"] ?? 0);
            $quantidade  = (int) ($_POST["quantidade"] ?? 0);
            $usuario     = $_POST["usuario"] ?? "sistema";
            $responsavel = $_POST["responsavel"] ?? "admin";

            if ($id <= 0 || $quantidade <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
                break;
            }

            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
            $stmt->bind_param("ii", $quantidade, $id);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $nomeProduto = obterNomeProduto($conn, $id);

                $stmtMov = $conn->prepare("
                    INSERT INTO movimentacoes 
                        (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel) 
                    VALUES (?, ?, 'entrada', ?, NOW(), ?, ?)
                ");
                $stmtMov->bind_param("isiss", $id, $nomeProduto, $quantidade, $usuario, $responsavel);
                $stmtMov->execute();

                echo json_encode(["sucesso" => true, "mensagem" => "Entrada registrada"]);
            } else {
                echo json_encode(["sucesso" => false, "mensagem" => "Erro ao registrar entrada"]);
            }
            break;

        case "saida":
            $id          = (int) ($_POST["id"] ?? 0);
            $quantidade  = (int) ($_POST["quantidade"] ?? 0);
            $usuario     = $_POST["usuario"] ?? "sistema";
            $responsavel = $_POST["responsavel"] ?? "admin";

            if ($id <= 0 || $quantidade <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
                break;
            }

            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
            $stmt->bind_param("iii", $quantidade, $id, $quantidade);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $nomeProduto = obterNomeProduto($conn, $id);

                $stmtMov = $conn->prepare("
                    INSERT INTO movimentacoes 
                        (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel) 
                    VALUES (?, ?, 'saida', ?, NOW(), ?, ?)
                ");
                $stmtMov->bind_param("isiss", $id, $nomeProduto, $quantidade, $usuario, $responsavel);
                $stmtMov->execute();

                echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
            } else {
                echo json_encode(["sucesso" => false, "mensagem" => "Quantidade insuficiente ou produto inexistente"]);
            }
            break;

        case "remover":
            $id          = (int) ($_POST["id"] ?? $_GET["id"] ?? 0);
            $usuario     = $_POST["usuario"] ?? "sistema";
            $responsavel = $_POST["responsavel"] ?? "admin";

            if ($id <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "ID inválido"]);
                break;
            }

            // Coleta snapshot do produto antes de remover
            $stmtSnap = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ?");
            $stmtSnap->bind_param("i", $id);
            $stmtSnap->execute();
            $snapRes = $stmtSnap->get_result();
            $produto = $snapRes ? $snapRes->fetch_assoc() : null;

            if ($produto) {
                $nomeProduto = $produto['nome'];
                $qtdAtual    = (int)$produto['quantidade'];

                // Log de remoção (produto_id ainda existe aqui)
                $stmtMov = $conn->prepare("
                    INSERT INTO movimentacoes 
                        (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel) 
                    VALUES (?, ?, 'remocao', ?, NOW(), ?, ?)
                ");
                $stmtMov->bind_param("isiss", $id, $nomeProduto, $qtdAtual, $usuario, $responsavel);
                $stmtMov->execute();
            }

            // Remove o produto
            $stmtDel = $conn->prepare("DELETE FROM produtos WHERE id = ?");
            $stmtDel->bind_param("i", $id);

            if ($stmtDel->execute() && $stmtDel->affected_rows > 0) {
                echo json_encode(["sucesso" => true, "mensagem" => "Produto removido com sucesso"]);
            } else {
                echo json_encode(["sucesso" => false, "mensagem" => "Erro ao remover produto ou produto inexistente"]);
            }
            break;

        default:
            echo json_encode(["sucesso" => false, "mensagem" => "Ação inválida"]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro interno: " . $e->getMessage()]);
} finally {
    $conn->close();
}
