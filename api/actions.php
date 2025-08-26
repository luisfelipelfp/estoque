<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

$conn = db();

$acao = $_GET["acao"] ?? $_POST["acao"] ?? null;

if (!$acao) {
    echo json_encode(["sucesso" => false, "mensagem" => "Nenhuma ação especificada"]);
    exit;
}

switch ($acao) {
    case "testeconexao":
        echo json_encode(["sucesso" => true, "mensagem" => "Conexão OK"]);
        break;

    case "listarprodutos":
        $sql = "SELECT * FROM produtos";
        $res = $conn->query($sql);
        $produtos = [];
        while ($row = $res->fetch_assoc()) {
            $produtos[] = $row;
        }
        echo json_encode($produtos);
        break;

    case "listarmovimentacoes":
        // Parâmetros de filtro
        $tipo = $_GET["tipo"] ?? $_POST["tipo"] ?? null;
        $produto = $_GET["produto"] ?? $_POST["produto"] ?? null;
        $usuario = $_GET["usuario"] ?? $_POST["usuario"] ?? null;
        $responsavel = $_GET["responsavel"] ?? $_POST["responsavel"] ?? null;
        $data_inicio = $_GET["data_inicio"] ?? $_POST["data_inicio"] ?? null;
        $data_fim = $_GET["data_fim"] ?? $_POST["data_fim"] ?? null;

        // Paginação
        $pagina = max(1, (int)($_GET["pagina"] ?? $_POST["pagina"] ?? 1));
        $limite = max(1, (int)($_GET["limite"] ?? $_POST["limite"] ?? 20));
        $offset = ($pagina - 1) * $limite;

        $where = [];
        if ($tipo) $where[] = "m.tipo = '" . $conn->real_escape_string($tipo) . "'";
        if ($produto) $where[] = "p.nome LIKE '%" . $conn->real_escape_string($produto) . "%'";
        if ($usuario) $where[] = "m.usuario LIKE '%" . $conn->real_escape_string($usuario) . "%'";
        if ($responsavel) $where[] = "m.responsavel LIKE '%" . $conn->real_escape_string($responsavel) . "'";
        if ($data_inicio && $data_fim) {
            $where[] = "DATE(m.data) BETWEEN '" . $conn->real_escape_string($data_inicio) . "' 
                        AND '" . $conn->real_escape_string($data_fim) . "'";
        } elseif ($data_inicio) {
            $where[] = "DATE(m.data) >= '" . $conn->real_escape_string($data_inicio) . "'";
        } elseif ($data_fim) {
            $where[] = "DATE(m.data) <= '" . $conn->real_escape_string($data_fim) . "'";
        }

        $condicao = $where ? "WHERE " . implode(" AND ", $where) : "";

        // Total de registros
        $sql_total = "
            SELECT COUNT(*) AS total
            FROM movimentacoes m
            LEFT JOIN produtos p ON m.produto_id = p.id
            $condicao
        ";
        $res_total = $conn->query($sql_total);
        $total = $res_total->fetch_assoc()["total"] ?? 0;

        // Registros paginados
        $sql = "
            SELECT m.id, 
                   COALESCE(p.nome, 'Produto removido') AS produto_nome, 
                   m.tipo, 
                   m.quantidade, 
                   m.data, 
                   m.usuario, 
                   m.responsavel
            FROM movimentacoes m
            LEFT JOIN produtos p ON m.produto_id = p.id
            $condicao
            ORDER BY m.data DESC
            LIMIT $limite OFFSET $offset
        ";
        $res = $conn->query($sql);
        $movs = [];
        while ($row = $res->fetch_assoc()) {
            $movs[] = $row;
        }

        echo json_encode([
            "sucesso" => true,
            "dados" => $movs,
            "total" => (int)$total,
            "paginas" => ceil($total / $limite),
            "pagina_atual" => $pagina
        ]);
        break;

    case "adicionar":
        $nome = $_POST["nome"] ?? null;
        $quantidade = (int) ($_POST["quantidade"] ?? 0);
        $usuario = $_POST["usuario"] ?? "sistema";
        $responsavel = $_POST["responsavel"] ?? "admin";

        if (!$nome || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO produtos (nome, quantidade) VALUES (?, ?) 
                                ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)");
        $stmt->bind_param("si", $nome, $quantidade);
        if ($stmt->execute()) {
            $produto_id = $conn->insert_id ?: $conn->query("SELECT id FROM produtos WHERE nome='$nome'")->fetch_assoc()["id"];
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel) 
                          VALUES ($produto_id, 'entrada', $quantidade, NOW(), '$usuario', '$responsavel')");

            echo json_encode(["sucesso" => true, "mensagem" => "Produto adicionado com sucesso"]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Erro: " . $stmt->error]);
        }
        break;

    case "saida":
        $id = (int) ($_POST["id"] ?? 0);
        $quantidade = (int) ($_POST["quantidade"] ?? 0);
        $usuario = $_POST["usuario"] ?? "sistema";
        $responsavel = $_POST["responsavel"] ?? "admin";

        if ($id <= 0 || $quantidade <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos"]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
        $stmt->bind_param("iii", $quantidade, $id, $quantidade);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel) 
                          VALUES ($id, 'saida', $quantidade, NOW(), '$usuario', '$responsavel')");

            echo json_encode(["sucesso" => true, "mensagem" => "Saída registrada"]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Quantidade insuficiente ou produto inexistente"]);
        }
        break;

    case "remover":
        $id = (int) ($_POST["id"] ?? $_GET["id"] ?? 0);
        $usuario = $_POST["usuario"] ?? "sistema";
        $responsavel = $_POST["responsavel"] ?? "admin";

        if ($id <= 0) {
            echo json_encode(["sucesso" => false, "mensagem" => "ID inválido"]);
            exit;
        }

        $produto = $conn->query("SELECT quantidade FROM produtos WHERE id=$id")->fetch_assoc();
        if ($produto) {
            $conn->query("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data, usuario, responsavel) 
                          VALUES ($id, 'remocao', {$produto['quantidade']}, NOW(), '$usuario', '$responsavel')");
        }

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(["sucesso" => true, "mensagem" => "Produto removido com sucesso"]);
        } else {
            echo json_encode(["sucesso" => false, "mensagem" => "Erro ao remover produto ou produto inexistente"]);
        }
        break;

    default:
        echo json_encode(["sucesso" => false, "mensagem" => "Ação inválida"]);
        break;
}

$conn->close();
?>
