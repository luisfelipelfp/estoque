<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

$conn = db();

function obterNomeProduto(mysqli $conn, int $id): string {
    $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    return $row && !empty($row['nome']) ? $row['nome'] : "Produto removido";
}

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
            echo json_encode($produtos);
            break;

        case "listarmovimentacoes":
            $pagina = max(1, (int)($_GET["pagina"] ?? 1));
            $limite = max(1, (int)($_GET["limite"] ?? 20));
            $offset = ($pagina - 1) * $limite;

            $condicoes = [];
            $params = [];
            $tipos = "";

            if (!empty($_GET["tipo"])) {
                $condicoes[] = "m.tipo = ?";
                $params[] = $_GET["tipo"];
                $tipos .= "s";
            }
            if (!empty($_GET["produto"])) {
                $condicoes[] = "m.produto_nome LIKE ?";
                $params[] = "%" . $_GET["produto"] . "%";
                $tipos .= "s";
            }
            if (!empty($_GET["usuario"])) {
                $condicoes[] = "m.usuario = ?";
                $params[] = $_GET["usuario"];
                $tipos .= "s";
            }
            if (!empty($_GET["responsavel"])) {
                $condicoes[] = "m.responsavel = ?";
                $params[] = $_GET["responsavel"];
                $tipos .= "s";
            }
            if (!empty($_GET["data_inicio"])) {
                $condicoes[] = "m.data >= ?";
                $params[] = $_GET["data_inicio"];
                $tipos .= "s";
            }
            if (!empty($_GET["data_fim"])) {
                $condicoes[] = "m.data <= ?";
                $params[] = $_GET["data_fim"];
                $tipos .= "s";
            }

            $where = $condicoes ? "WHERE " . implode(" AND ", $condicoes) : "";

            // Total
            $sqlTotal = "SELECT COUNT(*) as total FROM movimentacoes m $where";
            $stmtTotal = $conn->prepare($sqlTotal);
            if ($params) $stmtTotal->bind_param($tipos, ...$params);
            $stmtTotal->execute();
            $total = ($stmtTotal->get_result()->fetch_assoc())["total"] ?? 0;

            // Dados paginados
            $sql = "SELECT 
                        m.id,
                        COALESCE(m.produto_nome, 'Produto removido') AS produto_nome,
                        m.tipo,
                        m.quantidade,
                        m.data,
                        m.usuario,
                        m.responsavel
                    FROM movimentacoes m
                    $where
                    ORDER BY m.data DESC
                    LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($params) {
                $tiposFull = $tipos . "ii";
                $paramsFull = array_merge($params, [$limite, $offset]);
                $stmt->bind_param($tiposFull, ...$paramsFull);
            } else {
                $stmt->bind_param("ii", $limite, $offset);
            }
            $stmt->execute();
            $res = $stmt->get_result();

            $movs = [];
            while ($row = $res->fetch_assoc()) {
                $movs[] = $row;
            }

            echo json_encode([
                "sucesso" => true,
                "total" => (int)$total,
                "pagina" => $pagina,
                "limite" => $limite,
                "paginas" => ceil($total / $limite),
                "dados" => $movs
            ]);
            break;

        case "adicionar":
            $nome = $_POST["nome"] ?? null;
            $quantidade = (int)($_POST["quantidade"] ?? 0);

            if (!$nome || $quantidade <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Nome e quantidade obrigatórios"]);
                exit;
            }

            $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?)");
            $stmt->bind_param("si", $nome, $quantidade);
            $stmt->execute();
            $produto_id = $stmt->insert_id;

            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data) VALUES (?, ?, 'entrada', ?, NOW())");
            $stmt->bind_param("isi", $produto_id, $nome, $quantidade);
            $stmt->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado"]);
            break;

        case "entrada":
            $id = (int)($_POST["id"] ?? 0);
            $quantidade = (int)($_POST["quantidade"] ?? 0);

            if ($id <= 0 || $quantidade <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
                exit;
            }

            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
            $stmt->bind_param("ii", $quantidade, $id);
            $stmt->execute();

            $nome = obterNomeProduto($conn, $id);
            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data) VALUES (?, ?, 'entrada', ?, NOW())");
            $stmt->bind_param("isi", $id, $nome, $quantidade);
            $stmt->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Entrada registrada"]);
            break;

        case "saida":
            $id = (int)($_POST["id"] ?? 0);
            $quantidade = (int)($_POST["quantidade"] ?? 0);

            if ($id <= 0 || $quantidade <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
                exit;
            }

            $stmt = $conn->prepare("UPDATE produtos SET quantidade = GREATEST(quantidade - ?, 0) WHERE id = ?");
            $stmt->bind_param("ii", $quantidade, $id);
            $stmt->execute();

            $nome = obterNomeProduto($conn, $id);
            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data) VALUES (?, ?, 'saida', ?, NOW())");
            $stmt->bind_param("isi", $id, $nome, $quantidade);
            $stmt->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
            break;

        case "remover":
            $id = (int)($_POST["id"] ?? 0);
            if ($id <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "ID inválido"]);
                exit;
            }

            $nome = obterNomeProduto($conn, $id);

            $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data) VALUES (?, ?, 'remocao', 0, NOW())");
            $stmt->bind_param("is", $id, $nome);
            $stmt->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Produto removido"]);
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
