<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auditoria.php';

initLog('fornecedores');

const FORNECEDOR_NOME_MAX_LEN = 150;
const FORNECEDOR_TELEFONE_MAX_LEN = 30;
const FORNECEDOR_EMAIL_MAX_LEN = 150;
const FORNECEDOR_OBSERVACAO_MAX_LEN = 500;
const FORNECEDOR_TOTAL_PRODUTOS_MAX = 100000;
const FORNECEDOR_TOTAL_MOVIMENTACOES_MAX = 1000000;

function fornecedor_strlen(string $valor): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($valor, 'UTF-8')
        : strlen($valor);
}

function fornecedor_normalizar_texto(?string $valor): string
{
    $valor = trim((string)$valor);
    $valor = preg_replace('/\s+/u', ' ', $valor) ?? $valor;
    return $valor;
}

function fornecedor_normalizar_cnpj(string $cnpj): string
{
    return preg_replace('/\D+/', '', $cnpj) ?? '';
}

function fornecedor_validar_tamanho(string $valor, int $max, string $campo): void
{
    if (fornecedor_strlen($valor) > $max) {
        throw new InvalidArgumentException("O campo {$campo} excede o limite permitido.");
    }
}

function fornecedor_validar_int_range(int $valor, int $min, int $max, string $campo): void
{
    if ($valor < $min || $valor > $max) {
        throw new InvalidArgumentException("Valor inválido para {$campo}.");
    }
}

function fornecedor_email_valido(string $email): bool
{
    if ($email === '') {
        return true;
    }

    if (fornecedor_strlen($email) > FORNECEDOR_EMAIL_MAX_LEN) {
        return false;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function fornecedor_total_produtos(mysqli $conn, int $fornecedorId): int
{
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT produto_id) AS total
        FROM produto_fornecedores
        WHERE fornecedor_id = ?
    ");
    if (!$stmt) {
        throw new RuntimeException('Erro ao contar produtos do fornecedor.');
    }

    $stmt->bind_param('i', $fornecedorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total = (int)($row['total'] ?? 0);
    if ($total < 0 || $total > FORNECEDOR_TOTAL_PRODUTOS_MAX) {
        throw new RuntimeException('Total de produtos do fornecedor fora do limite esperado.');
    }

    return $total;
}

function fornecedor_total_movimentacoes(mysqli $conn, int $fornecedorId): int
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM movimentacoes
        WHERE fornecedor_id = ?
    ");
    if (!$stmt) {
        throw new RuntimeException('Erro ao contar movimentações do fornecedor.');
    }

    $stmt->bind_param('i', $fornecedorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total = (int)($row['total'] ?? 0);
    if ($total < 0 || $total > FORNECEDOR_TOTAL_MOVIMENTACOES_MAX) {
        throw new RuntimeException('Total de movimentações do fornecedor fora do limite esperado.');
    }

    return $total;
}

function fornecedor_produtos_listar(mysqli $conn, int $fornecedorId): array
{
    $hasAtivoProduto = false;

    $stmtCol = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'produtos'
          AND COLUMN_NAME = 'ativo'
        LIMIT 1
    ");
    if ($stmtCol) {
        $stmtCol->execute();
        $hasAtivoProduto = (bool)$stmtCol->get_result()->fetch_assoc();
        $stmtCol->close();
    }

    $sql = "
        SELECT
            p.id,
            p.nome,
            COALESCE(pf.codigo_produto_fornecedor, '') AS codigo_produto_fornecedor,
            COALESCE(pf.observacao, '') AS observacao,
            COALESCE(pf.principal, 0) AS principal
            " . ($hasAtivoProduto ? ", COALESCE(p.ativo, 1) AS ativo" : ", 1 AS ativo") . "
        FROM produto_fornecedores pf
        INNER JOIN produtos p
            ON p.id = pf.produto_id
        WHERE pf.fornecedor_id = ?
        ORDER BY
            " . ($hasAtivoProduto ? "COALESCE(p.ativo, 1) DESC," : "") . "
            p.nome ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Erro ao listar produtos do fornecedor.');
    }

    $stmt->bind_param('i', $fornecedorId);
    $stmt->execute();
    $res = $stmt->get_result();

    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = [
            'produto_id'                => (int)$row['id'],
            'produto_nome'              => (string)$row['nome'],
            'codigo_produto_fornecedor' => (string)$row['codigo_produto_fornecedor'],
            'observacao'                => (string)$row['observacao'],
            'principal'                 => (int)$row['principal'],
            'ativo'                     => (int)($row['ativo'] ?? 1),
        ];
    }

    $stmt->close();
    return $dados;
}

function fornecedor_snapshot(mysqli $conn, int $fornecedorId): ?array
{
    if ($fornecedorId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            nome,
            COALESCE(cnpj, '') AS cnpj,
            COALESCE(telefone, '') AS telefone,
            COALESCE(email, '') AS email,
            COALESCE(ativo, 1) AS ativo,
            COALESCE(observacao, '') AS observacao,
            criado_em
        FROM fornecedores
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Erro ao buscar snapshot do fornecedor.');
    }

    $stmt->bind_param('i', $fornecedorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'id'                  => (int)$row['id'],
        'nome'                => (string)$row['nome'],
        'cnpj'                => (string)$row['cnpj'],
        'telefone'            => (string)$row['telefone'],
        'email'               => (string)$row['email'],
        'ativo'               => (int)$row['ativo'],
        'observacao'          => (string)$row['observacao'],
        'criado_em'           => (string)$row['criado_em'],
        'total_produtos'      => fornecedor_total_produtos($conn, $fornecedorId),
        'total_movimentacoes' => fornecedor_total_movimentacoes($conn, $fornecedorId),
        'produtos'            => fornecedor_produtos_listar($conn, $fornecedorId),
    ];
}

function fornecedores_registrar_auditoria_segura(
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
        logWarning('fornecedores', 'Falha ao registrar auditoria de fornecedor', [
            'acao'        => $acao,
            'entidade'    => $entidade,
            'entidade_id' => $entidadeId,
            'usuario_id'  => $usuarioId
        ]);
    }
}

function fornecedor_esta_vinculado_produtos(mysqli $conn, int $fornecedorId): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM produto_fornecedores
        WHERE fornecedor_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Erro ao validar vínculo do fornecedor com produtos.');
    }

    $stmt->bind_param('i', $fornecedorId);
    $stmt->execute();
    $existe = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $existe;
}

function fornecedor_esta_vinculado_movimentacoes(mysqli $conn, int $fornecedorId): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM movimentacoes
        WHERE fornecedor_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Erro ao validar vínculo do fornecedor com movimentações.');
    }

    $stmt->bind_param('i', $fornecedorId);
    $stmt->execute();
    $existe = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $existe;
}

function fornecedor_esta_vinculado(mysqli $conn, int $fornecedorId): bool
{
    return fornecedor_esta_vinculado_produtos($conn, $fornecedorId)
        || fornecedor_esta_vinculado_movimentacoes($conn, $fornecedorId);
}

function fornecedores_listar(mysqli $conn): array
{
    try {
        $sql = "
            SELECT
                f.id,
                f.nome,
                COALESCE(f.cnpj, '') AS cnpj,
                COALESCE(f.telefone, '') AS telefone,
                COALESCE(f.email, '') AS email,
                COALESCE(f.ativo, 1) AS ativo,
                COALESCE(f.observacao, '') AS observacao,
                COUNT(DISTINCT pf.produto_id) AS total_produtos
            FROM fornecedores f
            LEFT JOIN produto_fornecedores pf
                ON pf.fornecedor_id = f.id
            GROUP BY
                f.id, f.nome, f.cnpj, f.telefone, f.email, f.ativo, f.observacao
            ORDER BY
                COALESCE(f.ativo, 1) DESC,
                f.nome ASC
        ";

        $res = $conn->query($sql);
        if (!$res) {
            return resposta(false, 'Erro ao listar fornecedores.', []);
        }

        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $fornecedorId = (int)$row['id'];

            $dados[] = [
                'id'                  => $fornecedorId,
                'nome'                => (string)$row['nome'],
                'cnpj'                => (string)$row['cnpj'],
                'telefone'            => (string)$row['telefone'],
                'email'               => (string)$row['email'],
                'ativo'               => (int)$row['ativo'],
                'observacao'          => (string)$row['observacao'],
                'total_produtos'      => (int)$row['total_produtos'],
                'total_movimentacoes' => fornecedor_total_movimentacoes($conn, $fornecedorId),
            ];
        }

        $res->free();

        return resposta(true, 'OK', $dados);
    } catch (Throwable $e) {
        logError('fornecedores', 'Erro ao listar fornecedores', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return resposta(false, 'Erro ao listar fornecedores.', []);
    }
}

function fornecedor_obter(mysqli $conn, int $fornecedorId): array
{
    try {
        if ($fornecedorId <= 0) {
            return resposta(false, 'Fornecedor inválido.', null);
        }

        $stmt = $conn->prepare("
            SELECT
                id,
                nome,
                COALESCE(cnpj, '') AS cnpj,
                COALESCE(telefone, '') AS telefone,
                COALESCE(email, '') AS email,
                COALESCE(ativo, 1) AS ativo,
                COALESCE(observacao, '') AS observacao,
                criado_em
            FROM fornecedores
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return resposta(false, 'Erro ao obter fornecedor.', null);
        }

        $stmt->bind_param('i', $fornecedorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return resposta(false, 'Fornecedor não encontrado.', null);
        }

        $produtos = fornecedor_produtos_listar($conn, $fornecedorId);

        return resposta(true, 'OK', [
            'id'                  => (int)$row['id'],
            'nome'                => (string)$row['nome'],
            'cnpj'                => (string)$row['cnpj'],
            'telefone'            => (string)$row['telefone'],
            'email'               => (string)$row['email'],
            'ativo'               => (int)$row['ativo'],
            'observacao'          => (string)$row['observacao'],
            'criado_em'           => (string)$row['criado_em'],
            'total_produtos'      => count($produtos),
            'total_movimentacoes' => fornecedor_total_movimentacoes($conn, $fornecedorId),
            'produtos'            => $produtos,
        ]);
    } catch (Throwable $e) {
        logError('fornecedores', 'Erro ao obter fornecedor', [
            'arquivo'       => $e->getFile(),
            'linha'         => $e->getLine(),
            'erro'          => $e->getMessage(),
            'fornecedor_id' => $fornecedorId
        ]);

        return resposta(false, 'Erro ao obter fornecedor.', null);
    }
}

function fornecedor_salvar(
    mysqli $conn,
    int $fornecedorId,
    string $nome,
    string $cnpj,
    string $telefone,
    string $email,
    int $ativo,
    string $observacao,
    ?int $usuarioId = null
): array {
    try {
        $nome = fornecedor_normalizar_texto($nome);
        $cnpj = fornecedor_normalizar_cnpj(fornecedor_normalizar_texto($cnpj));
        $telefone = fornecedor_normalizar_texto($telefone);
        $email = fornecedor_normalizar_texto($email);
        $observacao = fornecedor_normalizar_texto($observacao);

        if ($nome === '') {
            return resposta(false, 'Nome do fornecedor obrigatório.', null);
        }

        fornecedor_validar_tamanho($nome, FORNECEDOR_NOME_MAX_LEN, 'nome');
        fornecedor_validar_tamanho($telefone, FORNECEDOR_TELEFONE_MAX_LEN, 'telefone');
        fornecedor_validar_tamanho($email, FORNECEDOR_EMAIL_MAX_LEN, 'e-mail');
        fornecedor_validar_tamanho($observacao, FORNECEDOR_OBSERVACAO_MAX_LEN, 'observação');

        if (!in_array($ativo, [0, 1], true)) {
            return resposta(false, 'Status inválido.', null);
        }

        if (!fornecedor_email_valido($email)) {
            return resposta(false, 'Informe um e-mail válido.', null);
        }

        if ($cnpj !== '' && strlen($cnpj) !== 14) {
            return resposta(false, 'O CNPJ deve conter 14 dígitos.', null);
        }

        $stmtDupNome = $conn->prepare("
            SELECT id
            FROM fornecedores
            WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))
              AND id <> ?
            LIMIT 1
        ");
        if (!$stmtDupNome) {
            return resposta(false, 'Erro ao validar nome do fornecedor.', null);
        }

        $stmtDupNome->bind_param('si', $nome, $fornecedorId);
        $stmtDupNome->execute();
        $dupNome = $stmtDupNome->get_result()->fetch_assoc();
        $stmtDupNome->close();

        if ($dupNome) {
            return resposta(false, 'Já existe um fornecedor com esse nome.', null);
        }

        if ($cnpj !== '') {
            $stmtDupCnpj = $conn->prepare("
                SELECT id
                FROM fornecedores
                WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cnpj, ''), '.', ''), '-', ''), '/', ''), ' ', '') = ?
                  AND id <> ?
                LIMIT 1
            ");
            if (!$stmtDupCnpj) {
                return resposta(false, 'Erro ao validar CNPJ do fornecedor.', null);
            }

            $stmtDupCnpj->bind_param('si', $cnpj, $fornecedorId);
            $stmtDupCnpj->execute();
            $dupCnpj = $stmtDupCnpj->get_result()->fetch_assoc();
            $stmtDupCnpj->close();

            if ($dupCnpj) {
                return resposta(false, 'Já existe um fornecedor com esse CNPJ.', null);
            }
        }

        $conn->begin_transaction();

        if ($fornecedorId > 0) {
            $antes = fornecedor_snapshot($conn, $fornecedorId);

            if (!$antes) {
                $conn->rollback();
                return resposta(false, 'Fornecedor não encontrado.', null);
            }

            if ((int)($antes['ativo'] ?? 1) === 1 && $ativo === 0 && fornecedor_esta_vinculado($conn, $fornecedorId)) {
                $conn->rollback();
                return resposta(false, 'Este fornecedor possui vínculos com produtos ou movimentações e não pode ser inativado no momento.', null);
            }

            $stmt = $conn->prepare("
                UPDATE fornecedores
                SET
                    nome = ?,
                    cnpj = ?,
                    telefone = ?,
                    email = ?,
                    ativo = ?,
                    observacao = ?
                WHERE id = ?
            ");
            if (!$stmt) {
                $conn->rollback();
                return resposta(false, 'Erro ao atualizar fornecedor.', null);
            }

            $stmt->bind_param(
                'ssssisi',
                $nome,
                $cnpj,
                $telefone,
                $email,
                $ativo,
                $observacao,
                $fornecedorId
            );

            if (!$stmt->execute()) {
                $stmt->close();
                $conn->rollback();
                return resposta(false, 'Erro ao atualizar fornecedor.', null);
            }

            $stmt->close();

            $depois = fornecedor_snapshot($conn, $fornecedorId);

            fornecedores_registrar_auditoria_segura(
                $conn,
                $usuarioId,
                $ativo === 0 && (int)($antes['ativo'] ?? 1) === 1 ? 'inativar_fornecedor' : 'editar_fornecedor',
                'fornecedor',
                $fornecedorId,
                $antes,
                $depois
            );

            $conn->commit();

            return resposta(
                true,
                $ativo === 0 ? 'Fornecedor inativado com sucesso.' : 'Fornecedor atualizado com sucesso.',
                ['id' => $fornecedorId]
            );
        }

        $stmt = $conn->prepare("
            INSERT INTO fornecedores
                (nome, cnpj, telefone, email, ativo, observacao)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            $conn->rollback();
            return resposta(false, 'Erro ao cadastrar fornecedor.', null);
        }

        $stmt->bind_param(
            'ssssis',
            $nome,
            $cnpj,
            $telefone,
            $email,
            $ativo,
            $observacao
        );

        if (!$stmt->execute()) {
            $stmt->close();
            $conn->rollback();
            return resposta(false, 'Erro ao cadastrar fornecedor.', null);
        }

        $novoId = (int)$stmt->insert_id;
        $stmt->close();

        $depois = fornecedor_snapshot($conn, $novoId);

        fornecedores_registrar_auditoria_segura(
            $conn,
            $usuarioId,
            'criar_fornecedor',
            'fornecedor',
            $novoId,
            null,
            $depois
        );

        $conn->commit();

        return resposta(true, 'Fornecedor cadastrado com sucesso.', ['id' => $novoId]);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }

        logError('fornecedores', 'Erro ao salvar fornecedor', [
            'arquivo'       => $e->getFile(),
            'linha'         => $e->getLine(),
            'erro'          => $e->getMessage(),
            'fornecedor_id' => $fornecedorId,
            'nome'          => $nome ?? '',
            'usuario_id'    => $usuarioId
        ]);

        return resposta(false, 'Erro ao salvar fornecedor.', null);
    }
}
