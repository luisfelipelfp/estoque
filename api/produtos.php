<?php
// =======================================
// api/produtos.php
// Roteador central de produtos
// Compatível PHP 8.2+ / 8.5
// =======================================

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/movimentacoes.php';
require_once __DIR__ . '/relatorios.php';
require_once __DIR__ . '/produtos_funcoes.php';

// ---------------------------------------
// Inicializa log do contexto
// ---------------------------------------
initLog('produtos');

// ---------------------------------------
// HEADERS / CORS
// ---------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://192.168.15.100');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---------------------------------------
// SESSÃO
// ---------------------------------------
if (session_status() === PHP_SESSION_NONE) {

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false, // true em HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// ---------------------------------------
// Helpers locais
// ---------------------------------------
function read_body(): array
{
    return get_json_input('produtos') ?: ($_POST ?? []);
}

function auditoria(
    array $usuario,
    string $acao,
    array $dados = []
): void {
    logInfo('produtos', 'AUDITORIA', [
        'usuario_id' => $usuario['id']   ?? null,
        'usuario'    => $usuario['nome'] ?? null,
        'acao'       => $acao,
        'dados'      => $dados
    ]);
}

// ---------------------------------------
// Conexão com banco
// ---------------------------------------
$conn = db();

// ---------------------------------------
// Ação solicitada
// ---------------------------------------
$acao = $_REQUEST['acao'] ?? '';
$body = read_body();

// ---------------------------------------
// ROTAS PÚBLICAS (sem auth)
// ---------------------------------------
if ($acao === 'login') {
    require __DIR__ . '/login.php';
    exit;
}

if ($acao === 'logout') {
    require __DIR__ . '/logout.php';
    exit;
}

// ---------------------------------------
// AUTENTICAÇÃO OBRIGATÓRIA
// ---------------------------------------
require_once __DIR__ . '/auth.php';

$usuario = $_SESSION['usuario'] ?? [];

// Log de auditoria
auditoria($usuario, $acao, $body ?: $_GET);

// ---------------------------------------
// ROTEAMENTO
// ---------------------------------------
try {

    switch ($acao) {

        case 'listar_produtos': {

            $res = produtos_listar($conn);

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                ['produtos' => $res['dados']]
            );
            break;
        }

        case 'adicionar_produto': {

            $nome = trim($body['nome'] ?? '');
            $qtd  = (int) ($body['quantidade'] ?? 0);

            if ($nome === '') {
                logWarning('produtos', 'Nome de produto vazio', $body);
                json_response(false, 'O nome do produto não pode estar vazio.');
            }

            $res = produtos_adicionar(
                $conn,
                $nome,
                $qtd,
                $usuario['id'] ?? null
            );

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? null
            );
            break;
        }

        case 'remover_produto': {

            $produto_id = (int) ($body['produto_id'] ?? $body['id'] ?? 0);

            if ($produto_id <= 0) {
                logWarning('produtos', 'Produto ID inválido', $body);
                json_response(false, 'ID do produto inválido.');
            }

            $res = produtos_remover(
                $conn,
                $produto_id,
                $usuario['id'] ?? null
            );

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? null
            );
            break;
        }

        case 'listar_movimentacoes': {

            $res = mov_listar($conn, $_GET);

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados']
            );
            break;
        }

        case 'registrar_movimentacao': {

            $produto_id = (int) ($body['produto_id'] ?? 0);
            $tipo       = $body['tipo'] ?? '';
            $quantidade = (int) ($body['quantidade'] ?? 0);

            if (
                $produto_id <= 0 ||
                $quantidade <= 0 ||
                !in_array($tipo, ['entrada', 'saida', 'remocao'], true)
            ) {
                logWarning('produtos', 'Dados inválidos de movimentação', $body);
                json_response(false, 'Dados inválidos para movimentação.');
            }

            $res = mov_registrar(
                $conn,
                $produto_id,
                $tipo,
                $quantidade,
                $usuario['id'] ?? null
            );

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? null
            );
            break;
        }

        case 'listar_usuarios': {

            $sql = 'SELECT id, nome FROM usuarios ORDER BY nome';
            $res = $conn->query($sql);

            $lista = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

            json_response(true, 'Usuários listados com sucesso.', $lista);
            break;
        }

        case 'relatorio_movimentacoes': {

            $filtros = array_merge($_GET, $body);
            $res = relatorio($conn, $filtros);

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? []
            );
            break;
        }

        case 'exportar_relatorio': {

            require_once __DIR__ . '/exportar.php';

            $res = exportar_relatorio($conn, $_GET);

            json_response(
                $res['sucesso'],
                $res['mensagem'],
                $res['dados'] ?? null
            );
            break;
        }

        default:
            logWarning('produtos', 'Ação inválida', [
                'acao' => $acao
            ]);
            json_response(false, 'Ação inválida ou não informada.');
    }

} catch (Throwable $e) {

    logError(
        'produtos',
        'Erro global não tratado',
        $e->getFile(),
        $e->getLine(),
        $e->getMessage()
    );

    json_response(false, 'Erro interno no servidor.');
}
