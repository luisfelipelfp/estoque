<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

// (opcional) debug local
// error_reporting(E_ALL); ini_set('display_errors', 1);

$conn = db();

/** Helper: pega nome do produto (ou "Produto removido") */
function obterNomeProduto(mysqli $conn, int $id): string {
    $stmt = $conn->prepare("SELECT nome FROM produtos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    return $row && !empty($row['nome']) ? $row['nome'] : "Produto removido";
}

/** Aceita JSON no corpo também */
$raw = file_get_contents("php://input");
if ($raw && ($raw[0] === '{' || $raw[0] === '[')) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $_POST = array_merge($_POST, $json);
    }
}

/** Normaliza ação */
$acao = strtolower(trim($_GET['acao'] ?? $_POST['acao'] ?? $_GET['action'] ?? $_POST['action'] ?? ''));

if ($acao === '') {
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
            while ($row = $res->fetch_assoc()) $produtos[] = $row;
            echo json_encode($produtos);
            break;

        case "listarmovimentacoes":
            // paginação
            $pagina = max(1, (int)($_GET["pagina"] ?? $_POST["pagina"] ?? 1));
            $limite = max(1, (int)($_GET["limite"] ?? $_POST["limite"] ?? 20));
            $offset = ($pagina - 1) * $limite;

            // filtros
            $cond = [];
            $bindVals = [];
            $bindTypes = "";

            $tipo = $_GET["tipo"] ?? $_POST["tipo"] ?? "";
            if ($tipo !== "") {
                $cond[] = "m.tipo = ?";
                $bindVals[] = $tipo;
                $bindTypes .= "s";
            }

            $produto = $_GET["produto"] ?? $_POST["produto"] ?? "";
            if ($produto !== "") {
                $cond[] = "m.produto_nome LIKE ?";
                $bindVals[] = "%".$produto."%";
                $bindTypes .= "s";
            }

            $usuario = $_GET["usuario"] ?? $_POST["usuario"] ?? "";
            if ($usuario !== "") {
                $cond[] = "m.usuario = ?";
                $bindVals[] = $usuario;
                $bindTypes .= "s";
            }

            $responsavel = $_GET["responsavel"] ?? $_POST["responsavel"] ?? "";
            if ($responsavel !== "") {
                $cond[] = "m.responsavel = ?";
                $bindVals[] = $responsavel;
                $bindTypes .= "s";
            }

            $data_inicio = $_GET["data_inicio"] ?? $_POST["data_inicio"] ?? "";
            if ($data_inicio !== "") {
                $cond[] = "m.data >= ?";
                $bindVals[] = $data_inicio;
                $bindTypes .= "s";
            }

            $data_fim = $_GET["data_fim"] ?? $_POST["data_fim"] ?? "";
            if ($data_fim !== "") {
                $cond[] = "m.data <= ?";
                $bindVals[] = $data_fim;
                $bindTypes .= "s";
            }

            $where = $cond ? ("WHERE ".implode(" AND ", $cond)) : "";

            // total
            $sqlTotal = "SELECT COUNT(*) AS total FROM movimentacoes m $where";
            $stmtTotal = $conn->prepare($sqlTotal);
            if ($bindVals) $stmtTotal->bind_param($bindTypes, ...$bindVals);
            $stmtTotal->execute();
            $total = (int)($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);

            // dados paginados
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
            if ($bindVals) {
                $types = $bindTypes . "ii";
                $vals = array_merge($bindVals, [$limite, $offset]);
                $stmt->bind_param($types, ...$vals);
            } else {
                $stmt->bind_param("ii", $limite, $offset);
            }
            $stmt->execute();
            $res = $stmt->get_result();

            $movs = [];
            while ($row = $res->fetch_assoc()) $movs[] = $row;

            echo json_encode([
                "sucesso" => true,
                "total"   => $total,
                "pagina"  => $pagina,
                "limite"  => $limite,
                "paginas" => (int)ceil($total / $limite),
                "dados"   => $movs
            ]);
            break;

        case "adicionar":
            // permite cadastrar com quantidade 0
            $nome = trim($_POST["nome"] ?? "");
            $quantidade = isset($_POST["quantidade"]) ? (int)$_POST["quantidade"] : 0;
            $usuario = $_POST["usuario"] ?? "sistema";
            $responsavel = $_POST["responsavel"] ?? "admin";

            if ($nome === "") {
                echo json_encode(["sucesso" => false, "mensagem" => "Nome do produto é obrigatório"]);
                break;
            }

            // Se existir UNIQUE em nome, isso soma estoque; se não existir UNIQUE, apenas insere um novo
            $stmt = $conn->prepare("
                INSERT INTO produtos (nome, quantidade) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)
            ");
            $stmt->bind_param("si", $nome, $quantidade);
            if (!$stmt->execute()) {
                echo json_encode(["sucesso" => false, "mensagem" => "Erro ao adicionar: ".$stmt->error]);
                break;
            }

            $produto_id = $conn->insert_id;
            if ($produto_id === 0) {
                // caiu no UPDATE (produto já existia); buscar id
                $stmt2 = $conn->prepare("SELECT id FROM produtos WHERE nome = ? LIMIT 1");
                $stmt2->bind_param("s", $nome);
                $stmt2->execute();
                $r = $stmt2->get_result()->fetch_assoc();
                $produto_id = (int)($r['id'] ?? 0);
            }

            // registra movimentação apenas se entrou estoque > 0
            if ($quantidade > 0 && $produto_id > 0) {
                $stmt3 = $conn->prepare("
                    INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
                    VALUES (?, ?, 'entrada', ?, NOW(), ?, ?)
                ");
                $stmt3->bind_param("isiss", $produto_id, $nome, $quantidade, $usuario, $responsavel);
                $stmt3->execute();
            }

            echo json_encode(["sucesso" => true, "mensagem" => "Produto cadastrado/atualizado"]);
            break;

        case "entrada":
            $id = (int)($_POST["id"] ?? 0);
            $quantidade = (int)($_POST["quantidade"] ?? 0);
            $usuario = $_POST["usuario"] ?? "sistema";
            $responsavel = $_POST["responsavel"] ?? "admin";

            if ($id <= 0 || $quantidade <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
                break;
            }

            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade + ? WHERE id = ?");
            $stmt->bind_param("ii", $quantidade, $id);
            if (!$stmt->execute()) {
                echo json_encode(["sucesso" => false, "mensagem" => "Erro ao atualizar estoque"]);
                break;
            }

            $nome = obterNomeProduto($conn, $id);
            $stmt2 = $conn->prepare("
                INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
                VALUES (?, ?, 'entrada', ?, NOW(), ?, ?)
            ");
            $stmt2->bind_param("isiss", $id, $nome, $quantidade, $usuario, $responsavel);
            $stmt2->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Entrada registrada"]);
            break;

        case "saida":
            $id = (int)($_POST["id"] ?? 0);
            $quantidade = (int)($_POST["quantidade"] ?? 0);
            $usuario = $_POST["usuario"] ?? "sistema";
            $responsavel = $_POST["responsavel"] ?? "admin";

            if ($id <= 0 || $quantidade <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
                break;
            }

            // só permite saída se tiver saldo suficiente
            $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
            $stmt->bind_param("iii", $quantidade, $id, $quantidade);
            $stmt->execute();

            if ($stmt->affected_rows <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "Estoque insuficiente ou produto inexistente"]);
                break;
            }

            $nome = obterNomeProduto($conn, $id);
            $stmt2 = $conn->prepare("
                INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
                VALUES (?, ?, 'saida', ?, NOW(), ?, ?)
            ");
            $stmt2->bind_param("isiss", $id, $nome, $quantidade, $usuario, $responsavel);
            $stmt2->execute();

            echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
            break;

        case "remover":
            $id = (int)($_POST["id"] ?? $_GET["id"] ?? 0);
            $usuario = $_POST["usuario"] ?? "sistema";
            $responsavel = $_POST["responsavel"] ?? "admin";

            if ($id <= 0) {
                echo json_encode(["sucesso" => false, "mensagem" => "ID inválido"]);
                break;
            }

            // pega dados antes de remover
            $stmtSel = $conn->prepare("SELECT nome, quantidade FROM produtos WHERE id = ?");
            $stmtSel->bind_param("i", $id);
            $stmtSel->execute();
            $prod = $stmtSel->get_result()->fetch_assoc();

            if (!$prod) {
                echo json_encode(["sucesso" => false, "mensagem" => "Produto inexistente"]);
                break;
            }

            $nome = $prod['nome'] ?? "Produto removido";
            $qtdRemovida = (int)($prod['quantidade'] ?? 0);

            // registra a remoção com a quantidade atual
            $stmtMov = $conn->prepare("
                INSERT INTO movimentacoes (produto_id, produto_nome, tipo, quantidade, data, usuario, responsavel)
                VALUES (?, ?, 'remocao', ?, NOW(), ?, ?)
            ");
            $stmtMov->bind_param("isiss", $id, $nome, $qtdRemovida, $usuario, $responsavel);
            $stmtMov->execute();

            // remove o produto
            $stmtDel = $conn->prepare("DELETE FROM produtos WHERE id = ?");
            $stmtDel->bind_param("i", $id);
            $stmtDel->execute();

            if ($stmtDel->affected_rows > 0) {
                echo json_encode(["sucesso" => true, "mensagem" => "Produto removido com sucesso"]);
            } else {
                echo json_encode(["sucesso" => false, "mensagem" => "Erro ao remover produto"]);
            }
            break;

        default:
            echo json_encode([
                "sucesso" => false,
                "mensagem" => "Ação inválida",
                "acao_recebida" => $acao
            ]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro interno: " . $e->getMessage()]);
} finally {
    $conn->close();
}
