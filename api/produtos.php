<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auditoria.php';

initLog('produtos');

function coluna_existe(mysqli $conn, string $tabela, string $coluna): bool
{
    static $cache = [];

    $dbRow = $conn->query("SELECT DATABASE() AS db");
    $db = '';

    if ($dbRow instanceof mysqli_result) {
        $assoc = $dbRow->fetch_assoc();
        $db = (string)($assoc['db'] ?? '');
        $dbRow->free();
    }

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
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$key] = $ok;
    return $ok;
}

function produto_normalizar_nome(string $nome): string
{
    return trim($nome);
}

function produto_nome_para_comparacao(string $nome): string
{
    $nome = trim($nome);

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($nome, 'UTF-8');
    }

    return strtolower($nome);
}

function produto_nome_duplicado(mysqli $conn, string $nome, int $ignorarProdutoId = 0): bool
{
    $nomeComparacao = produto_nome_para_comparacao($nome);

    $stmt = $conn->prepare("
        SELECT id
        FROM produtos
        WHERE LOWER(TRIM(nome)) = ?
          AND id <> ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao validar nome do produto.');
    }

    $stmt->bind_param('si', $nomeComparacao, $ignorarProdutoId);
    $stmt->execute();
    $duplicado = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $duplicado;
}

function normalizar_ncm(?string $ncm): ?string
{
    $valor = preg_replace('/\D+/', '', (string)$ncm) ?? '';
    $valor = trim($valor);

    if ($valor === '') {
        return null;
    }

    if (strlen($valor) !== 8) {
        throw new InvalidArgumentException('O NCM deve conter exatamente 8 dígitos.');
    }

    return $valor;
}

function normalizar_fornecedores(array $fornecedores): array
{
    $out = [];
    $idsUsados = [];

    foreach ($fornecedores as $f) {
        if (!is_array($f)) {
            continue;
        }

        $fornecedorId = (int)($f['fornecedor_id'] ?? 0);
        $nome = trim((string)($f['nome'] ?? ''));
        $codigo = trim((string)($f['codigo'] ?? ''));
        $observacao = trim((string)($f['observacao'] ?? ''));
        $precoCusto = isset($f['preco_custo']) && $f['preco_custo'] !== ''
            ? (float)$f['preco_custo']
            : 0.0;
        $precoVenda = isset($f['preco_venda']) && $f['preco_venda'] !== ''
            ? (float)$f['preco_venda']
            : 0.0;
        $principal = !empty($f['principal']) ? 1 : 0;

        if ($fornecedorId <= 0) {
            continue;
        }

        if (in_array($fornecedorId, $idsUsados, true)) {
            throw new InvalidArgumentException('O mesmo fornecedor não pode ser adicionado mais de uma vez para o mesmo produto.');
        }

        if ($precoCusto < 0 || $precoVenda < 0) {
            throw new InvalidArgumentException('Os preços dos fornecedores devem ser maiores ou iguais a zero.');
        }

        $out[] = [
            'fornecedor_id' => $fornecedorId,
            'nome'          => $nome,
            'codigo'        => $codigo,
            'preco_custo'   => $precoCusto,
            'preco_venda'   => $precoVenda,
            'observacao'    => $observacao,
            'principal'     => $principal,
        ];

        $idsUsados[] = $fornecedorId;
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

function validar_fornecedores_existentes(mysqli $conn, array $fornecedores): void
{
    if (empty($fornecedores)) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT id, nome, ativo
        FROM fornecedores
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao validar fornecedores do produto.');
    }

    foreach ($fornecedores as $f) {
        $fornecedorId = (int)($f['fornecedor_id'] ?? 0);

        if ($fornecedorId <= 0) {
            $stmt->close();
            throw new InvalidArgumentException('Fornecedor inválido informado para o produto.');
        }

        $stmt->bind_param('i', $fornecedorId);
        $stmt->execute();
        $existe = $stmt->get_result()->fetch_assoc();

        if (!$existe) {
            $stmt->close();
            throw new InvalidArgumentException('Fornecedor informado não existe.');
        }

        if ((int)($existe['ativo'] ?? 1) !== 1) {
            $stmt->close();
            throw new InvalidArgumentException('Fornecedor informado está inativo.');
        }
    }

    $stmt->close();
}

function produto_fornecedores_salvar(mysqli $conn, int $produto_id, array $fornecedores): void
{
    $fornecedores = normalizar_fornecedores($fornecedores);
    validar_fornecedores_existentes($conn, $fornecedores);

    $stmtDel = $conn->prepare('DELETE FROM produto_fornecedores WHERE produto_id = ?');
    if (!$stmtDel) {
        throw new RuntimeException('Erro ao limpar fornecedores do produto.');
    }

    $stmtDel->bind_param('i', $produto_id);
    if (!$stmtDel->execute()) {
        $stmtDel->close();
        throw new RuntimeException('Erro ao remover fornecedores antigos do produto.');
    }
    $stmtDel->close();

    if (empty($fornecedores)) {
        return;
    }

    $stmtIns = $conn->prepare(
        'INSERT INTO produto_fornecedores
            (produto_id, fornecedor_id, codigo_produto_fornecedor, preco_custo, preco_venda, observacao, principal)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$stmtIns) {
        throw new RuntimeException('Erro ao salvar fornecedores do produto.');
    }

    foreach ($fornecedores as $f) {
        $fornecedorId = (int)$f['fornecedor_id'];
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

        if (!$stmtIns->execute()) {
            $stmtIns->close();
            throw new RuntimeException('Erro ao inserir fornecedor do produto.');
        }
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
    if (!$stmt) {
        throw new RuntimeException('Erro ao listar fornecedores do produto.');
    }

    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = [
            'id'            => (int)$row['id'],
            'fornecedor_id' => (int)$row['fornecedor_id'],
            'nome'          => (string)$row['fornecedor_nome'],
            'codigo'        => (string)$row['codigo_produto_fornecedor'],
            'preco_custo'   => (float)$row['preco_custo'],
            'preco_venda'   => (float)$row['preco_venda'],
            'observacao'    => (string)$row['observacao'],
            'principal'     => (int)$row['principal'],
        ];
    }

    $stmt->close();
    return $dados;
}

function fornecedor_principal_preco(array $fornecedores): array
{
    if (empty($fornecedores)) {
        return [
            'preco_custo' => 0.0,
            'preco_venda' => 0.0,
        ];
    }

    foreach ($fornecedores as $f) {
        if ((int)($f['principal'] ?? 0) === 1) {
            return [
                'preco_custo' => (float)($f['preco_custo'] ?? 0),
                'preco_venda' => (float)($f['preco_venda'] ?? 0),
            ];
        }
    }

    return [
        'preco_custo' => (float)($fornecedores[0]['preco_custo'] ?? 0),
        'preco_venda' => (float)($fornecedores[0]['preco_venda'] ?? 0),
    ];
}

function produto_tem_movimentacoes(mysqli $conn, int $produto_id): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM movimentacoes
        WHERE produto_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao validar movimentações do produto.');
    }

    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $tem = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $tem;
}

function produtos_registrar_auditoria_segura(
    mysqli $conn,
    ?int $usuarioId,
    string $acao,
    string $entidade,
    ?int $entidadeId,
    mixed $dadosAnteriores,
    mixed $dadosNovos
): void {
    $ok = auditoria_registrar(
        $conn,
        $usuarioId,
        $acao,
        $entidade,
        $entidadeId,
        $dadosAnteriores,
        $dadosNovos
    );

    if (!$ok) {
        logWarning('produtos', 'Falha ao registrar auditoria do produto', [
            'acao'        => $acao,
            'entidade'    => $entidade,
            'entidade_id' => $entidadeId,
            'usuario_id'  => $usuarioId
        ]);
    }
}

function produto_auditoria_snapshot(mysqli $conn, int $produto_id): ?array
{
    if ($produto_id <= 0) {
        return null;
    }

    $hasCusto = coluna_existe($conn, 'produtos', 'preco_custo');
    $hasVenda = coluna_existe($conn, 'produtos', 'preco_venda');
    $hasEstoqueMinimo = coluna_existe($conn, 'produtos', 'estoque_minimo');
    $hasNcm = coluna_existe($conn, 'produtos', 'ncm');
    $hasAtivo = coluna_existe($conn, 'produtos', 'ativo');

    $sql = "
        SELECT
            id,
            nome,
            " . ($hasNcm ? "COALESCE(ncm, '') AS ncm," : "'' AS ncm,") . "
            quantidade,
            " . ($hasAtivo ? "COALESCE(ativo, 1) AS ativo" : "1 AS ativo") . "
            " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo, 0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
            " . ($hasCusto ? ", COALESCE(preco_custo, 0) AS preco_custo" : ", 0 AS preco_custo") . "
            " . ($hasVenda ? ", COALESCE(preco_venda, 0) AS preco_venda" : ", 0 AS preco_venda") . "
        FROM produtos
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar snapshot do produto.');
    }

    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'id'             => (int)$row['id'],
        'nome'           => (string)$row['nome'],
        'ncm'            => (string)($row['ncm'] ?? ''),
        'quantidade'     => (int)$row['quantidade'],
        'estoque_minimo' => (int)$row['estoque_minimo'],
        'ativo'          => (int)$row['ativo'],
        'preco_custo'    => (float)$row['preco_custo'],
        'preco_venda'    => (float)$row['preco_venda'],
        'fornecedores'   => produto_fornecedores_listar($conn, $produto_id),
    ];
}

function produtos_listar(mysqli $conn): array
{
    try {
        $hasCusto = coluna_existe($conn, 'produtos', 'preco_custo');
        $hasVenda = coluna_existe($conn, 'produtos', 'preco_venda');
        $hasEstoqueMinimo = coluna_existe($conn, 'produtos', 'estoque_minimo');
        $hasNcm = coluna_existe($conn, 'produtos', 'ncm');
        $hasAtivo = coluna_existe($conn, 'produtos', 'ativo');

        $sql = "
            SELECT
                id,
                nome,
                " . ($hasNcm ? "COALESCE(ncm,'') AS ncm," : "'' AS ncm,") . "
                quantidade,
                " . ($hasAtivo ? "COALESCE(ativo,1) AS ativo" : "1 AS ativo") . "
                " . ($hasEstoqueMinimo ? ",COALESCE(estoque_minimo,0) AS estoque_minimo" : ",0 AS estoque_minimo") . "
                " . ($hasCusto ? ",COALESCE(preco_custo,0) AS preco_custo" : ",0 AS preco_custo") . "
                " . ($hasVenda ? ",COALESCE(preco_venda,0) AS preco_venda" : ",0 AS preco_venda") . "
            FROM produtos
            ORDER BY COALESCE(ativo, 1) DESC, nome ASC, id ASC
        ";

        $res = $conn->query($sql);

        if (!$res) {
            throw new RuntimeException('Erro na consulta de produtos.');
        }

        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $dados[] = [
                'id'             => (int)$row['id'],
                'nome'           => (string)$row['nome'],
                'ncm'            => (string)($row['ncm'] ?? ''),
                'quantidade'     => (int)$row['quantidade'],
                'ativo'          => (int)$row['ativo'],
                'estoque_minimo' => (int)$row['estoque_minimo'],
                'preco_custo'    => (float)$row['preco_custo'],
                'preco_venda'    => (float)$row['preco_venda'],
            ];
        }

        $res->free();

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
        $hasNcm = coluna_existe($conn, 'produtos', 'ncm');
        $hasAtivo = coluna_existe($conn, 'produtos', 'ativo');

        $sql = "
            SELECT
                id,
                nome,
                " . ($hasNcm ? "COALESCE(ncm, '') AS ncm," : "'' AS ncm,") . "
                quantidade,
                " . ($hasAtivo ? "COALESCE(ativo, 1) AS ativo" : "1 AS ativo") . "
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo, 0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo, 0) AS preco_custo" : ", 0 AS preco_custo") . "
                " . ($hasVenda ? ", COALESCE(preco_venda, 0) AS preco_venda" : ", 0 AS preco_venda") . "
            FROM produtos
            WHERE id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return resposta(false, 'Erro ao obter produto.', null);
        }

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
            'ncm'            => (string)($row['ncm'] ?? ''),
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
        $hasNcm = coluna_existe($conn, 'produtos', 'ncm');
        $hasAtivo = coluna_existe($conn, 'produtos', 'ativo');

        $like = '%' . $q . '%';

        $sql = "
            SELECT
                id,
                nome,
                " . ($hasNcm ? "COALESCE(ncm, '') AS ncm," : "'' AS ncm,") . "
                quantidade
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo, 0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo, 0) AS preco_custo" : ", 0 AS preco_custo") . "
                " . ($hasAtivo ? ", COALESCE(ativo, 1) AS ativo" : ", 1 AS ativo") . "
            FROM produtos
            WHERE (" . ($hasNcm ? "nome LIKE ? OR ncm LIKE ?" : "nome LIKE ?") . ")
              " . ($hasAtivo ? "AND COALESCE(ativo, 1) = 1" : "") . "
            ORDER BY nome ASC
            LIMIT ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return resposta(false, 'Erro ao buscar produtos', ['itens' => []]);
        }

        if ($hasNcm) {
            $stmt->bind_param('ssi', $like, $like, $limit);
        } else {
            $stmt->bind_param('si', $like, $limit);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $itens = [];
        while ($r = $res->fetch_assoc()) {
            $itens[] = [
                'id'             => (int)$r['id'],
                'nome'           => (string)$r['nome'],
                'ncm'            => (string)($r['ncm'] ?? ''),
                'quantidade'     => (int)$r['quantidade'],
                'estoque_minimo' => (int)$r['estoque_minimo'],
                'preco_custo'    => (float)$r['preco_custo'],
                'ativo'          => (int)$r['ativo'],
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
        $hasNcm = coluna_existe($conn, 'produtos', 'ncm');
        $hasAtivo = coluna_existe($conn, 'produtos', 'ativo');

        $sqlP = "
            SELECT
                id,
                nome,
                " . ($hasNcm ? "COALESCE(ncm, '') AS ncm," : "'' AS ncm,") . "
                quantidade
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo, 0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo, 0) AS preco_custo" : ", 0 AS preco_custo") . "
                " . ($hasAtivo ? ", COALESCE(ativo, 1) AS ativo" : ", 1 AS ativo") . "
            FROM produtos
            WHERE id = ?
            LIMIT 1
        ";

        $stmtP = $conn->prepare($sqlP);
        if (!$stmtP) {
            return resposta(false, 'Erro ao gerar resumo do produto.', null);
        }

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
        if (!$stmtM) {
            return resposta(false, 'Erro ao gerar resumo do produto.', null);
        }

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
                'ncm'            => (string)($prod['ncm'] ?? ''),
                'quantidade'     => (int)$prod['quantidade'],
                'estoque_minimo' => (int)$prod['estoque_minimo'],
                'preco_custo'    => (float)$prod['preco_custo'],
                'ativo'          => (int)$prod['ativo'],
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
    ?string $ncm,
    int $quantidade,
    int $estoque_minimo,
    ?int $usuario_id,
    ?float $preco_custo = null,
    ?float $preco_venda = null,
    array $fornecedores = []
): array {
    try {
        $nome = produto_normalizar_nome($nome);
        $ncmNormalizado = normalizar_ncm($ncm);

        if ($nome === '') {
            return resposta(false, 'Nome do produto obrigatório.', null);
        }

        if ($quantidade < 0 || $estoque_minimo < 0) {
            return resposta(false, 'Dados inválidos para o produto.', null);
        }

        if (produto_nome_duplicado($conn, $nome, 0)) {
            return resposta(false, 'Já existe um produto com esse nome.', null);
        }

        $fornecedoresNormalizados = normalizar_fornecedores($fornecedores);
        validar_fornecedores_existentes($conn, $fornecedoresNormalizados);

        $conn->begin_transaction();

        $precos = !empty($fornecedoresNormalizados)
            ? fornecedor_principal_preco($fornecedoresNormalizados)
            : [
                'preco_custo' => 0.0,
                'preco_venda' => 0.0,
            ];

        $pc = (float)$precos['preco_custo'];
        $pv = (float)$precos['preco_venda'];
        $hasNcm = coluna_existe($conn, 'produtos', 'ncm');

        if ($hasNcm) {
            $stmt = $conn->prepare(
                'INSERT INTO produtos (nome, ncm, quantidade, estoque_minimo, ativo, preco_custo, preco_venda)
                 VALUES (?, ?, ?, ?, 1, ?, ?)'
            );
            if (!$stmt) {
                $conn->rollback();
                return resposta(false, 'Erro ao cadastrar produto.', null);
            }

            $stmt->bind_param('ssiidd', $nome, $ncmNormalizado, $quantidade, $estoque_minimo, $pc, $pv);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO produtos (nome, quantidade, estoque_minimo, ativo, preco_custo, preco_venda)
                 VALUES (?, ?, ?, 1, ?, ?)'
            );
            if (!$stmt) {
                $conn->rollback();
                return resposta(false, 'Erro ao cadastrar produto.', null);
            }

            $stmt->bind_param('siidd', $nome, $quantidade, $estoque_minimo, $pc, $pv);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return resposta(false, 'Erro ao cadastrar produto.', null);
        }

        $id = (int)$stmt->insert_id;
        $stmt->close();

        if (!empty($fornecedoresNormalizados)) {
            produto_fornecedores_salvar($conn, $id, $fornecedoresNormalizados);
        }

        $depois = produto_auditoria_snapshot($conn, $id);

        produtos_registrar_auditoria_segura(
            $conn,
            $usuario_id,
            'criar_produto',
            'produto',
            $id,
            null,
            $depois
        );

        $conn->commit();

        return resposta(true, 'Produto adicionado com sucesso', ['id' => $id]);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }

        logError('produtos', 'Erro ao adicionar produto', [
            'arquivo'        => $e->getFile(),
            'linha'          => $e->getLine(),
            'erro'           => $e->getMessage(),
            'nome'           => $nome ?? '',
            'ncm'            => $ncm ?? null,
            'qtd'            => $quantidade ?? null,
            'estoque_minimo' => $estoque_minimo ?? null,
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
    ?string $ncm,
    int $quantidade,
    int $estoque_minimo,
    float $preco_custo,
    float $preco_venda,
    ?int $usuario_id,
    array $fornecedores = []
): array {
    try {
        $nome = produto_normalizar_nome($nome);
        $ncmNormalizado = normalizar_ncm($ncm);

        if ($produto_id <= 0 || $nome === '' || $quantidade < 0 || $estoque_minimo < 0 || $preco_custo < 0 || $preco_venda < 0) {
            return resposta(false, 'Dados inválidos para atualização do produto.', null);
        }

        if (produto_nome_duplicado($conn, $nome, $produto_id)) {
            return resposta(false, 'Já existe um produto com esse nome.', null);
        }

        $fornecedoresNormalizados = normalizar_fornecedores($fornecedores);
        validar_fornecedores_existentes($conn, $fornecedoresNormalizados);

        $conn->begin_transaction();

        $antes = produto_auditoria_snapshot($conn, $produto_id);

        if (!$antes) {
            $conn->rollback();
            return resposta(false, 'Produto não encontrado.', null);
        }

        $precos = !empty($fornecedoresNormalizados)
            ? fornecedor_principal_preco($fornecedoresNormalizados)
            : [
                'preco_custo' => 0.0,
                'preco_venda' => 0.0,
            ];

        $pc = (float)$precos['preco_custo'];
        $pv = (float)$precos['preco_venda'];
        $hasNcm = coluna_existe($conn, 'produtos', 'ncm');

        if ($hasNcm) {
            $stmt = $conn->prepare(
                'UPDATE produtos
                 SET nome = ?, ncm = ?, quantidade = ?, estoque_minimo = ?, preco_custo = ?, preco_venda = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                $conn->rollback();
                return resposta(false, 'Erro ao atualizar produto.', null);
            }

            $stmt->bind_param('ssiiddi', $nome, $ncmNormalizado, $quantidade, $estoque_minimo, $pc, $pv, $produto_id);
        } else {
            $stmt = $conn->prepare(
                'UPDATE produtos
                 SET nome = ?, quantidade = ?, estoque_minimo = ?, preco_custo = ?, preco_venda = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                $conn->rollback();
                return resposta(false, 'Erro ao atualizar produto.', null);
            }

            $stmt->bind_param('siiddi', $nome, $quantidade, $estoque_minimo, $pc, $pv, $produto_id);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return resposta(false, 'Erro ao atualizar produto.', null);
        }
        $stmt->close();

        produto_fornecedores_salvar($conn, $produto_id, $fornecedoresNormalizados);

        $depois = produto_auditoria_snapshot($conn, $produto_id);

        produtos_registrar_auditoria_segura(
            $conn,
            $usuario_id,
            'editar_produto',
            'produto',
            $produto_id,
            $antes,
            $depois
        );

        $conn->commit();

        return resposta(true, 'Produto atualizado com sucesso', ['id' => $produto_id]);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }

        logError('produtos', 'Erro ao atualizar produto', [
            'arquivo'        => $e->getFile(),
            'linha'          => $e->getLine(),
            'erro'           => $e->getMessage(),
            'produto_id'     => $produto_id,
            'nome'           => $nome ?? '',
            'ncm'            => $ncm ?? null,
            'qtd'            => $quantidade ?? null,
            'estoque_minimo' => $estoque_minimo ?? null,
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

        $antes = produto_auditoria_snapshot($conn, $produto_id);

        if (!$antes) {
            $conn->rollback();
            return resposta(false, 'Produto não encontrado.', null);
        }

        $hasAtivo = coluna_existe($conn, 'produtos', 'ativo');
        $temMovimentacoes = produto_tem_movimentacoes($conn, $produto_id);

        if ($hasAtivo) {
            if ((int)($antes['ativo'] ?? 1) === 0) {
                $conn->rollback();
                return resposta(false, 'O produto já está inativo.', null);
            }

            $stmt = $conn->prepare("
                UPDATE produtos
                SET ativo = 0
                WHERE id = ?
            ");

            if (!$stmt) {
                $conn->rollback();
                return resposta(false, 'Erro ao inativar produto.', null);
            }

            $stmt->bind_param('i', $produto_id);

            if (!$stmt->execute()) {
                $stmt->close();
                $conn->rollback();
                return resposta(false, 'Erro ao inativar produto.', null);
            }

            $stmt->close();

            $depois = produto_auditoria_snapshot($conn, $produto_id);

            produtos_registrar_auditoria_segura(
                $conn,
                $usuario_id,
                'inativar_produto',
                'produto',
                $produto_id,
                $antes,
                $depois
            );

            $conn->commit();

            return resposta(true, 'Produto inativado com sucesso.', ['id' => $produto_id]);
        }

        if ($temMovimentacoes) {
            $conn->rollback();
            return resposta(false, 'Este produto possui movimentações e não pode ser removido fisicamente.', null);
        }

        $stmtDelRel = $conn->prepare('DELETE FROM produto_fornecedores WHERE produto_id = ?');
        if (!$stmtDelRel) {
            $conn->rollback();
            return resposta(false, 'Erro ao remover vínculos do produto.', null);
        }

        $stmtDelRel->bind_param('i', $produto_id);
        if (!$stmtDelRel->execute()) {
            $stmtDelRel->close();
            $conn->rollback();
            return resposta(false, 'Erro ao remover vínculos do produto.', null);
        }
        $stmtDelRel->close();

        $stmt = $conn->prepare('DELETE FROM produtos WHERE id = ?');
        if (!$stmt) {
            $conn->rollback();
            return resposta(false, 'Erro ao remover produto.', null);
        }

        $stmt->bind_param('i', $produto_id);
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return resposta(false, 'Erro ao remover produto.', null);
        }
        $stmt->close();

        produtos_registrar_auditoria_segura(
            $conn,
            $usuario_id,
            'excluir_produto',
            'produto',
            $produto_id,
            $antes,
            null
        );

        $conn->commit();

        return resposta(true, 'Produto removido com sucesso.', null);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }

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