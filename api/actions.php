<?php
header("Content-Type: application/json; charset=UTF-8");
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/produtos.php";
require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/relatorios.php";

$acao = strtolower(trim($_REQUEST["acao"] ?? ""));
if ($acao === "") {
    echo json_encode(["sucesso" => false, "mensagem" => "Nenhuma ação especificada."]);
    exit;
}

$conn = db();

try {
    switch ($acao) {
        // ---- Produtos
        case "listarprodutos":
        case "listar_produtos":
            echo json_encode(produtos_listar($conn));
            break;

        case "adicionar":
        case "adicionar_produto":
            $body = json_decode(file_get_contents("php://input"), true) ?? $_POST ?? $_GET;
            $nome = trim($body["nome"] ?? "");
            $quant = (int)($body["quantidade"] ?? 0);
            echo json_encode(produtos_adicionar($conn, $nome, $quant));
            break;

        case "remover":
        case "remover_produto":
            $id = (int)($_GET["id"] ?? $_POST["id"] ?? 0);
            echo json_encode(mov_remover($conn, $id, "sistema", "admin"));
            break;

        // ---- Movimentações
        case "entrada":
            $body = json_decode(file_get_contents("php://input"), true) ?? $_POST ?? $_GET;
            echo json_encode(mov_entrada(
                $conn,
                (int)($body["id"] ?? 0),
                (int)($body["quantidade"] ?? 0),
                $body["usuario"] ?? "sistema",
                $body["responsavel"] ?? "admin"
            ));
            break;

        case "saida":
            $body = json_decode(file_get_contents("php://input"), true) ?? $_POST ?? $_GET;
            echo json_encode(mov_saida(
                $conn,
                (int)($body["id"] ?? 0),
                (int)($body["quantidade"] ?? 0),
                $body["usuario"] ?? "sistema",
                $body["responsavel"] ?? "admin"
            ));
            break;

        case "listarmovimentacoes":
        case "listar_movimentacoes":
            $filtros = [
                "pagina"      => (int)($_GET["pagina"] ?? $_POST["pagina"] ?? 1),
                "limite"      => (int)($_GET["limite"] ?? $_POST["limite"] ?? 10),
                "tipo"        => $_GET["tipo"] ?? $_POST["tipo"] ?? "",
                "produto_id"  => $_GET["produto_id"] ?? $_POST["produto_id"] ?? null,
                "produto"     => $_GET["produto"] ?? $_POST["produto"] ?? null,
                "usuario"     => $_GET["usuario"] ?? $_POST["usuario"] ?? "",
                "responsavel" => $_GET["responsavel"] ?? $_POST["responsavel"] ?? "",
                "data_inicio" => $_GET["data_inicio"] ?? $_POST["data_inicio"] ?? "",
                "data_fim"    => $_GET["data_fim"] ?? $_POST["data_fim"] ?? "",
            ];
            echo json_encode(mov_listar($conn, $filtros));
            break;

        // ---- Relatório
        case "relatorio":
            $filtros = [
                "tipo"        => $_GET["tipo"] ?? "",
                "produto_id"  => $_GET["produto_id"] ?? null,
                "produto"     => $_GET["produto"] ?? null,
                "usuario"     => $_GET["usuario"] ?? "",
                "responsavel" => $_GET["responsavel"] ?? "",
                "data_inicio" => $_GET["data_inicio"] ?? "",
                "data_fim"    => $_GET["data_fim"] ?? "",
            ];
            echo json_encode(relatorio($conn, $filtros));
            break;

        default:
            echo json_encode(["sucesso" => false, "mensagem" => "Ação desconhecida."]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro interno no servidor",
        "detalhes" => $e->getMessage()
    ]);
} finally {
    $conn?->close();
}
