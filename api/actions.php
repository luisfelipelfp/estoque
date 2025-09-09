<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

function resposta($sucesso, $mensagem = "", $dados = null) {
    return ["sucesso" => $sucesso, "mensagem" => $mensagem, "dados" => $dados];
}

function read_body() {
    $body = file_get_contents("php://input");
    return json_decode($body, true) ?? [];
}

$conn = new mysqli("localhost", "root", "#Shakka01", "estoque");
if ($conn->connect_error) {
    echo json_encode(resposta(false, "Erro de conexão com o banco."));
    exit;
}

$acao = $_GET["acao"] ?? $_POST["acao"] ?? "";

switch ($acao) {

    // 🔑 LOGIN
    case "login":
        $body = read_body();
        // aceita tanto "login" quanto "email"
        $login = trim($body["login"] ?? $body["email"] ?? "");
        $senha = trim($body["senha"] ?? "");

        if ($login === "" || $senha === "") {
            echo json_encode(resposta(false, "Preencha todos os campos."));
            break;
        }

        $stmt = $conn->prepare("SELECT id, nome, login, email, senha_hash, nivel 
                                FROM usuarios 
                                WHERE login = ? OR email = ? 
                                LIMIT 1");
        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($senha, $row["senha_hash"])) {
                $_SESSION["usuario"] = [
                    "id"    => $row["id"],
                    "nome"  => $row["nome"],
                    "login" => $row["login"],
                    "nivel" => $row["nivel"]
                ];
                echo json_encode(resposta(true, "Login realizado.", ["usuario" => $_SESSION["usuario"]]));
            } else {
                echo json_encode(resposta(false, "Senha incorreta."));
            }
        } else {
            echo json_encode(resposta(false, "Usuário não encontrado."));
        }
        break;

    // 🔑 LOGOUT
    case "logout":
        session_destroy();
        echo json_encode(resposta(true, "Logout realizado."));
        break;

    // 🔑 CHECA SE ESTÁ LOGADO
    case "check_session":
        if (isset($_SESSION["usuario"])) {
            echo json_encode(resposta(true, "Usuário logado.", ["usuario" => $_SESSION["usuario"]]));
        } else {
            echo json_encode(resposta(false, "Não logado."));
        }
        break;

    // PRODUTOS
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
            echo json_encode(resposta(false, "Erro ao adicionar produto."));
        }
        break;

    case "remover_produto":
        $id = (int)($_GET["id"] ?? 0);
        if ($id <= 0) {
            echo json_encode(resposta(false, "ID inválido."));
            break;
        }

        // registra movimentação de remoção
        $stmt = $conn->prepare("INSERT INTO movimentacoes (produto_id, tipo, quantidade, data) 
                                VALUES (?, 'remocao', 0, NOW())");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(resposta(true, "Produto removido."));
        } else {
            echo json_encode(resposta(false, "Erro ao remover produto."));
        }
        break;

    // MOVIMENTAÇÕES
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
            } else {
                $stmt = $conn->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND quantidade >= ?");
                $stmt->bind_param("iii", $quantidade, $produto_id, $quantidade);
                if (!$stmt->execute() || $stmt->affected_rows === 0) {
                    throw new Exception("Estoque insuficiente.");
                }
                $stmt = null;
            }

            if ($stmt) {
                $stmt->bind_param("ii", $quantidade, $produto_id);
                $stmt->execute();
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

    default:
        echo json_encode(resposta(false, "Ação inválida."));
        break;
}
?>
