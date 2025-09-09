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
            error_log("Duplicate action ignored: key={$key} hash={$hash}");
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

function read_body(): array {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    return array_merge($_GET, $_POST, $body);
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
            $body = read_body();
            $login = trim($body["login"] ?? "");
            $senha = trim($body["senha"] ?? "");

            if ($login === "" || $senha === "") {
                echo json_encode(resposta(false, "Preencha todos os campos."));
                break;
            }

            $stmt = $conn->prepare("SELECT id, nome, login, senha_hash, nivel FROM usuarios WHERE login = ? LIMIT 1");
            $stmt->bind_param("s", $login);
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
            $body = read_body();

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

            $hash = md5("adicionar|{$nome}|{$quant}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            $key  = "adicionar|".md5($nome);
            if (is_duplicate_action($key, $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            $res = produtos_adicionar($conn, $nome, 0, $_SESSION["usuario"]["id"]);

            if (!$res["sucesso"]) {
                echo json_encode($res);
                break;
            }

            if ($quant > 0) {
                $movRes = mov_entrada($conn, (int)$res["id"], $quant, $_SESSION["usuario"]["id"] ?? null);
                if (!$movRes["sucesso"]) {
                    echo json_encode(resposta(false, "Produto criado, mas falha ao registrar movimentação inicial.", ["movimentacao" => $movRes]));
                    break;
                }
                echo json_encode(resposta(true, "Produto criado e movimentação inicial registrada.", [
                    "produto" => ["id" => $res["id"], "nome" => $nome, "quantidade" => $quant]
                ]));
                break;
            }

            echo json_encode($res);
            break;

        case "remover":
        case "remover_produto":
            require_login("admin");
            $body = read_body();
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);

            if ($produto_id <= 0) {
                echo json_encode(resposta(false, "ID do produto inválido."));
                break;
            }

            $hash = md5("remover|{$produto_id}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            $key  = "remover|{$produto_id}";
            if (is_duplicate_action($key, $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            echo json_encode(mov_remover($conn, $produto_id, $_SESSION["usuario"]["id"] ?? null));
            break;

        // ---- Movimentações ----
        case "entrada":
            require_login();
            $body = read_body();
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);
            $quantidade = (int)($body["quantidade"] ?? 0);

            if ($produto_id <= 0 || $quantidade <= 0) {
                echo json_encode(resposta(false, "Produto ou quantidade inválida."));
                break;
            }

            $hash = md5("entrada|{$produto_id}|{$quantidade}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            $key  = "entrada|{$produto_id}";
            if (is_duplicate_action($key, $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            echo json_encode(mov_entrada($conn, $produto_id, $quantidade, $_SESSION["usuario"]["id"] ?? null));
            break;

        case "saida":
            require_login();
            $body = read_body();
            $produto_id = (int)($body["produto_id"] ?? $body["id"] ?? 0);
            $quantidade = (int)($body["quantidade"] ?? 0);

            if ($produto_id <= 0 || $quantidade <= 0) {
                echo json_encode(resposta(false, "Produto ou quantidade inválida."));
                break;
            }

            $hash = md5("saida|{$produto_id}|{$quantidade}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            $key  = "saida|{$produto_id}";
            if (is_duplicate_action($key, $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            echo json_encode(mov_saida($conn, $produto_id, $quantidade, $_SESSION["usuario"]["id"] ?? null));
            break;

        case "listarmovimentacoes":
        case "listar_movimentacoes":
            $body = read_body();
            $filtros = [
                "pagina"      => (int)($body["pagina"] ?? 1),
                "limite"      => (int)($body["limite"] ?? 10),
                "tipo"        => $body["tipo"] ?? "",
                "produto_id"  => !empty($body["produto_id"]) ? (int)$body["produto_id"] : null,
                "usuario_id"  => !empty($body["usuario_id"]) ? (int)$body["usuario_id"] : null,
                "usuario"     => $body["usuario"] ?? "",
                "data_inicio" => $body["data_inicio"] ?? "",
                "data_fim"    => $body["data_fim"] ?? "",
            ];
            echo json_encode(mov_listar($conn, $filtros));
            break;

        // ---- Relatório ----
        case "relatorio":
            $body = read_body();
            $filtros = [
                "pagina"      => (int)($body["pagina"] ?? 1),
                "limite"      => (int)($body["limite"] ?? 50),
                "tipo"        => $body["tipo"] ?? "",
                "produto_id"  => !empty($body["produto_id"]) ? (int)$body["produto_id"] : null,
                "usuario_id"  => !empty($body["usuario_id"]) ? (int)$body["usuario_id"] : null,
                "usuario"     => $body["usuario"] ?? "",
                "data_inicio" => $body["data_inicio"] ?? "",
                "data_fim"    => $body["data_fim"] ?? "",
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
