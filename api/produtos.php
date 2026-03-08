<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

initLog('produtos');

function coluna_existe(mysqli $conn, string $tabela, string $coluna): bool
{
    static $cache = [];

    $dbRow = $conn->query("SELECT DATABASE() AS db")->fetch_assoc();
    $db = (string)($dbRow['db'] ?? '');
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

function normalizar_fornecedores(array $fornecedores): array
{
    $out = [];

    foreach ($fornecedores as $f) {
        if (!is_array($f)) {
            continue;
        }

        $nome = trim((string)($f['nome'] ?? ''));
        $codigo = trim((string)($f['codigo'] ?? ''));
        $precoCusto = (float)($f['preco_custo'] ?? 0);
        $precoVenda = (float)($f['preco_venda'] ?? 0);
        $observacao = trim((string)($f['observacao'] ?? ''));
        $principal = !empty($f['principal']) ? 1 : 0;

        if ($nome === '') {
            continue;
        }

        if ($precoCusto < 0) {
            $precoCusto = 0;
        }

        if ($precoVenda < 0) {
            $precoVenda = 0;
        }

        $out[] = [
            'nome'        => $nome,
            'codigo'      => $codigo,
            'preco_custo' => $precoCusto,
            'preco_venda' => $precoVenda,
            'observacao'  => $observacao,
            'principal'   => $principal,
        ];
    }

    if (!empty($out)) {
        $temPrincipal = false;

        foreach ($out as $f) {
            if ((int)$f['principal'] === 1) {
                $temPrincipal = true;
                break;
            }
        }

        if (!$temPrincipal) {
            $out[0]['principal'] = 1;
        } else {
            $achou = false;
            foreach ($out as $i => $f) {
                if ((int)$f['principal'] === 1) {
                    if (!$achou) {
                        $achou = true;
                        $out[$i]['principal'] = 1;
                    } else {
                        $out[$i]['principal'] = 0;
                    }
                }
            }
        }
    }

    return $out;
}

function fornecedor_obter_ou_criar(mysqli $conn, string $nome): int
{
    $stmtSel = $conn->prepare('SELECT id FROM fornecedores WHERE nome = ? LIMIT 1');
    $stmtSel->bind_param('s', $nome);
    $stmtSel->execute();
    $row = $stmtSel->get_result()->fetch_assoc();
    $stmtSel->close();

    if ($row) {
        return (int)$row['id'];
    }

    $stmtIns = $conn->prepare('INSERT INTO fornecedores (nome, ativo) VALUES (?, 1)');
    $stmtIns->bind_param('s', $nome);
    $stmtIns->execute();
    $id = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $id;
}

function produto_fornecedores_salvar(mysqli $conn, int $produto_id, array $fornecedores): void
{
    $fornecedores = normalizar_fornecedores($fornecedores);

    $stmtDel = $conn->prepare('DELETE FROM produto_fornecedores WHERE produto_id = ?');
    $stmtDel->bind_param('i', $produto_id);
    $stmtDel->execute();
    $stmtDel->close();

    if (empty($fornecedores)) {
        return;
    }

    $stmtIns = $conn->prepare(
        'INSERT INTO produto_fornecedores
            (produto_id, fornecedor_id, codigo_produto_fornecedor, preco_custo, preco_venda, observacao, principal)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($fornecedores as $f) {
        $fornecedorId = fornecedor_obter_ou_criar($conn, (string)$f['nome']);
        $codigo = $f['codigo'] !== '' ? (string)$f['codigo'] : null;
        $precoCusto = (float)$f['preco_custo'];
        $precoVenda = (float)$f['preco_venda'];
        $observacao = $f['observacao'] !== '' ? (string)$f['observacao'] : null;
        $principal = (int)$f['principal'];

        $stmtIns->bind_param(
            'iisddsi',
            $produto_id,
            $fornecedorId,
            $codigo,
            $precoCusto,
            $precoVenda,
            $observacao,
            $principal
        );
        $stmtIns->execute();
    }

    $stmtIns->close();
}

function produto_fornecedores_listar(mysqli $conn, int $produto_id): array
{
    $sql = "
        SELECT
            pf.id,
            pf.fornecedor_id,
            f.nome AS fornecedor_nome,
            COALESCE(pf.codigo_produto_fornecedor, '') AS codigo_produto_fornecedor,
            COALESCE(pf.preco_custo, 0) AS preco_custo,
            COALESCE(pf.preco_venda, 0) AS preco_venda,
            COALESCE(pf.observacao, '') AS observacao,
            COALESCE(pf.principal, 0) AS principal
        FROM produto_fornecedores pf
        INNER JOIN fornecedores f ON f.id = pf.fornecedor_id
        WHERE pf.produto_id = ?
        ORDER BY pf.principal DESC, f.nome ASC, pf.id ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = [
            'id'           => (int)$row['id'],
            'fornecedor_id'=> (int)$row['fornecedor_id'],
            'nome'         => (string)$row['fornecedor_nome'],
            'codigo'       => (string)$row['codigo_produto_fornecedor'],
            'preco_custo'  => (float)$row['preco_custo'],
            'preco_venda'  => (float)$row['preco_venda'],
            'observacao'   => (string)$row['observacao'],
            'principal'    => (int)$row['principal'],
        ];
    }

    $stmt->close();
    return $dados;
}

function fornecedor_principal_preco(array $fornecedores): array
{
    $fornecedores = normalizar_fornecedores($fornecedores);

    if (empty($fornecedores)) {
        return [
            'preco_custo' => 0.0,
            'preco_venda' => 0.0,
        ];
    }

    foreach ($fornecedores as $f) {
        if ((int)$f['principal'] === 1) {
            return [
                'preco_custo' => (float)$f['preco_custo'],
                'preco_venda' => (float)$f['preco_venda'],
            ];
        }
    }

    return [
        'preco_custo' => (float)$fornecedores[0]['preco_custo'],
        'preco_venda' => (float)$fornecedores[0]['preco_venda'],
    ];
}

function produtos_listar(mysqli $conn): array
{
    try {
        $hasCusto = coluna_existe($conn, 'produtos', 'preco_custo');
        $hasVenda = coluna_existe($conn, 'produtos', 'preco_venda');
        $hasEstoqueMinimo = coluna_existe($conn, 'produtos', 'estoque_minimo');

        $sql = "
            SELECT
                id,
                nome,
                quantidade,
                ativo
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo,0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo,0) AS preco_custo" : ", 0 AS preco_custo") . "
                " . ($hasVenda ? ", COALESCE(preco_venda,0) AS preco_venda" : ", 0 AS preco_venda") . "
            FROM produtos
            ORDER BY nome
        ";

        $res = $conn->query($sql);
        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $dados[] = [
                'id'             => (int)$row['id'],
                'nome'           => (string)$row['nome'],
                'quantidade'     => (int)$row['quantidade'],
                'ativo'          => (int)$row['ativo'],
                'estoque_minimo' => (int)$row['estoque_minimo'],
                'preco_custo'    => (float)$row['preco_custo'],
                'preco_venda'    => (float)$row['preco_venda'],
            ];
        }

        return resposta(true, '', $dados);
    } catch (Throwable $e) {
        logError('produtos', 'Erro ao listar produtos', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return resposta(false, 'Erro ao buscar produtos', []);
    }
}

function produto_obter(mysqli $conn, int $produto_id): array
{
    try {
        $hasCusto = coluna_existe($conn, 'produtos', 'preco_custo');
        $hasVenda = coluna_existe($conn, 'produtos', 'preco_venda');
        $hasEstoqueMinimo = coluna_existe($conn, 'produtos', 'estoque_minimo');

        $sql = "
            SELECT
                id,
                nome,
                quantidade,
                ativo
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo,0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo,0) AS preco_custo" : ", 0 AS preco_custo") . "
                " . ($hasVenda ? ", COALESCE(preco_venda,0) AS preco_venda" : ", 0 AS preco_venda") . "
            FROM produtos
            WHERE id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $produto_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return resposta(false, 'Produto não encontrado.', null);
        }

        $fornecedores = produto_fornecedores_listar($conn, $produto_id);

        return resposta(true, 'OK', [
            'id'             => (int)$row['id'],
            'nome'           => (string)$row['nome'],
            'quantidade'     => (int)$row['quantidade'],
            'estoque_minimo' => (int)$row['estoque_minimo'],
            'ativo'          => (int)$row['ativo'],
            'preco_custo'    => (float)$row['preco_custo'],
            'preco_venda'    => (float)$row['preco_venda'],
            'fornecedores'   => $fornecedores,
        ]);
    } catch (Throwable $e) {
        logError('produtos', 'Erro ao obter produto', [
            'arquivo'    => $e->getFile(),
            'linha'      => $e->getLine(),
            'erro'       => $e->getMessage(),
            'produto_id' => $produto_id
        ]);

        return resposta(false, 'Erro ao obter produto', null);
    }
}

function produtos_buscar(mysqli $conn, string $q, int $limit = 10): array
{
    try {
        $q = trim($q);
        $limit = max(1, min(25, $limit));

        $hasCusto = coluna_existe($conn, 'produtos', 'preco_custo');
        $hasEstoqueMinimo = coluna_existe($conn, 'produtos', 'estoque_minimo');

        $like = '%' . $q . '%';

        $sql = "
            SELECT
                id,
                nome,
                quantidade
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo,0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo,0) AS preco_custo" : ", 0 AS preco_custo") . "
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
                'id'             => (int)$r['id'],
                'nome'           => (string)$r['nome'],
                'quantidade'     => (int)$r['quantidade'],
                'estoque_minimo' => (int)$r['estoque_minimo'],
                'preco_custo'    => (float)$r['preco_custo'],
            ];
        }
        $stmt->close();

        return resposta(true, 'OK', ['itens' => $itens]);
    } catch (Throwable $e) {
        logError('produtos', 'Erro ao buscar produtos (autocomplete)', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage(),
            'q'       => $q
        ]);

        return resposta(false, 'Erro ao buscar produtos', ['itens' => []]);
    }
}

function produto_resumo(mysqli $conn, int $produto_id): array
{
    try {
        $hasCusto = coluna_existe($conn, 'produtos', 'preco_custo');
        $hasEstoqueMinimo = coluna_existe($conn, 'produtos', 'estoque_minimo');

        $sqlP = "
            SELECT
                id,
                nome,
                quantidade
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo,0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo,0) AS preco_custo" : ", 0 AS preco_custo") . "
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
            return resposta(false, 'Produto não encontrado.', null);
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

        return resposta(true, 'OK', [
            'produto' => [
                'id'             => (int)$prod['id'],
                'nome'           => (string)$prod['nome'],
                'quantidade'     => (int)$prod['quantidade'],
                'estoque_minimo' => (int)$prod['estoque_minimo'],
                'preco_custo'    => (float)$prod['preco_custo'],
            ],
            'ultimas_movimentacoes' => $movs
        ]);
    } catch (Throwable $e) {
        logError('produtos', 'Erro ao gerar resumo do produto', [
            'arquivo'    => $e->getFile(),
            'linha'      => $e->getLine(),
            'erro'       => $e->getMessage(),
            'produto_id' => $produto_id
        ]);

        return resposta(false, 'Erro interno ao gerar resumo.', null);
    }
}

function produtos_adicionar(
    mysqli $conn,
    string $nome,
    int $quantidade,
    int $estoque_minimo,
    ?int $usuario_id,
    ?float $preco_custo = null,
    ?float $preco_venda = null,
    array $fornecedores = []
): array {
    try {
        $nome = trim($nome);

        if ($nome === '') {
            return resposta(false, 'Nome do produto obrigatório.', null);
        }

        if ($quantidade < 0 || $estoque_minimo < 0) {
            return resposta(false, 'Dados inválidos para o produto.', null);
        }

        $conn->begin_transaction();

        $fornecedoresNormalizados = normalizar_fornecedores($fornecedores);

        $precos = !empty($fornecedoresNormalizados)
            ? fornecedor_principal_preco($fornecedoresNormalizados)
            : [
                'preco_custo' => (($preco_custo !== null && $preco_custo >= 0) ? $preco_custo : 0.0),
                'preco_venda' => (($preco_venda !== null && $preco_venda >= 0) ? $preco_venda : 0.0),
            ];

        $pc = (float)$precos['preco_custo'];
        $pv = (float)$precos['preco_venda'];

        $stmt = $conn->prepare(
            'INSERT INTO produtos (nome, quantidade, estoque_minimo, ativo, preco_custo, preco_venda)
             VALUES (?, ?, ?, 1, ?, ?)'
        );
        $stmt->bind_param('siidd', $nome, $quantidade, $estoque_minimo, $pc, $pv);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        if (!empty($fornecedoresNormalizados)) {
            produto_fornecedores_salvar($conn, $id, $fornecedoresNormalizados);
        }

        $conn->commit();

        return resposta(true, 'Produto adicionado com sucesso', ['id' => $id]);
    } catch (Throwable $e) {
        $conn->rollback();

        logError('produtos', 'Erro ao adicionar produto', [
            'arquivo'        => $e->getFile(),
            'linha'          => $e->getLine(),
            'erro'           => $e->getMessage(),
            'nome'           => $nome,
            'qtd'            => $quantidade,
            'estoque_minimo' => $estoque_minimo,
            'usuario'        => $usuario_id,
            'preco_custo'    => $preco_custo,
            'preco_venda'    => $preco_venda,
            'fornecedores'   => $fornecedores
        ]);

        return resposta(false, 'Erro ao adicionar produto', null);
    }
}

function produtos_atualizar(
    mysqli $conn,
    int $produto_id,
    string $nome,
    int $quantidade,
    int $estoque_minimo,
    float $preco_custo,
    float $preco_venda,
    ?int $usuario_id,
    array $fornecedores = []
): array {
    try {
        $nome = trim($nome);

        if ($produto_id <= 0 || $nome === '' || $quantidade < 0 || $estoque_minimo < 0 || $preco_custo < 0 || $preco_venda < 0) {
            return resposta(false, 'Dados inválidos para atualização do produto.', null);
        }

        $conn->begin_transaction();

        $stmtChk = $conn->prepare('SELECT id FROM produtos WHERE id = ? LIMIT 1');
        $stmtChk->bind_param('i', $produto_id);
        $stmtChk->execute();
        $exists = $stmtChk->get_result()->fetch_assoc();
        $stmtChk->close();

        if (!$exists) {
            $conn->rollback();
            return resposta(false, 'Produto não encontrado.', null);
        }

        $fornecedoresNormalizados = normalizar_fornecedores($fornecedores);

        $precos = !empty($fornecedoresNormalizados)
            ? fornecedor_principal_preco($fornecedoresNormalizados)
            : [
                'preco_custo' => $preco_custo,
                'preco_venda' => $preco_venda,
            ];

        $pc = (float)$precos['preco_custo'];
        $pv = (float)$precos['preco_venda'];

        $stmt = $conn->prepare(
            'UPDATE produtos
             SET nome = ?, quantidade = ?, estoque_minimo = ?, preco_custo = ?, preco_venda = ?
             WHERE id = ?'
        );
        $stmt->bind_param('siiddi', $nome, $quantidade, $estoque_minimo, $pc, $pv, $produto_id);
        $stmt->execute();
        $stmt->close();

        produto_fornecedores_salvar($conn, $produto_id, $fornecedoresNormalizados);

        $conn->commit();

        return resposta(true, 'Produto atualizado com sucesso', ['id' => $produto_id]);
    } catch (Throwable $e) {
        $conn->rollback();

        logError('produtos', 'Erro ao atualizar produto', [
            'arquivo'        => $e->getFile(),
            'linha'          => $e->getLine(),
            'erro'           => $e->getMessage(),
            'produto_id'     => $produto_id,
            'nome'           => $nome,
            'qtd'            => $quantidade,
            'estoque_minimo' => $estoque_minimo,
            'preco_custo'    => $preco_custo,
            'preco_venda'    => $preco_venda,
            'usuario'        => $usuario_id,
            'fornecedores'   => $fornecedores
        ]);

        return resposta(false, 'Erro ao atualizar produto', null);
    }
}

function produtos_remover(
    mysqli $conn,
    int $produto_id,
    ?int $usuario_id
): array {
    try {
        if ($produto_id <= 0) {
            return resposta(false, 'Produto inválido.', null);
        }

        $conn->begin_transaction();

        $stmtChk = $conn->prepare('SELECT id FROM produtos WHERE id = ? LIMIT 1');
        $stmtChk->bind_param('i', $produto_id);
        $stmtChk->execute();
        $exists = $stmtChk->get_result()->fetch_assoc();
        $stmtChk->close();

        if (!$exists) {
            $conn->rollback();
            return resposta(false, 'Produto não encontrado.', null);
        }

        $stmtDelRel = $conn->prepare('DELETE FROM produto_fornecedores WHERE produto_id = ?');
        $stmtDelRel->bind_param('i', $produto_id);
        $stmtDelRel->execute();
        $stmtDelRel->close();

        $stmt = $conn->prepare('DELETE FROM produtos WHERE id = ?');
        $stmt->bind_param('i', $produto_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        return resposta(true, 'Produto removido com sucesso', null);
    } catch (Throwable $e) {
        $conn->rollback();

        logError('produtos', 'Erro ao remover produto', [
            'arquivo'    => $e->getFile(),
            'linha'      => $e->getLine(),
            'erro'       => $e->getMessage(),
            'produto_id' => $produto_id,
            'usuario'    => $usuario_id
        ]);

        return resposta(false, 'Erro ao remover produto', null);
    }
}