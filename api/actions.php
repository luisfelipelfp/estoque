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

/**
 * Proteção contra duplicate requests por chave (chave por ação + alvo)
 * Guarda em $_SESSION['last_actions'] => [ key => ['hash'=>..., 'ts'=>...] ]
 */
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

/**
 * Helper: leitura segura do corpo (JSON + GET/POST)
 */
function read_body(): array {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    return array_merge($_GET, $_POST, $body);
}

/**
 * Helper: checa duplicata no banco (usa _mov_is_duplicate se disponível)
 * Retorna true se já existe movimentação idêntica nos últimos $window_seconds segundos.
 */
function db_mov_is_duplicate(mysqli $conn, int $produto_id, string $tipo, int $quantidade, ?int $usuario_id, int $window_seconds = 5): bool {
    // Se a função do módulo já existir, use-a (mais consistente)
    if (function_exists('_mov_is_duplicate')) {
        try {
            return _mov_is_duplicate($conn, $produto_id, $tipo, $quantidade, $usuario_id, $window_seconds);
        } catch (Throwable $e) {
            error_log("db_mov_is_duplicate: erro ao chamar _mov_is_duplicate: " . $e->getMessage());
            // fallback para query manual abaixo
        }
    }

    // Fallback: consulta manual
    $sql = "SELECT 1 FROM movimentacoes
            WHERE produto_id = ? AND tipo = ? AND quantidade = ?
              AND IFNULL(usuario_id, 0) = IFNULL(?, 0)
              AND data >= (NOW() - INTERVAL ? SECOND)
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("db_mov_is_duplicate: prepare falhou: " . $conn->error);
        return false;
    }
    $uid = $usuario_id === null ? 0 : (int)$usuario_id;
    $stmt->bind_param("isiii", $produto_id, $tipo, $quantidade, $uid, $window_seconds);
    $stmt->execute();
    $res = $stmt->get_result();
    $dup = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $stmt->close();
    return $dup;
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

        // ---- Produtos (leitura) ----
        case "listarprodutos":
        case "listar_produtos":
            echo json_encode(produtos_listar($conn));
            break;

        case "listarprodutostodos":
        case "listar_produtos_todos":
            echo json_encode(produtos_listar($conn, true));
            break;

        // ---- Adicionar produto: cria produto (quantidade = 0) e registra movimentação inicial via mov_entrada
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

            // prevenir duplicate submit por nome+quant+user nos próximos 3s
            $hash = md5("adicionar|" . $nome . "|" . $quant . "|" . ($_SESSION["usuario"]["id"] ?? '0'));
            $key  = "adicionar|" . md5($nome . "|" . ($_SESSION["usuario"]["id"] ?? '0'));
            if (is_duplicate_action($key, $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            // adiciona produto sempre com estoque inicial = 0
            $res = produtos_adicionar($conn, $nome, 0, $_SESSION["usuario"]["id"] ?? null);

            // se falhar ao criar produto, devolve erro
            if (!$res["sucesso"]) {
                echo json_encode($res);
                break;
            }

            // registra movimentação inicial se quantidade > 0 (usando mov_entrada, que atualiza produtos)
            if ($quant > 0) {
                if (!function_exists('mov_entrada')) {
                    echo json_encode(resposta(false, "Função mov_entrada não encontrada. Produto criado sem movimentação."));
                    break;
                }

                // Proteção extra: checa no DB se já existe movimentação igual recente
                $uid = $_SESSION["usuario"]["id"] ?? null;
                if (db_mov_is_duplicate($conn, (int)$res["id"], 'entrada', $quant, $uid, 5)) {
                    error_log("adicionar: duplicata DB detectada ao tentar registrar movimentação inicial para produto {$res['id']}");
                    echo json_encode(resposta(true, "Produto criado, movimentação inicial ignorada (duplicata detectada).", ["produto" => ["id" => $res["id"], "nome" => $nome, "quantidade" => $quant]]));
                    break;
                }

                $movRes = mov_entrada($conn, (int)$res["id"], $quant, $uid);

                // se mov_entrada falhar, retornamos erro com detalhes
                if (!$movRes["sucesso"]) {
                    echo json_encode(resposta(false, "Produto criado, mas falha ao registrar movimentação inicial.", ["movimentacao" => $movRes]));
                    break;
                }

                echo json_encode(resposta(true, "Produto criado e movimentação inicial registrada.", ["produto" => ["id" => $res["id"], "nome" => $nome, "quantidade" => $quant]]));
                break;
            }

            // sem movimentação inicial
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

            $hash = md5("remover|" . $produto_id . "|" . ($_SESSION["usuario"]["id"] ?? '0'));
            $key  = "remover|" . $produto_id . "|" . ($_SESSION["usuario"]["id"] ?? '0');
            if (is_duplicate_action($key, $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada."));
                break;
            }

            if (!function_exists('mov_remover')) {
                echo json_encode(resposta(false, "Função mov_remover não encontrada. Verifique o arquivo movimentacoes.php"));
                break;
            }

            // DB duplicate check (remoção)
            $uid = $_SESSION["usuario"]["id"] ?? null;
            if (db_mov_is_duplicate($conn, $produto_id, 'remocao', 0, $uid, 5)) {
                error_log("remover: duplicata DB detectada para produto {$produto_id}");
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada (DB)."));
                break;
            }

            echo json_encode(mov_remover($conn, $produto_id, $uid));
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

            // chave por produto+quantidade+user para evitar duplicata
            $hash = md5("entrada|{$produto_id}|{$quantidade}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            $key  = "entrada|" . md5("{$produto_id}|{$quantidade}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            if (is_duplicate_action($key, $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada (sessão)."));
                break;
            }

            if (!function_exists('mov_entrada')) {
                echo json_encode(resposta(false, "Função mov_entrada não encontrada. Verifique o arquivo movimentacoes.php"));
                break;
            }

            // Proteção extra no DB: evita race conditions (verifica existência de movimentação idêntica recente)
            $uid = $_SESSION["usuario"]["id"] ?? null;
            if (db_mov_is_duplicate($conn, $produto_id, 'entrada', $quantidade, $uid, 5)) {
                error_log("entrada: duplicata DB detectada para produto {$produto_id} quantidade {$quantidade} usuário {$uid}");
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada (DB)."));
                break;
            }

            // chama mov_entrada (essa função é responsável por atualizar produtos e inserir movimentacao)
            $res = mov_entrada($conn, $produto_id, $quantidade, $uid);

            echo json_encode($res);
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
            $key  = "saida|" . md5("{$produto_id}|{$quantidade}|" . ($_SESSION["usuario"]["id"] ?? '0'));
            if (is_duplicate_action($key, $hash, 3)) {
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada (sessão)."));
                break;
            }

            if (!function_exists('mov_saida')) {
                echo json_encode(resposta(false, "Função mov_saida não encontrada. Verifique o arquivo movimentacoes.php"));
                break;
            }

            $uid = $_SESSION["usuario"]["id"] ?? null;
            if (db_mov_is_duplicate($conn, $produto_id, 'saida', $quantidade, $uid, 5)) {
                error_log("saida: duplicata DB detectada para produto {$produto_id} quantidade {$quantidade} usuário {$uid}");
                echo json_encode(resposta(true, "Ação ignorada: duplicata detectada (DB)."));
                break;
            }

            $res = mov_saida($conn, $produto_id, $quantidade, $uid);
            echo json_encode($res);
            break;

        case "listarmovimentacoes":
        case "listar_movimentacoes":
            $filtros = [
                "pagina"      => (int)($_GET["pagina"] ?? $_POST["pagina"] ?? 1),
                "limite"      => (int)($_GET["limite"] ?? $_POST["limite"] ?? 10),
                "tipo"        => $_GET["tipo"] ?? $_POST["tipo"] ?? "",
                "produto_id"  => !empty($_GET["produto_id"] ?? $_POST["produto_id"] ?? "") ? (int)($_GET["produto_id"] ?? $_POST["produto_id"]) : null,
                "usuario_id"  => !empty($_GET["usuario_id"] ?? $_POST["usuario_id"] ?? "") ? (int)($_GET["usuario_id"] ?? $_POST["usuario_id"]) : null,
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
                "produto_id"  => !empty($_GET["produto_id"] ?? "") ? (int)($_GET["produto_id"]) : null,
                "usuario_id"  => !empty($_GET["usuario_id"] ?? "") ? (int)($_GET["usuario_id"]) : null,
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
