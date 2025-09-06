<?php
header("Content-Type: application/json; charset=UTF-8");

// === Configuração de logs ===
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/debug.log");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/produtos.php";
require_once __DIR__ . "/movimentacoes.php";
require_once __DIR__ . "/relatorios.php";

session_start();

$conn = db();

function resposta($sucesso, $mensagem = "", $extra = []) {
    return array_merge(["sucesso" => $sucesso, "mensagem" => $mensagem], $extra);
}

function is_duplicate_action(string $key, string $hash, int $ttl_seconds = 3): bool {
    if (!isset($_SESSION['last_actions'])) {
        $_SESSION['last_actions'] = [];
    }
    if (isset($_SESSION['last_actions'][$key])) {
        $info = $_SESSION['last_actions'][$key];
        if ($info['hash'] === $hash && (time() - ($info['ts'] ?? 0)) <= $ttl_seconds) {
            return true;
        }
    }
    $_SESSION['last_actions'][$key] = ['hash' => $hash, 'ts' => time()];
    return false;
}

function require_login($nivel = null) {
    if (empty($_SESSION["usuario"])) {
        echo json_encode(resposta(false, "Faça login para continuar."));
        exit;
    }
    if ($nivel && ($_SESSION["usuario"]["nivel"] ?? null) !== $nivel) {
        echo json_encode(resposta(false, "Ação permitida apenas para $nivel."));
        exit;
    }
}

$acao = strtolower(trim($_REQUEST["acao"] ?? ""));
if ($acao === "") {
    echo json_encode(resposta(false, "Nenhuma ação especificada."));
    exit;
}

try {
    switch ($acao) {
        // ---- Autenticação ----
        case "login":
            $body = json_decode(file_get_contents("php://input"), true) ?? [];
            $body = array_merge($_POST, $_GET, $body);
            $email = trim($body["email"] ?? "");
            $senha = trim($body["senha"] ?? "");

            if ($email === "" || $senha === "") {
                echo json_encode(resposta(false, "Preencha todos os campos."));
                break;
            }

            $stmt = $conn->prepare("SELECT id, nome, email, senha, nivel FROM usuarios WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                if (password_verify($senha, $row["senha"])) {
                    $_SESSION["usuario"] = [
                        "id"    => $row["id"],
                        "nome"  => $row["nome"],
                        "email" => $row["email"],
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

        case "logout":
            session_destroy();
            echo json_encode(resposta(true, "Logout realizado com sucesso."));
            break;

        case "usuario_atual":
            echo json_encode(resposta(true, "", [
                "logado"  => !empty($_SESSION["usuario"]),
                "usuario" => $_SESSION["usuario"] ?? null
            ]));
            break;

        // ---- Produtos ----
        case "listarprodutos":
        case "listar_produtos":
            echo json_encode(produtos_listar($conn));
            break;

        case "listarprodutostodos":
        case "listar_produtos_todos":
            echo json_encode(produtos_listar($conn, true));
            break;

        case "adicionar":
        case "adicionar_produto":
            require_login();
            $body = json_decode(file_get_contents("php://input"), true) ?? [];
            $body = array_merge($_GET, $_POST, $body);

            $nome  = trim($body["nome"] ?? "");
            $quant = (int)($body["quantidade"] ?? 0);

            if ($nome === "") {
                echo json_encode(resposta(false, "Nome do produto é obrigatório."));
                break;
            }
            if ($quant < 0) {
                echo json_encode(resposta(false, "Quantidade inválida."));
                break;
            }

            // ✅ produtos_adicionar já registra movimentação inicial, não precisa chamar mov_entrada aqui
            $res = produtos_adicionar($conn, $nome, $quant, $_SESSION["usuario"]["id"]);
            echo json_encode($res);
            break;

        case "remover":
        case "remover_produto":
            require_login("admin");
            $body = json_decode(file_get_contents("php://input"), true) ?? [];
            $body = array_merge($_GET, $_POST, $body);
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);

            if ($produto_id <= 0) {
                echo json_encode(resposta(false, "ID do produto inválido."));
                break;
            }

            $hash = md5("remover|{$produto_id}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            if (is_duplicate_action("remover", $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            echo json_encode(mov_remover($conn, $produto_id, $_SESSION["usuario"]["id"]));
            break;

        // ---- Movimentações ----
        case "entrada":
            require_login();
            $body = json_decode(file_get_contents("php://input"), true) ?? [];
            $body = array_merge($_GET, $_POST, $body);
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);
            $quantidade = (int)($body["quantidade"] ?? 0);

            if ($produto_id <= 0 || $quantidade <= 0) {
                echo json_encode(resposta(false, "Produto ou quantidade inválida."));
                break;
            }

            $hash = md5("entrada|{$produto_id}|{$quantidade}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            if (is_duplicate_action("entrada", $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            echo json_encode(mov_entrada($conn, $produto_id, $quantidade, $_SESSION["usuario"]["id"]));
            break;

        case "saida":
            require_login();
            $body = json_decode(file_get_contents("php://input"), true) ?? [];
            $body = array_merge($_GET, $_POST, $body);
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);
            $quantidade = (int)($body["quantidade"] ?? 0);

            if ($produto_id <= 0 || $quantidade <= 0) {
                echo json_encode(resposta(false, "Produto ou quantidade inválida."));
                break;
            }

            $hash = md5("saida|{$produto_id}|{$quantidade}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            if (is_duplicate_action("saida", $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            echo json_encode(mov_saida($conn, $produto_id, $quantidade, $_SESSION["usuario"]["id"]));
            break;

        case "listarmovimentacoes":
        case "listar_movimentacoes":
            $filtros = [
                "pagina"      => (int)($_GET["pagina"] ?? $_POST["pagina"] ?? 1),
                "limite"      => (int)($_GET["limite"] ?? $_POST["limite"] ?? 10),
                "tipo"        => $_GET["tipo"] ?? $_POST["tipo"] ?? "",
                "produto_id"  => $_GET["produto_id"] ?? $_POST["produto_id"] ?? null,
                "usuario_id"  => $_GET["usuario_id"] ?? $_POST["usuario_id"] ?? null,
                "usuario"     => $_GET["usuario"] ?? $_POST["usuario"] ?? "",
                "data_inicio" => $_GET["data_inicio"] ?? $_POST["data_inicio"] ?? "",
                "data_fim"    => $_GET["data_fim"] ?? $_POST["data_fim"] ?? "",
            ];
            echo json_encode(mov_listar($conn, $filtros));
            break;

        // ---- Relatório ----
        case "relatorio":
            $filtros = [
                "pagina"      => (int)($_GET["pagina"] ?? 1),
                "limite"      => (int)($_GET["limite"] ?? 50),
                "tipo"        => $_GET["tipo"] ?? "",
                "produto_id"  => $_GET["produto_id"] ?? null,
                "usuario_id"  => $_GET["usuario_id"] ?? null,
                "usuario"     => $_GET["usuario"] ?? "",
                "data_inicio" => $_GET["data_inicio"] ?? "",
                "data_fim"    => $_GET["data_fim"] ?? "",
            ];
            echo json_encode(relatorio($conn, $filtros));
            break;

        default:
            echo json_encode(resposta(false, "Ação desconhecida."));
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log("Erro em actions.php: " . $e->getMessage() . " | Arquivo: " . $e->getFile() . " | Linha: " . $e->getLine());
    echo json_encode(resposta(false, "Erro interno no servidor", [
        "detalhes" => "Verifique o arquivo debug.log"
    ]));
} finally {
    $conn?->close();
}
