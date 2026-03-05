<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

function coluna_existe(mysqli $conn, string $tabela, string $coluna): bool
{
    static $cache = []; // [db][tabela][coluna] => bool

    $db = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '';
    $key = $db . '|' . $tabela . '|' . $coluna;

    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }

    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$key] = $ok;
    return $ok;
}

function produtos_listar(mysqli $conn): array
{
    try {
        $hasPreco = coluna_existe($conn, 'produtos', 'preco_custo');

        $sql = "
            SELECT
                id,
                nome,
                quantidade,
                ativo
                " . ($hasPreco ? ", COALESCE(preco_custo,0) AS preco_custo" : ", 0 AS preco_custo") . "
            FROM produtos
            ORDER BY nome
        ";

        $res = $conn->query($sql);

        return [
            'sucesso'  => true,
            'mensagem' => '',
            'dados'    => $res->fetch_all(MYSQLI_ASSOC)
        ];

    } catch (Throwable $e) {

        logError('produtos', 'Erro ao listar produtos', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao buscar produtos',
            'dados'    => []
        ];
    }
}

/**
 * Busca para autocomplete (modal)
 * Retorna: dados.itens = [{id,nome,quantidade,preco_custo}]
 */
function produtos_buscar(mysqli $conn, string $q, int $limit = 10): array
{
    try {
        $q = trim($q);
        $limit = max(1, min(25, $limit));

        $hasPreco = coluna_existe($conn, 'produtos', 'preco_custo');

        $like = '%' . $q . '%';

        $sql = "
            SELECT
                id,
                nome,
                quantidade
                " . ($hasPreco ? ", COALESCE(preco_custo,0) AS preco_custo" : ", 0 AS preco_custo") . "
            FROM produtos
            WHERE nome LIKE ?
            ORDER BY nome ASC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();

        $itens = [];
        while ($r = $res->fetch_assoc()) {
            $itens[] = [
                'id'         => (int)$r['id'],
                'nome'       => (string)$r['nome'],
                'quantidade' => (int)$r['quantidade'],
                'preco_custo'=> (float)$r['preco_custo'],
            ];
        }
        $stmt->close();

        return [
            'sucesso' => true,
            'mensagem'=> 'OK',
            'dados'   => ['itens' => $itens]
        ];

    } catch (Throwable $e) {
        logError('produtos', 'Erro ao buscar produtos (autocomplete)', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage(),
            'q'       => $q
        ]);

        return [
            'sucesso' => false,
            'mensagem'=> 'Erro ao buscar produtos',
            'dados'   => ['itens' => []]
        ];
    }
}

/**
 * Resumo do produto para o modal:
 * - produto (id,nome,quantidade,preco_custo)
 * - ultimas_movimentacoes (10)
 */
function produto_resumo(mysqli $conn, int $produto_id): array
{
    try {
        $hasPreco = coluna_existe($conn, 'produtos', 'preco_custo');

        $sqlP = "
            SELECT
                id,
                nome,
                quantidade
                " . ($hasPreco ? ", COALESCE(preco_custo,0) AS preco_custo" : ", 0 AS preco_custo") . "
            FROM produtos
            WHERE id = ?
            LIMIT 1
        ";
        $stmtP = $conn->prepare($sqlP);
        $stmtP->bind_param('i', $produto_id);
        $stmtP->execute();
        $prod = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        if (!$prod) {
            return ['sucesso' => false, 'mensagem' => 'Produto não encontrado.', 'dados' => null];
        }

        $sqlM = "
            SELECT
                m.id,
                m.tipo,
                m.quantidade,
                m.data,
                COALESCE(u.nome, 'Sistema') AS usuario
            FROM movimentacoes m
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.produto_id = ?
            ORDER BY m.data DESC, m.id DESC
            LIMIT 10
        ";
        $stmtM = $conn->prepare($sqlM);
        $stmtM->bind_param('i', $produto_id);
        $stmtM->execute();
        $resM = $stmtM->get_result();

        $movs = [];
        while ($r = $resM->fetch_assoc()) {
            $movs[] = [
                'id'         => (int)$r['id'],
                'tipo'       => (string)$r['tipo'],
                'quantidade' => (int)$r['quantidade'],
                'data'       => date('d/m/Y H:i', strtotime((string)$r['data'])),
                'usuario'    => (string)$r['usuario'],
            ];
        }
        $stmtM->close();

        $payload = [
            'produto' => [
                'id'         => (int)$prod['id'],
                'nome'       => (string)$prod['nome'],
                'quantidade' => (int)$prod['quantidade'],
                'preco_custo'=> (float)$prod['preco_custo'],
            ],
            'ultimas_movimentacoes' => $movs
        ];

        return ['sucesso' => true, 'mensagem' => 'OK', 'dados' => $payload];

    } catch (Throwable $e) {
        logError('produtos', 'Erro ao gerar resumo do produto', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage(),
            'produto_id' => $produto_id
        ]);

        return ['sucesso' => false, 'mensagem' => 'Erro interno ao gerar resumo.', 'dados' => null];
    }
}

function produtos_adicionar(
    mysqli $conn,
    string $nome,
    int $quantidade,
    ?int $usuario_id
): array {
    try {
        $stmt = $conn->prepare(
            'INSERT INTO produtos (nome, quantidade, ativo) VALUES (?, ?, 1)'
        );

        $stmt->bind_param('si', $nome, $quantidade);

        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return [
            'sucesso'  => true,
            'mensagem' => 'Produto adicionado com sucesso',
            'dados'    => ['id' => $id]
        ];

    } catch (Throwable $e) {

        logError('produtos', 'Erro ao adicionar produto', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage(),
            'nome'    => $nome,
            'qtd'     => $quantidade,
            'usuario' => $usuario_id
        ]);

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao adicionar produto',
            'dados'    => null
        ];
    }
}

function produtos_remover(
    mysqli $conn,
    int $produto_id,
    ?int $usuario_id
): array {
    try {

        $stmt = $conn->prepare('DELETE FROM produtos WHERE id = ?');
        $stmt->bind_param('i', $produto_id);

        $stmt->execute();
        $stmt->close();

        return [
            'sucesso'  => true,
            'mensagem' => 'Produto removido com sucesso',
            'dados'    => null
        ];

    } catch (Throwable $e) {

        logError('produtos', 'Erro ao remover produto', [
            'arquivo'    => $e->getFile(),
            'linha'      => $e->getLine(),
            'erro'       => $e->getMessage(),
            'produto_id' => $produto_id,
            'usuario'    => $usuario_id
        ]);

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao remover produto',
            'dados'    => null
        ];
    }
}