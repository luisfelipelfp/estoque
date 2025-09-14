<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

// 🔧 DEBUG PHP
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

try {
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
            if (!$usuario_id) {
                echo json_encode(resposta(false, "Usuário não autenticado."));
                break;
            }

            // 🔐 Verifica nível de acesso do usuário
            $stmt = $conn->prepare("SELECT nivel FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $usuario_id);
            $stmt->execute();
            $stmt->bind_result($nivel);
            $stmt->fetch();
            $stmt->close();

            if ($nivel !== "admin") {
                echo json_encode(resposta(false, "Ação permitida apenas para administradores."));
                break;
            }

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

            $rel = relatorio($conn, $filtros);

            // garante consistência
            $dados = $rel["dados"] ?? (is_array($rel) ? $rel : []);
            $total = $rel["total"] ?? count($dados);

            echo json_encode(resposta(true, "", [
                "dados" => $dados,
                "total" => $total
            ]));
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
        // LOG DE ERROS JS
        // ======================
        case "log_js_error":
            $body = read_body();
            $mensagem = $body["mensagem"] ?? "Erro JS desconhecido";
            $arquivo  = $body["arquivo"] ?? "";
            $linha    = $body["linha"] ?? "";
            $coluna   = $body["coluna"] ?? "";
            $stack    = $body["stack"] ?? "";
            $origem   = $body["origem"] ?? "desconhecida";

            $log = "[JS ERROR][$origem] $mensagem em $arquivo:$linha:$coluna | Stack: $stack";
            error_log($log);

            echo json_encode(resposta(true, "Erro JS registrado no log."));
            break;

        // ======================
        // DEFAULT
        // ======================
        default:
            echo json_encode(resposta(false, "Ação inválida."));
            break;
    }

} catch (Throwable $e) {
    error_log("Erro global: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(resposta(false, "Erro interno no servidor."));
}
