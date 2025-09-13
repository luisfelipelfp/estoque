<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

// 🔧 DEBUG
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

// Conexão
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/relatorios.php"; 
$conn = db();

$acao = $_GET["acao"] ?? $_POST["acao"] ?? "";
$usuario_id = $_SESSION["usuario_id"] ?? null;

switch ($acao) {

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
        $res = mov_remover($conn, $id, $usuario_id);
        echo json_encode($res);
        break;

    // ======================
    // MOVIMENTAÇÕES & RELATÓRIOS
    // ======================
    case "listar_movimentacoes": // 🔄 alias do relatório
    case "listar_relatorios":
        $filtros = [
            "produto_id"  => $_GET["produto_id"] ?? null,
            "tipo"        => $_GET["tipo"] ?? null,
            "usuario_id"  => $_GET["usuario_id"] ?? null,
            "usuario"     => $_GET["usuario"] ?? null,
            "data_inicio" => $_GET["data_inicio"] ?? ($_GET["data_ini"] ?? null),
            "data_fim"    => $_GET["data_fim"] ?? null,
            "pagina"      => $_GET["pagina"] ?? 1,
            "limite"      => $_GET["limite"] ?? 50,
        ];

        try {
            $rel = relatorio($conn, $filtros);

            // garante consistência
            $dados = $rel["dados"] ?? (is_array($rel) ? $rel : []);
            $total = $rel["total"] ?? count($dados);

            echo json_encode(resposta(true, "", [
                "dados" => $dados,
                "total" => $total
            ]));
        } catch (Throwable $e) {
            error_log("Erro relatorio: " . $e->getMessage());
            echo json_encode(resposta(false, "Erro ao gerar relatório."));
        }
        break;

    case "registrar_movimentacao":
        $body = read_body();
        $produto_id = (int)($body["produto_id"] ?? 0);
        $tipo = $body["tipo"] ?? "";
        $quantidade = (int)($body["quantidade"] ?? 0);

        $res = mov_registrar($conn, $produto_id, $tipo, $quantidade, $usuario_id);
        echo json_encode($res);
        break;

    // ======================
    // DEFAULT
    // ======================
    default:
        echo json_encode(resposta(false, "Ação inválida."));
        break;
}
