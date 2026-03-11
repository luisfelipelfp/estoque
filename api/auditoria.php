Vou te mandar o meu api/usuarios.php, api/produtos.php, api/fornecedores.php, api/movimentacoes.php, e api/actions.php atual para que você possa analisar fazer todos os ajustes necessários e já me devolver de forma completa
api/usuarios.php
<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

initLog('usuarios');

function usuarios_eh_admin(array $usuario): bool
{
    $nivel = strtolower(trim((string)($usuario['nivel'] ?? '')));
    return $nivel === 'admin';
}

function usuarios_require_admin(array $usuario): void
{
    if (!usuarios_eh_admin($usuario)) {
        json_response(false, 'Acesso restrito para administradores.', null, 403);
    }
}

function usuarios_normalizar_nivel(string $nivel): string
{
    return strtolower(trim($nivel));
}

function usuarios_contar_admins_ativos(mysqli $conn, ?int $ignorarUsuarioId = null): int
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM usuarios
        WHERE LOWER(TRIM(nivel)) = 'admin'
          AND COALESCE(ativo, 1) = 1
    ";

    if ($ignorarUsuarioId !== null && $ignorarUsuarioId > 0) {
        $sql .= " AND id <> ?";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Erro ao contar administradores ativos.');
    }

    if ($ignorarUsuarioId !== null && $ignorarUsuarioId > 0) {
        $stmt->bind_param('i', $ignorarUsuarioId);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

function usuario_buscar_basico(mysqli $conn, int $usuarioId): ?array
{
    if ($usuarioId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            nome,
            email,
            LOWER(TRIM(nivel)) AS nivel,
            COALESCE(ativo, 1) AS ativo,
            criado_em
        FROM usuarios
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Erro ao buscar usuário.');
    }

    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function usuarios_listar(mysqli $conn): array
{
    try {
        $sql = "
            SELECT
                id,
                nome,
                email,
                LOWER(TRIM(nivel)) AS nivel,
                COALESCE(ativo, 1) AS ativo,
                criado_em
            FROM usuarios
            ORDER BY nome ASC, id ASC
        ";

        $res = $conn->query($sql);
        if (!$res) {
            return resposta(false, 'Erro ao listar usuários.');
        }

        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $dados[] = [
                'id'        => (int)$row['id'],
                'nome'      => (string)$row['nome'],
                'email'     => (string)$row['email'],
                'nivel'     => (string)$row['nivel'],
                'ativo'     => (int)$row['ativo'],
                'criado_em' => (string)$row['criado_em'],
            ];
        }

        $res->free();

        return resposta(true, 'Usuários listados com sucesso.', $dados);
    } catch (Throwable $e) {
        logError('usuarios', 'Erro ao listar usuários', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return resposta(false, 'Erro interno ao listar usuários.');
    }
}

function usuario_obter(mysqli $conn, int $usuarioId): array
{
    if ($usuarioId <= 0) {
        return resposta(false, 'Usuário inválido.');
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                id,
                nome,
                email,
                LOWER(TRIM(nivel)) AS nivel,
                COALESCE(ativo, 1) AS ativo,
                criado_em
            FROM usuarios
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return resposta(false, 'Erro ao obter usuário.');
        }

        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return resposta(false, 'Usuário não encontrado.');
        }

        return resposta(true, 'Usuário obtido com sucesso.', [
            'id'        => (int)$row['id'],
            'nome'      => (string)$row['nome'],
            'email'     => (string)$row['email'],
            'nivel'     => (string)$row['nivel'],
            'ativo'     => (int)$row['ativo'],
            'criado_em' => (string)$row['criado_em'],
        ]);
    } catch (Throwable $e) {
        logError('usuarios', 'Erro ao obter usuário', [
            'arquivo'    => $e->getFile(),
            'linha'      => $e->getLine(),
            'erro'       => $e->getMessage(),
            'usuario_id' => $usuarioId
        ]);

        return resposta(false, 'Erro interno ao obter usuário.');
    }
}

function usuario_salvar(
    mysqli $conn,
    int $usuarioId,
    string $nome,
    string $email,
    string $nivel,
    ?string $senha,
    int $ativo,
    int $usuarioLogadoId
): array {
    $nome = trim($nome);
    $email = trim($email);
    $nivel = usuarios_normalizar_nivel($nivel);
    $senha = $senha !== null ? trim($senha) : null;

    if ($nome === '') {
        return resposta(false, 'Nome do usuário é obrigatório.');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return resposta(false, 'Informe um e-mail válido.');
    }

    if (!in_array($nivel, ['admin', 'operador'], true)) {
        return resposta(false, 'Nível de usuário inválido.');
    }

    if (!in_array($ativo, [0, 1], true)) {
        return resposta(false, 'Status do usuário inválido.');
    }

    if ($usuarioId <= 0 && ($senha === null || $senha === '')) {
        return resposta(false, 'A senha é obrigatória para novo usuário.');
    }

    if ($senha !== null && $senha !== '' && mb_strlen($senha) < 6) {
        return resposta(false, 'A senha deve ter pelo menos 6 caracteres.');
    }

    try {
        $stmtDup = $conn->prepare("
            SELECT id
            FROM usuarios
            WHERE LOWER(email) = LOWER(?)
              AND id <> ?
            LIMIT 1
        ");
        if (!$stmtDup) {
            return resposta(false, 'Erro ao validar e-mail do usuário.');
        }

        $stmtDup->bind_param('si', $email, $usuarioId);
        $stmtDup->execute();
        $duplicado = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();

        if ($duplicado) {
            return resposta(false, 'Já existe um usuário com este e-mail.');
        }

        if ($usuarioId > 0) {
            $usuarioAtual = usuario_buscar_basico($conn, $usuarioId);

            if (!$usuarioAtual) {
                return resposta(false, 'Usuário não encontrado.');
            }

            $eraAdminAtivo = (
                strtolower((string)($usuarioAtual['nivel'] ?? '')) === 'admin'
                && (int)($usuarioAtual['ativo'] ?? 1) === 1
            );

            $seraAdminAtivo = ($nivel === 'admin' && $ativo === 1);

            if ($usuarioId === $usuarioLogadoId && $ativo === 0) {
                return resposta(false, 'Você não pode inativar o seu próprio usuário.');
            }

            if ($usuarioId === $usuarioLogadoId && $nivel !== 'admin') {
                return resposta(false, 'Você não pode remover o seu próprio perfil de administrador.');
            }

            if ($eraAdminAtivo && !$seraAdminAtivo) {
                $adminsRestantes = usuarios_contar_admins_ativos($conn, $usuarioId);

                if ($adminsRestantes <= 0) {
                    return resposta(false, 'Não é permitido inativar ou rebaixar o último administrador ativo do sistema.');
                }
            }

            if ($senha !== null && $senha !== '') {
                $hash = password_hash($senha, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("
                    UPDATE usuarios
                    SET nome = ?, email = ?, nivel = ?, ativo = ?, senha = ?
                    WHERE id = ?
                ");
                if (!$stmt) {
                    return resposta(false, 'Erro ao atualizar usuário.');
                }

                $stmt->bind_param('sssisi', $nome, $email, $nivel, $ativo, $hash, $usuarioId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    UPDATE usuarios
                    SET nome = ?, email = ?, nivel = ?, ativo = ?
                    WHERE id = ?
                ");
                if (!$stmt) {
                    return resposta(false, 'Erro ao atualizar usuário.');
                }

                $stmt->bind_param('sssii', $nome, $email, $nivel, $ativo, $usuarioId);
                $stmt->execute();
                $stmt->close();
            }

            logInfo('usuarios', 'Usuário atualizado', [
                'usuario_id'        => $usuarioId,
                'usuario_logado_id' => $usuarioLogadoId,
                'email'             => $email,
                'nivel'             => $nivel,
                'ativo'             => $ativo
            ]);

            return resposta(true, 'Usuário atualizado com sucesso.', [
                'id' => $usuarioId
            ]);
        }

        $hash = password_hash((string)$senha, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO usuarios (nome, email, senha, nivel, ativo)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return resposta(false, 'Erro ao cadastrar usuário.');
        }

        $stmt->bind_param('ssssi', $nome, $email, $hash, $nivel, $ativo);
        $stmt->execute();
        $novoId = (int)$stmt->insert_id;
        $stmt->close();

        logInfo('usuarios', 'Usuário criado', [
            'usuario_id'        => $novoId,
            'usuario_logado_id' => $usuarioLogadoId,
            'email'             => $email,
            'nivel'             => $nivel,
            'ativo'             => $ativo
        ]);

        return resposta(true, 'Usuário cadastrado com sucesso.', [
            'id' => $novoId
        ]);
    } catch (Throwable $e) {
        logError('usuarios', 'Erro ao salvar usuário', [
            'arquivo'           => $e->getFile(),
            'linha'             => $e->getLine(),
            'erro'              => $e->getMessage(),
            'usuario_id'        => $usuarioId,
            'usuario_logado_id' => $usuarioLogadoId,
            'email'             => $email
        ]);

        return resposta(false, 'Erro interno ao salvar usuário.');
    }
}

function usuario_alterar_status(
    mysqli $conn,
    int $usuarioId,
    int $ativo,
    int $usuarioLogadoId
): array {
    if ($usuarioId <= 0) {
        return resposta(false, 'Usuário inválido.');
    }

    if (!in_array($ativo, [0, 1], true)) {
        return resposta(false, 'Status inválido.');
    }

    if ($usuarioId === $usuarioLogadoId) {
        return resposta(false, 'Você não pode alterar o status do seu próprio usuário.');
    }

    try {
        $usuarioAtual = usuario_buscar_basico($conn, $usuarioId);

        if (!$usuarioAtual) {
            return resposta(false, 'Usuário não encontrado.');
        }

        $ehAdminAtivo = (
            strtolower((string)($usuarioAtual['nivel'] ?? '')) === 'admin'
            && (int)($usuarioAtual['ativo'] ?? 1) === 1
        );

        if ($ehAdminAtivo && $ativo === 0) {
            $adminsRestantes = usuarios_contar_admins_ativos($conn, $usuarioId);

            if ($adminsRestantes <= 0) {
                return resposta(false, 'Não é permitido inativar o último administrador ativo do sistema.');
            }
        }

        $stmt = $conn->prepare("
            UPDATE usuarios
            SET ativo = ?
            WHERE id = ?
        ");
        if (!$stmt) {
            return resposta(false, 'Erro ao alterar status do usuário.');
        }

        $stmt->bind_param('ii', $ativo, $usuarioId);
        $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();

        if ($afetadas <= 0 && (int)($usuarioAtual['ativo'] ?? 1) !== $ativo) {
            return resposta(false, 'Usuário não encontrado ou sem alterações.');
        }

        logInfo('usuarios', 'Status do usuário alterado', [
            'usuario_id'        => $usuarioId,
            'ativo'             => $ativo,
            'usuario_logado_id' => $usuarioLogadoId
        ]);

        return resposta(true, 'Status do usuário alterado com sucesso.', [
            'id'    => $usuarioId,
            'ativo' => $ativo
        ]);
    } catch (Throwable $e) {
        logError('usuarios', 'Erro ao alterar status do usuário', [
            'arquivo'           => $e->getFile(),
            'linha'             => $e->getLine(),
            'erro'              => $e->getMessage(),
            'usuario_id'        => $usuarioId,
            'usuario_logado_id' => $usuarioLogadoId
        ]);

        return resposta(false, 'Erro interno ao alterar status do usuário.');
    }
}

function usuario_excluir(
    mysqli $conn,
    int $usuarioId,
    int $usuarioLogadoId
): array {
    if ($usuarioId <= 0) {
        return resposta(false, 'Usuário inválido.');
    }

    if ($usuarioId === $usuarioLogadoId) {
        return resposta(false, 'Você não pode excluir o seu próprio usuário.');
    }

    try {
        $usuarioAtual = usuario_buscar_basico($conn, $usuarioId);

        if (!$usuarioAtual) {
            return resposta(false, 'Usuário não encontrado.');
        }

        $ehAdminAtivo = (
            strtolower((string)($usuarioAtual['nivel'] ?? '')) === 'admin'
            && (int)($usuarioAtual['ativo'] ?? 1) === 1
        );

        if ($ehAdminAtivo) {
            $adminsRestantes = usuarios_contar_admins_ativos($conn, $usuarioId);

            if ($adminsRestantes <= 0) {
                return resposta(false, 'Não é permitido excluir o último administrador ativo do sistema.');
            }
        }

        $stmt = $conn->prepare("
            DELETE FROM usuarios
            WHERE id = ?
        ");
        if (!$stmt) {
            return resposta(false, 'Erro ao excluir usuário.');
        }

        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();

        if ($afetadas <= 0) {
            return resposta(false, 'Usuário não encontrado.');
        }

        logInfo('usuarios', 'Usuário excluído', [
            'usuario_id'        => $usuarioId,
            'usuario_logado_id' => $usuarioLogadoId
        ]);

        return resposta(true, 'Usuário excluído com sucesso.', [
            'id' => $usuarioId
        ]);
    } catch (Throwable $e) {
        logError('usuarios', 'Erro ao excluir usuário', [
            'arquivo'           => $e->getFile(),
            'linha'             => $e->getLine(),
            'erro'              => $e->getMessage(),
            'usuario_id'        => $usuarioId,
            'usuario_logado_id' => $usuarioLogadoId
        ]);

        return resposta(false, 'Erro interno ao excluir usuário.');
    }
}

api/produtos.php
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

/**
 * Normaliza a estrutura de fornecedores recebida da actions.php.
 *
 * Regras:
 * - exige fornecedor_id > 0
 * - não usa nome para criar fornecedor
 * - garante apenas um principal
 * - sanitiza preços e textos
 */
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
        $precoCusto = (float)($f['preco_custo'] ?? 0);
        $precoVenda = (float)($f['preco_venda'] ?? 0);
        $observacao = trim((string)($f['observacao'] ?? ''));
        $principal = !empty($f['principal']) ? 1 : 0;

        if ($fornecedorId <= 0) {
            continue;
        }

        if (in_array($fornecedorId, $idsUsados, true)) {
            continue;
        }

        if ($precoCusto < 0) {
            $precoCusto = 0;
        }

        if ($precoVenda < 0) {
            $precoVenda = 0;
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

/**
 * Garante que todos os fornecedores informados realmente existam.
 */
function validar_fornecedores_existentes(mysqli $conn, array $fornecedores): void
{
    if (empty($fornecedores)) {
        return;
    }

    $stmt = $conn->prepare('SELECT id FROM fornecedores WHERE id = ? LIMIT 1');

    foreach ($fornecedores as $f) {
        $fornecedorId = (int)($f['fornecedor_id'] ?? 0);

        if ($fornecedorId <= 0) {
            throw new InvalidArgumentException('Fornecedor inválido informado para o produto.');
        }

        $stmt->bind_param('i', $fornecedorId);
        $stmt->execute();
        $existe = $stmt->get_result()->fetch_assoc();

        if (!$existe) {
            $stmt->close();
            throw new InvalidArgumentException('Fornecedor informado não existe.');
        }
    }

    $stmt->close();
}

function produto_fornecedores_salvar(mysqli $conn, int $produto_id, array $fornecedores): void
{
    $fornecedores = normalizar_fornecedores($fornecedores);
    validar_fornecedores_existentes($conn, $fornecedores);

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
            ORDER BY nome ASC, id ASC
        ";

        $res = $conn->query($sql);
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

        $like = '%' . $q . '%';

        $sql = "
            SELECT
                id,
                nome,
                " . ($hasNcm ? "COALESCE(ncm, '') AS ncm," : "'' AS ncm,") . "
                quantidade
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo, 0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo, 0) AS preco_custo" : ", 0 AS preco_custo") . "
            FROM produtos
            WHERE nome LIKE ?
            " . ($hasNcm ? "OR ncm LIKE ?" : "") . "
            ORDER BY nome ASC
            LIMIT ?
        ";

        $stmt = $conn->prepare($sql);

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

        $sqlP = "
            SELECT
                id,
                nome,
                " . ($hasNcm ? "COALESCE(ncm, '') AS ncm," : "'' AS ncm,") . "
                quantidade
                " . ($hasEstoqueMinimo ? ", COALESCE(estoque_minimo, 0) AS estoque_minimo" : ", 0 AS estoque_minimo") . "
                " . ($hasCusto ? ", COALESCE(preco_custo, 0) AS preco_custo" : ", 0 AS preco_custo") . "
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
                'ncm'            => (string)($prod['ncm'] ?? ''),
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
    ?string $ncm,
    int $quantidade,
    int $estoque_minimo,
    ?int $usuario_id,
    ?float $preco_custo = null,
    ?float $preco_venda = null,
    array $fornecedores = []
): array {
    try {
        $nome = trim($nome);
        $ncmNormalizado = normalizar_ncm($ncm);

        if ($nome === '') {
            return resposta(false, 'Nome do produto obrigatório.', null);
        }

        if ($quantidade < 0 || $estoque_minimo < 0) {
            return resposta(false, 'Dados inválidos para o produto.', null);
        }

        $fornecedoresNormalizados = normalizar_fornecedores($fornecedores);
        validar_fornecedores_existentes($conn, $fornecedoresNormalizados);

        $conn->begin_transaction();

        $precos = !empty($fornecedoresNormalizados)
            ? fornecedor_principal_preco($fornecedoresNormalizados)
            : [
                'preco_custo' => (($preco_custo !== null && $preco_custo >= 0) ? $preco_custo : 0.0),
                'preco_venda' => (($preco_venda !== null && $preco_venda >= 0) ? $preco_venda : 0.0),
            ];

        $pc = (float)$precos['preco_custo'];
        $pv = (float)$precos['preco_venda'];
        $hasNcm = coluna_existe($conn, 'produtos', 'ncm');

        if ($hasNcm) {
            $stmt = $conn->prepare(
                'INSERT INTO produtos (nome, ncm, quantidade, estoque_minimo, ativo, preco_custo, preco_venda)
                 VALUES (?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->bind_param('ssiidd', $nome, $ncmNormalizado, $quantidade, $estoque_minimo, $pc, $pv);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO produtos (nome, quantidade, estoque_minimo, ativo, preco_custo, preco_venda)
                 VALUES (?, ?, ?, 1, ?, ?)'
            );
            $stmt->bind_param('siidd', $nome, $quantidade, $estoque_minimo, $pc, $pv);
        }

        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        if (!empty($fornecedoresNormalizados)) {
            produto_fornecedores_salvar($conn, $id, $fornecedoresNormalizados);
        }

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
            'nome'           => $nome,
            'ncm'            => $ncm ?? null,
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
    ?string $ncm,
    int $quantidade,
    int $estoque_minimo,
    float $preco_custo,
    float $preco_venda,
    ?int $usuario_id,
    array $fornecedores = []
): array {
    try {
        $nome = trim($nome);
        $ncmNormalizado = normalizar_ncm($ncm);

        if ($produto_id <= 0 || $nome === '' || $quantidade < 0 || $estoque_minimo < 0 || $preco_custo < 0 || $preco_venda < 0) {
            return resposta(false, 'Dados inválidos para atualização do produto.', null);
        }

        $fornecedoresNormalizados = normalizar_fornecedores($fornecedores);
        validar_fornecedores_existentes($conn, $fornecedoresNormalizados);

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

        $precos = !empty($fornecedoresNormalizados)
            ? fornecedor_principal_preco($fornecedoresNormalizados)
            : [
                'preco_custo' => $preco_custo,
                'preco_venda' => $preco_venda,
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
            $stmt->bind_param('ssiiddi', $nome, $ncmNormalizado, $quantidade, $estoque_minimo, $pc, $pv, $produto_id);
        } else {
            $stmt = $conn->prepare(
                'UPDATE produtos
                 SET nome = ?, quantidade = ?, estoque_minimo = ?, preco_custo = ?, preco_venda = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('siiddi', $nome, $quantidade, $estoque_minimo, $pc, $pv, $produto_id);
        }

        $stmt->execute();
        $stmt->close();

        produto_fornecedores_salvar($conn, $produto_id, $fornecedoresNormalizados);

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
            'nome'           => $nome,
            'ncm'            => $ncm ?? null,
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

api/fornecedores.php
<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

initLog('fornecedores');

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
            ORDER BY f.nome ASC
        ";

        $res = $conn->query($sql);
        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $dados[] = [
                'id'             => (int)$row['id'],
                'nome'           => (string)$row['nome'],
                'cnpj'           => (string)$row['cnpj'],
                'telefone'       => (string)$row['telefone'],
                'email'          => (string)$row['email'],
                'ativo'          => (int)$row['ativo'],
                'observacao'     => (string)$row['observacao'],
                'total_produtos' => (int)$row['total_produtos'],
            ];
        }

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

function fornecedor_produtos_listar(mysqli $conn, int $fornecedor_id): array
{
    $sql = "
        SELECT
            p.id,
            p.nome,
            COALESCE(pf.codigo_produto_fornecedor, '') AS codigo_produto_fornecedor,
            COALESCE(pf.observacao, '') AS observacao,
            COALESCE(pf.principal, 0) AS principal
        FROM produto_fornecedores pf
        INNER JOIN produtos p
            ON p.id = pf.produto_id
        WHERE pf.fornecedor_id = ?
        ORDER BY p.nome ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $fornecedor_id);
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
        ];
    }

    $stmt->close();
    return $dados;
}

function fornecedor_obter(mysqli $conn, int $fornecedor_id): array
{
    try {
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
        $stmt->bind_param('i', $fornecedor_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return resposta(false, 'Fornecedor não encontrado.', null);
        }

        $produtos = fornecedor_produtos_listar($conn, $fornecedor_id);

        return resposta(true, 'OK', [
            'id'             => (int)$row['id'],
            'nome'           => (string)$row['nome'],
            'cnpj'           => (string)$row['cnpj'],
            'telefone'       => (string)$row['telefone'],
            'email'          => (string)$row['email'],
            'ativo'          => (int)$row['ativo'],
            'observacao'     => (string)$row['observacao'],
            'criado_em'      => (string)$row['criado_em'],
            'total_produtos' => count($produtos),
            'produtos'       => $produtos,
        ]);
    } catch (Throwable $e) {
        logError('fornecedores', 'Erro ao obter fornecedor', [
            'arquivo'       => $e->getFile(),
            'linha'         => $e->getLine(),
            'erro'          => $e->getMessage(),
            'fornecedor_id' => $fornecedor_id
        ]);

        return resposta(false, 'Erro ao obter fornecedor.', null);
    }
}

function fornecedor_salvar(
    mysqli $conn,
    int $fornecedor_id,
    string $nome,
    string $cnpj,
    string $telefone,
    string $email,
    int $ativo,
    string $observacao,
    ?int $usuario_id = null
): array {
    try {
        $nome = trim($nome);
        $cnpj = trim($cnpj);
        $telefone = trim($telefone);
        $email = trim($email);
        $observacao = trim($observacao);

        if ($nome === '') {
            return resposta(false, 'Nome do fornecedor obrigatório.', null);
        }

        $stmtDup = $conn->prepare("
            SELECT id
            FROM fornecedores
            WHERE LOWER(nome) = LOWER(?)
              AND id <> ?
            LIMIT 1
        ");
        $stmtDup->bind_param('si', $nome, $fornecedor_id);
        $stmtDup->execute();
        $dup = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();

        if ($dup) {
            return resposta(false, 'Já existe um fornecedor com esse nome.', null);
        }

        if ($fornecedor_id > 0) {
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
            $stmt->bind_param(
                'ssssisi',
                $nome,
                $cnpj,
                $telefone,
                $email,
                $ativo,
                $observacao,
                $fornecedor_id
            );
            $stmt->execute();
            $stmt->close();

            return resposta(true, 'Fornecedor atualizado com sucesso.', ['id' => $fornecedor_id]);
        }

        $stmt = $conn->prepare("
            INSERT INTO fornecedores
                (nome, cnpj, telefone, email, ativo, observacao)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssssis',
            $nome,
            $cnpj,
            $telefone,
            $email,
            $ativo,
            $observacao
        );
        $stmt->execute();
        $novoId = (int)$stmt->insert_id;
        $stmt->close();

        return resposta(true, 'Fornecedor cadastrado com sucesso.', ['id' => $novoId]);
    } catch (Throwable $e) {
        logError('fornecedores', 'Erro ao salvar fornecedor', [
            'arquivo'       => $e->getFile(),
            'linha'         => $e->getLine(),
            'erro'          => $e->getMessage(),
            'fornecedor_id' => $fornecedor_id,
            'nome'          => $nome,
            'usuario_id'    => $usuario_id
        ]);

        return resposta(false, 'Erro ao salvar fornecedor.', null);
    }
}

api/movimentacoes.php
<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';

initLog('movimentacoes');

function coluna_existe_mov(mysqli $conn, string $tabela, string $coluna): bool
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

function mov_obter_produto_for_update(mysqli $conn, int $produto_id): ?array
{
    $stmt = $conn->prepare("
        SELECT
            id,
            nome,
            quantidade,
            COALESCE(preco_custo, 0) AS preco_custo,
            COALESCE(preco_venda, 0) AS preco_venda
        FROM produtos
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function mov_inserir_registro(
    mysqli $conn,
    int $produto_id,
    string $produto_nome,
    ?int $fornecedor_id,
    string $tipo,
    int $quantidade,
    ?float $valor_unitario,
    ?float $custo_unitario,
    ?float $custo_total,
    ?float $valor_total,
    ?float $lucro,
    ?int $usuario_id,
    ?string $observacao
): int {
    $hasFornecedorId = coluna_existe_mov($conn, 'movimentacoes', 'fornecedor_id');
    $hasObs          = coluna_existe_mov($conn, 'movimentacoes', 'observacao');
    $hasCustoUnit    = coluna_existe_mov($conn, 'movimentacoes', 'custo_unitario');
    $hasCustoTotal   = coluna_existe_mov($conn, 'movimentacoes', 'custo_total');
    $hasValorTotal   = coluna_existe_mov($conn, 'movimentacoes', 'valor_total');
    $hasLucro        = coluna_existe_mov($conn, 'movimentacoes', 'lucro');
    $hasValorUnit    = coluna_existe_mov($conn, 'movimentacoes', 'valor_unitario');

    $cols  = ['produto_id', 'produto_nome'];
    $vals  = ['?', '?'];
    $types = 'is';
    $bind  = [$produto_id, $produto_nome];

    if ($hasFornecedorId) {
        $cols[] = 'fornecedor_id';
        $vals[] = '?';
        $types .= 'i';
        $bind[] = $fornecedor_id;
    }

    $cols[] = 'tipo';
    $vals[] = '?';
    $types .= 's';
    $bind[] = $tipo;

    $cols[] = 'quantidade';
    $vals[] = '?';
    $types .= 'i';
    $bind[] = $quantidade;

    if ($hasValorUnit) {
        $cols[] = 'valor_unitario';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $valor_unitario;
    }

    if ($hasCustoUnit) {
        $cols[] = 'custo_unitario';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $custo_unitario;
    }

    if ($hasCustoTotal) {
        $cols[] = 'custo_total';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $custo_total;
    }

    if ($hasValorTotal) {
        $cols[] = 'valor_total';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $valor_total;
    }

    if ($hasLucro) {
        $cols[] = 'lucro';
        $vals[] = '?';
        $types .= 'd';
        $bind[] = $lucro;
    }

    $cols[] = 'data';
    $vals[] = 'NOW()';

    $cols[] = 'usuario_id';
    $vals[] = '?';
    $types .= 'i';
    $bind[] = $usuario_id;

    if ($hasObs) {
        $cols[] = 'observacao';
        $vals[] = '?';
        $types .= 's';
        $bind[] = $observacao;
    }

    $sql = 'INSERT INTO movimentacoes (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
}

function mov_criar_lote_entrada(
    mysqli $conn,
    int $produto_id,
    ?int $fornecedor_id,
    int $movimentacao_entrada_id,
    int $quantidade,
    float $custo_unitario
): void {
    $stmt = $conn->prepare("
        INSERT INTO estoque_lotes
            (produto_id, fornecedor_id, movimentacao_entrada_id, quantidade_entrada, quantidade_restante, custo_unitario)
        VALUES
            (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'iiiiid',
        $produto_id,
        $fornecedor_id,
        $movimentacao_entrada_id,
        $quantidade,
        $quantidade,
        $custo_unitario
    );
    $stmt->execute();
    $stmt->close();
}

function mov_buscar_lotes_fifo(mysqli $conn, int $produto_id): array
{
    $stmt = $conn->prepare("
        SELECT
            id,
            quantidade_entrada,
            quantidade_restante,
            custo_unitario,
            criado_em
        FROM estoque_lotes
        WHERE produto_id = ?
          AND quantidade_restante > 0
        ORDER BY criado_em ASC, id ASC
        FOR UPDATE
    ");
    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $lotes = [];
    while ($row = $res->fetch_assoc()) {
        $lotes[] = [
            'id'                  => (int)$row['id'],
            'quantidade_entrada'  => (int)$row['quantidade_entrada'],
            'quantidade_restante' => (int)$row['quantidade_restante'],
            'custo_unitario'      => (float)$row['custo_unitario'],
            'criado_em'           => (string)$row['criado_em'],
        ];
    }

    $stmt->close();
    return $lotes;
}

function mov_planejar_consumo_fifo(mysqli $conn, int $produto_id, int $quantidade): array
{
    $lotes = mov_buscar_lotes_fifo($conn, $produto_id);

    if (empty($lotes)) {
        return [
            'ok' => false,
            'mensagem' => 'Este produto possui estoque sem lotes vinculados. Faça a carga inicial dos lotes antes de registrar saídas.'
        ];
    }

    $disponivel = 0;
    foreach ($lotes as $lote) {
        $disponivel += (int)$lote['quantidade_restante'];
    }

    if ($disponivel < $quantidade) {
        return [
            'ok' => false,
            'mensagem' => 'Quantidade insuficiente em estoque por lote.'
        ];
    }

    $restante = $quantidade;
    $consumos = [];
    $custoTotal = 0.0;

    foreach ($lotes as $lote) {
        if ($restante <= 0) {
            break;
        }

        $saldoLote = (int)$lote['quantidade_restante'];
        if ($saldoLote <= 0) {
            continue;
        }

        $qtdConsumida = min($restante, $saldoLote);
        $custoUnitario = (float)$lote['custo_unitario'];
        $custoLinha = $qtdConsumida * $custoUnitario;

        $consumos[] = [
            'lote_id'              => (int)$lote['id'],
            'quantidade_consumida' => $qtdConsumida,
            'custo_unitario'       => $custoUnitario,
            'custo_total'          => $custoLinha
        ];

        $custoTotal += $custoLinha;
        $restante -= $qtdConsumida;
    }

    if ($restante > 0) {
        return [
            'ok' => false,
            'mensagem' => 'Não foi possível montar o consumo FIFO completo.'
        ];
    }

    return [
        'ok'             => true,
        'consumos'       => $consumos,
        'custo_total'    => $custoTotal,
        'custo_unitario' => $quantidade > 0 ? ($custoTotal / $quantidade) : 0.0
    ];
}

function mov_aplicar_consumo_fifo(
    mysqli $conn,
    int $movimentacao_saida_id,
    array $consumos
): void {
    $stmtUpd = $conn->prepare("
        UPDATE estoque_lotes
        SET quantidade_restante = quantidade_restante - ?
        WHERE id = ?
          AND quantidade_restante >= ?
    ");

    $stmtIns = $conn->prepare("
        INSERT INTO movimentacao_saida_lotes
            (movimentacao_saida_id, lote_id, quantidade_consumida, custo_unitario, custo_total)
        VALUES
            (?, ?, ?, ?, ?)
    ");

    foreach ($consumos as $item) {
        $qtd = (int)$item['quantidade_consumida'];
        $loteId = (int)$item['lote_id'];
        $custoUnit = (float)$item['custo_unitario'];
        $custoTotal = (float)$item['custo_total'];

        $stmtUpd->bind_param('iii', $qtd, $loteId, $qtd);
        $stmtUpd->execute();

        if ($stmtUpd->affected_rows <= 0) {
            throw new RuntimeException('Falha ao baixar saldo do lote ' . $loteId);
        }

        $stmtIns->bind_param(
            'iiidd',
            $movimentacao_saida_id,
            $loteId,
            $qtd,
            $custoUnit,
            $custoTotal
        );
        $stmtIns->execute();
    }

    $stmtUpd->close();
    $stmtIns->close();
}

function mov_registrar(
    mysqli $conn,
    int $produto_id,
    string $tipo,
    int $quantidade,
    ?int $usuario_id,
    ?float $preco_custo = null,
    ?float $valor_unitario = null,
    ?string $observacao = null,
    ?int $fornecedor_id = null
): array {
    if ($produto_id <= 0 || $quantidade <= 0) {
        logWarning('movimentacoes', 'Dados inválidos para movimentação', [
            'produto_id' => $produto_id,
            'quantidade' => $quantidade,
            'tipo'       => $tipo
        ]);
        return resposta(false, 'Dados inválidos.');
    }

    if (!in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
        logWarning('movimentacoes', 'Tipo de movimentação inválido', ['tipo' => $tipo]);
        return resposta(false, 'Tipo de movimentação inválido.');
    }

    if ($preco_custo !== null && $preco_custo < 0) {
        $preco_custo = null;
    }

    if ($valor_unitario !== null && $valor_unitario < 0) {
        $valor_unitario = null;
    }

    $conn->begin_transaction();

    try {
        $produto = mov_obter_produto_for_update($conn, $produto_id);

        if (!$produto) {
            $conn->rollback();
            logWarning('movimentacoes', 'Produto não encontrado', ['produto_id' => $produto_id]);
            return resposta(false, 'Produto não encontrado.');
        }

        $nomeProduto = (string)$produto['nome'];
        $estoqueAtual = (int)$produto['quantidade'];
        $precoVendaPadrao = (float)($produto['preco_venda'] ?? 0);

        $custoUnitarioMov = null;
        $custoTotalMov = null;
        $valorUnitarioMov = null;
        $valorTotalMov = null;
        $lucroMov = null;
        $consumosFIFO = [];

        if ($tipo === 'entrada') {
            if ($preco_custo === null || $preco_custo <= 0) {
                $conn->rollback();
                return resposta(false, 'Na entrada é obrigatório informar um preço de custo válido.');
            }

            $custoUnitarioMov = (float)$preco_custo;
            $custoTotalMov = $quantidade * $custoUnitarioMov;
            $valorUnitarioMov = $valor_unitario !== null ? (float)$valor_unitario : $custoUnitarioMov;
            $valorTotalMov = $valorUnitarioMov !== null ? ($quantidade * $valorUnitarioMov) : null;
            $lucroMov = null;

            $stmtUpd = $conn->prepare('UPDATE produtos SET quantidade = quantidade + ?, preco_custo = ? WHERE id = ?');
            $stmtUpd->bind_param('idi', $quantidade, $custoUnitarioMov, $produto_id);
            $stmtUpd->execute();
            $stmtUpd->close();
        } else {
            if ($estoqueAtual < $quantidade) {
                $conn->rollback();
                logWarning('movimentacoes', 'Estoque insuficiente', [
                    'produto_id' => $produto_id,
                    'estoque'    => $estoqueAtual,
                    'solicitado' => $quantidade
                ]);
                return resposta(false, 'Quantidade insuficiente em estoque.');
            }

            $plano = mov_planejar_consumo_fifo($conn, $produto_id, $quantidade);
            if (!(bool)($plano['ok'] ?? false)) {
                $conn->rollback();
                return resposta(false, (string)($plano['mensagem'] ?? 'Falha ao calcular consumo FIFO.'));
            }

            $consumosFIFO = $plano['consumos'] ?? [];
            $custoTotalMov = (float)($plano['custo_total'] ?? 0);
            $custoUnitarioMov = (float)($plano['custo_unitario'] ?? 0);

            if ($tipo === 'saida') {
                if ($valor_unitario !== null && $valor_unitario > 0) {
                    $valorUnitarioMov = (float)$valor_unitario;
                } elseif ($precoVendaPadrao > 0) {
                    $valorUnitarioMov = $precoVendaPadrao;
                } else {
                    $valorUnitarioMov = null;
                }

                $valorTotalMov = $valorUnitarioMov !== null ? ($quantidade * $valorUnitarioMov) : null;
                $lucroMov = $valorTotalMov !== null ? ($valorTotalMov - $custoTotalMov) : null;
            } else {
                $valorUnitarioMov = null;
                $valorTotalMov = null;
                $lucroMov = null;
            }

            $stmtUpd = $conn->prepare('UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?');
            $stmtUpd->bind_param('ii', $quantidade, $produto_id);
            $stmtUpd->execute();
            $stmtUpd->close();
        }

        $movimentacaoId = mov_inserir_registro(
            $conn,
            $produto_id,
            $nomeProduto,
            $fornecedor_id,
            $tipo,
            $quantidade,
            $valorUnitarioMov,
            $custoUnitarioMov,
            $custoTotalMov,
            $valorTotalMov,
            $lucroMov,
            $usuario_id,
            $observacao
        );

        if ($tipo === 'entrada') {
            mov_criar_lote_entrada(
                $conn,
                $produto_id,
                $fornecedor_id,
                $movimentacaoId,
                $quantidade,
                (float)$custoUnitarioMov
            );
        } else {
            mov_aplicar_consumo_fifo($conn, $movimentacaoId, $consumosFIFO);
        }

        $conn->commit();

        logInfo('movimentacoes', 'Movimentação registrada com FIFO/lote', [
            'movimentacao_id' => $movimentacaoId,
            'produto_id'      => $produto_id,
            'fornecedor_id'   => $fornecedor_id,
            'tipo'            => $tipo,
            'quantidade'      => $quantidade,
            'usuario_id'      => $usuario_id,
            'custo_unitario'  => $custoUnitarioMov,
            'custo_total'     => $custoTotalMov,
            'valor_unitario'  => $valorUnitarioMov,
            'valor_total'     => $valorTotalMov,
            'lucro'           => $lucroMov,
            'observacao'      => $observacao
        ]);

        return resposta(true, 'Movimentação registrada com sucesso.', [
            'movimentacao_id' => $movimentacaoId,
            'custo_unitario'  => $custoUnitarioMov,
            'custo_total'     => $custoTotalMov,
            'valor_unitario'  => $valorUnitarioMov,
            'valor_total'     => $valorTotalMov,
            'lucro'           => $lucroMov
        ]);
    } catch (Throwable $e) {
        $conn->rollback();

        logError('movimentacoes', 'Erro ao registrar movimentação', [
            'arquivo'       => $e->getFile(),
            'linha'         => $e->getLine(),
            'erro'          => $e->getMessage(),
            'produto_id'    => $produto_id,
            'fornecedor_id' => $fornecedor_id,
            'tipo'          => $tipo,
            'quantidade'    => $quantidade,
            'usuario_id'    => $usuario_id
        ]);

        return resposta(false, 'Erro interno ao registrar movimentação.');
    }
}

function mov_listar(mysqli $conn, array $f): array
{
    $pagina = max(1, (int)($f['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($f['limite'] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    $where  = [];
    $params = [];
    $types  = '';

    if (!empty($f['produto_id'])) {
        $where[]  = 'm.produto_id = ?';
        $params[] = (int)$f['produto_id'];
        $types   .= 'i';
    }

    if (!empty($f['produto'])) {
        $termo = '%' . trim((string)$f['produto']) . '%';
        $where[] = '(COALESCE(m.produto_nome, p.nome, "") LIKE ?)';
        $params[] = $termo;
        $types .= 's';
    }

    if (!empty($f['fornecedor_id'])) {
        $where[]  = 'm.fornecedor_id = ?';
        $params[] = (int)$f['fornecedor_id'];
        $types   .= 'i';
    }

    if (!empty($f['tipo']) && in_array($f['tipo'], ['entrada', 'saida', 'remocao'], true)) {
        $where[]  = 'm.tipo = ?';
        $params[] = (string)$f['tipo'];
        $types   .= 's';
    }

    if (!empty($f['data_inicio'])) {
        $where[]  = 'm.data >= ?';
        $params[] = (string)$f['data_inicio'] . ' 00:00:00';
        $types   .= 's';
    }

    if (!empty($f['data_fim'])) {
        $where[]  = 'm.data <= ?';
        $params[] = (string)$f['data_fim'] . ' 23:59:59';
        $types   .= 's';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    try {
        $sqlCount = "
            SELECT COUNT(*) AS total
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            $whereSql
        ";
        $stmtT = $conn->prepare($sqlCount);
        if ($types !== '') {
            $stmtT->bind_param($types, ...$params);
        }
        $stmtT->execute();
        $total = (int)($stmtT->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtT->close();

        $sql = "
            SELECT
                m.id,
                COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                m.produto_id,
                m.fornecedor_id,
                COALESCE(f.nome, '') AS fornecedor_nome,
                m.tipo,
                m.quantidade,
                m.valor_unitario,
                m.custo_unitario,
                m.custo_total,
                m.valor_total,
                m.lucro,
                m.observacao,
                m.data,
                COALESCE(u.nome, 'Sistema') AS usuario
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            $whereSql
            ORDER BY m.data DESC, m.id DESC
            LIMIT ? OFFSET ?
        ";

        $paramsPage = $params;
        $typesPage = $types . 'ii';
        $paramsPage[] = $limite;
        $paramsPage[] = $offset;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($typesPage, ...$paramsPage);
        $stmt->execute();

        $res = $stmt->get_result();
        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $dados[] = [
                'id'              => (int)$row['id'],
                'produto_id'      => $row['produto_id'] !== null ? (int)$row['produto_id'] : null,
                'produto_nome'    => (string)$row['produto_nome'],
                'fornecedor_id'   => $row['fornecedor_id'] !== null ? (int)$row['fornecedor_id'] : null,
                'fornecedor_nome' => (string)$row['fornecedor_nome'],
                'tipo'            => (string)$row['tipo'],
                'quantidade'      => (int)$row['quantidade'],
                'valor_unitario'  => $row['valor_unitario'] !== null ? (float)$row['valor_unitario'] : null,
                'custo_unitario'  => $row['custo_unitario'] !== null ? (float)$row['custo_unitario'] : null,
                'custo_total'     => $row['custo_total'] !== null ? (float)$row['custo_total'] : null,
                'valor_total'     => $row['valor_total'] !== null ? (float)$row['valor_total'] : null,
                'lucro'           => $row['lucro'] !== null ? (float)$row['lucro'] : null,
                'observacao'      => (string)($row['observacao'] ?? ''),
                'data'            => (string)$row['data'],
                'usuario'         => (string)$row['usuario']
            ];
        }

        $stmt->close();

        return resposta(true, '', [
            'total'   => $total,
            'pagina'  => $pagina,
            'limite'  => $limite,
            'paginas' => (int)ceil($total / $limite),
            'dados'   => $dados
        ]);
    } catch (Throwable $e) {
        logError('movimentacoes', 'Erro ao listar movimentações', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage()
        ]);

        return resposta(false, 'Erro interno ao listar movimentações.');
    }
}

function mov_obter(mysqli $conn, int $movimentacaoId): array
{
    if ($movimentacaoId <= 0) {
        return resposta(false, 'Movimentação inválida.');
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                m.id,
                m.produto_id,
                COALESCE(m.produto_nome, p.nome, '[Produto removido]') AS produto_nome,
                m.fornecedor_id,
                COALESCE(f.nome, '') AS fornecedor_nome,
                m.tipo,
                m.quantidade,
                m.valor_unitario,
                m.custo_unitario,
                m.custo_total,
                m.valor_total,
                m.lucro,
                m.observacao,
                m.data,
                COALESCE(u.nome, 'Sistema') AS usuario
            FROM movimentacoes m
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $movimentacaoId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return resposta(false, 'Movimentação não encontrada.');
        }

        $dados = [
            'id'              => (int)$row['id'],
            'produto_id'      => $row['produto_id'] !== null ? (int)$row['produto_id'] : null,
            'produto_nome'    => (string)$row['produto_nome'],
            'fornecedor_id'   => $row['fornecedor_id'] !== null ? (int)$row['fornecedor_id'] : null,
            'fornecedor_nome' => (string)$row['fornecedor_nome'],
            'tipo'            => (string)$row['tipo'],
            'quantidade'      => (int)$row['quantidade'],
            'valor_unitario'  => $row['valor_unitario'] !== null ? (float)$row['valor_unitario'] : null,
            'custo_unitario'  => $row['custo_unitario'] !== null ? (float)$row['custo_unitario'] : null,
            'custo_total'     => $row['custo_total'] !== null ? (float)$row['custo_total'] : null,
            'valor_total'     => $row['valor_total'] !== null ? (float)$row['valor_total'] : null,
            'lucro'           => $row['lucro'] !== null ? (float)$row['lucro'] : null,
            'observacao'      => (string)($row['observacao'] ?? ''),
            'data'            => (string)$row['data'],
            'usuario'         => (string)$row['usuario'],
            'lotes'           => []
        ];

        $stmtLotes = $conn->prepare("
            SELECT
                msl.lote_id,
                msl.quantidade_consumida,
                msl.custo_unitario,
                msl.custo_total,
                el.fornecedor_id,
                COALESCE(f.nome, '') AS fornecedor_nome,
                el.quantidade_entrada,
                el.criado_em AS lote_criado_em
            FROM movimentacao_saida_lotes msl
            INNER JOIN estoque_lotes el ON el.id = msl.lote_id
            LEFT JOIN fornecedores f ON f.id = el.fornecedor_id
            WHERE msl.movimentacao_saida_id = ?
            ORDER BY msl.id ASC
        ");
        $stmtLotes->bind_param('i', $movimentacaoId);
        $stmtLotes->execute();
        $resLotes = $stmtLotes->get_result();

        while ($l = $resLotes->fetch_assoc()) {
            $dados['lotes'][] = [
                'lote_id'              => (int)$l['lote_id'],
                'quantidade_consumida' => (int)$l['quantidade_consumida'],
                'custo_unitario'       => (float)$l['custo_unitario'],
                'custo_total'          => (float)$l['custo_total'],
                'fornecedor_id'        => $l['fornecedor_id'] !== null ? (int)$l['fornecedor_id'] : null,
                'fornecedor_nome'      => (string)($l['fornecedor_nome'] ?? ''),
                'quantidade_entrada'   => (int)($l['quantidade_entrada'] ?? 0),
                'lote_criado_em'       => (string)($l['lote_criado_em'] ?? '')
            ];
        }

        $stmtLotes->close();

        return resposta(true, '', $dados);
    } catch (Throwable $e) {
        logError('movimentacoes', 'Erro ao obter movimentação', [
            'arquivo' => $e->getFile(),
            'linha'   => $e->getLine(),
            'erro'    => $e->getMessage(),
            'movimentacao_id' => $movimentacaoId
        ]);

        return resposta(false, 'Erro interno ao obter movimentação.');
    }
}

api/actions.php
<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const SESSION_TIMEOUT_SECONDS = 7200; // 2 horas

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

require_once __DIR__ . '/fornecedores.php';
require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/movimentacoes.php';
require_once __DIR__ . '/relatorios.php';
require_once __DIR__ . '/usuarios.php';

initLog('actions');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function set_cors_origin(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

    $allowed = [
        'http://192.168.15.100',
        'https://192.168.15.100',
        'http://localhost',
        'http://127.0.0.1',
    ];

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        return;
    }

    header('Access-Control-Allow-Origin: https://192.168.15.100');
}
set_cors_origin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function read_body(): array
{
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    $raw = file_get_contents('php://input');

    if (str_contains($contentType, 'application/json')) {
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    return [];
}

function get_action(array $body): string
{
    $acao = $_GET['acao'] ?? $_POST['acao'] ?? $body['acao'] ?? '';
    return trim((string)$acao);
}

function sanitize_for_log(array $data): array
{
    $maskedKeys = [
        'senha',
        'password',
        'token',
        'access_token',
        'refresh_token'
    ];

    $sanitized = $data;

    array_walk_recursive($sanitized, function (&$value, $key) use ($maskedKeys): void {
        if (in_array((string)$key, $maskedKeys, true)) {
            $value = '***';
        }
    });

    return $sanitized;
}

function destroy_user_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

function apply_session_timeout(string $acao): void
{
    $publicActions = [
        'login',
        'logout',
    ];

    if (in_array($acao, $publicActions, true)) {
        return;
    }

    if (!empty($_SESSION['usuario']) && isset($_SESSION['LAST_ACTIVITY'])) {
        $elapsed = time() - (int)$_SESSION['LAST_ACTIVITY'];

        if ($elapsed > SESSION_TIMEOUT_SECONDS) {
            destroy_user_session();
            json_response(false, 'Sessão expirada. Faça login novamente.', null, 401);
        }
    }

    if (!empty($_SESSION['usuario'])) {
        $_SESSION['LAST_ACTIVITY'] = time();
    }
}

function require_auth(): array
{
    if (empty($_SESSION['usuario']) || !is_array($_SESSION['usuario'])) {
        json_response(false, 'Usuário não autenticado.', null, 401);
    }

    $_SESSION['LAST_ACTIVITY'] = time();

    return $_SESSION['usuario'];
}

function normalizar_login(string $login): string
{
    return trim(mb_strtolower($login));
}

function normalizar_fornecedores_payload(mysqli $conn, mixed $fornecedoresRaw): array
{
    if (!is_array($fornecedoresRaw) || empty($fornecedoresRaw)) {
        return [];
    }

    $normalizados = [];
    $idsUsados = [];

    foreach ($fornecedoresRaw as $item) {
        if (!is_array($item)) {
            continue;
        }

        $fornecedorId = (int)($item['fornecedor_id'] ?? 0);

        if ($fornecedorId <= 0) {
            continue;
        }

        if (in_array($fornecedorId, $idsUsados, true)) {
            throw new InvalidArgumentException('O mesmo fornecedor não pode ser adicionado mais de uma vez para o mesmo produto.');
        }

        $stmt = $conn->prepare("
            SELECT id, nome, ativo
            FROM fornecedores
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new RuntimeException('Erro ao validar fornecedor.');
        }

        $stmt->bind_param('i', $fornecedorId);
        $stmt->execute();
        $fornecedorDb = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fornecedorDb) {
            throw new InvalidArgumentException('Fornecedor informado não existe.');
        }

        if ((int)($fornecedorDb['ativo'] ?? 1) !== 1) {
            throw new InvalidArgumentException('Fornecedor informado está inativo.');
        }

        $precoCusto = isset($item['preco_custo']) && $item['preco_custo'] !== ''
            ? (float)$item['preco_custo']
            : 0.0;

        $precoVenda = isset($item['preco_venda']) && $item['preco_venda'] !== ''
            ? (float)$item['preco_venda']
            : 0.0;

        if ($precoCusto < 0 || $precoVenda < 0) {
            throw new InvalidArgumentException('Os preços dos fornecedores devem ser maiores ou iguais a zero.');
        }

        $normalizados[] = [
            'fornecedor_id' => $fornecedorId,
            'nome'          => (string)$fornecedorDb['nome'],
            'codigo'        => trim((string)($item['codigo'] ?? '')),
            'preco_custo'   => $precoCusto,
            'preco_venda'   => $precoVenda,
            'observacao'    => trim((string)($item['observacao'] ?? '')),
            'principal'     => !empty($item['principal']) ? 1 : 0,
        ];

        $idsUsados[] = $fornecedorId;
    }

    if (empty($normalizados)) {
        return [];
    }

    $temPrincipal = false;
    foreach ($normalizados as $f) {
        if ((int)$f['principal'] === 1) {
            $temPrincipal = true;
            break;
        }
    }

    if (!$temPrincipal) {
        $normalizados[0]['principal'] = 1;
    } else {
        $achou = false;
        foreach ($normalizados as $i => $f) {
            if ((int)$f['principal'] === 1) {
                if (!$achou) {
                    $achou = true;
                    $normalizados[$i]['principal'] = 1;
                } else {
                    $normalizados[$i]['principal'] = 0;
                }
            }
        }
    }

    return $normalizados;
}

function tabela_existe(mysqli $conn, string $nomeTabela): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $nomeTabela);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $ok;
}

function home_obter_conteudo(mysqli $conn): array
{
    $frase = [
        'texto' => '',
        'autor' => '',
    ];

    $imagem = [
        'caminho' => '',
    ];

    if (tabela_existe($conn, 'frases_home')) {
        $sqlFrase = "
            SELECT frase, autor
            FROM frases_home
            WHERE ativo = 1
            ORDER BY RAND()
            LIMIT 1
        ";

        $resFrase = $conn->query($sqlFrase);
        if ($resFrase instanceof mysqli_result) {
            $fraseRow = $resFrase->fetch_assoc();
            $resFrase->free();

            if (is_array($fraseRow)) {
                $frase['texto'] = (string)($fraseRow['frase'] ?? '');
                $frase['autor'] = (string)($fraseRow['autor'] ?? '');
            }
        }
    }

    if (tabela_existe($conn, 'imagens_home')) {
        $sqlImagem = "
            SELECT caminho
            FROM imagens_home
            WHERE ativo = 1
            ORDER BY RAND()
            LIMIT 1
        ";

        $resImagem = $conn->query($sqlImagem);
        if ($resImagem instanceof mysqli_result) {
            $imagemRow = $resImagem->fetch_assoc();
            $resImagem->free();

            if (is_array($imagemRow)) {
                $imagem['caminho'] = (string)($imagemRow['caminho'] ?? '');
            }
        }
    }

    return [
        'frase'  => $frase,
        'imagem' => $imagem,
    ];
}

try {
    $conn = db();
    $body = read_body();
    $acao = get_action($body);

    apply_session_timeout($acao);

    logInfo('actions', 'Requisição recebida', [
        'acao'   => $acao,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'get'    => sanitize_for_log($_GET),
        'post'   => sanitize_for_log($_POST),
        'body'   => sanitize_for_log($body)
    ]);

    switch ($acao) {
        case 'login': {
            $loginOriginal = trim((string)($body['login'] ?? $body['email'] ?? $body['usuario'] ?? ''));
            $senha = (string)($body['senha'] ?? $body['password'] ?? '');

            if ($loginOriginal === '' || $senha === '') {
                json_response(false, 'Informe login e senha.', null, 400);
            }

            $login = normalizar_login($loginOriginal);

            $stmt = $conn->prepare("
                SELECT
                    id,
                    nome,
                    email,
                    senha,
                    LOWER(TRIM(nivel)) AS nivel,
                    COALESCE(ativo, 1) AS ativo
                FROM usuarios
                WHERE LOWER(email) = ? OR LOWER(nome) = ?
                LIMIT 1
            ");
            if (!$stmt) {
                json_response(false, 'Erro ao processar login.', null, 500);
            }

            $stmt->bind_param('ss', $login, $login);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$user) {
                json_response(false, 'Usuário ou senha inválidos.', null, 401);
            }

            if ((int)($user['ativo'] ?? 1) !== 1) {
                json_response(false, 'Usuário inativo. Procure um administrador.', null, 403);
            }

            $hash = (string)($user['senha'] ?? '');

            if ($hash === '' || !password_verify($senha, $hash)) {
                json_response(false, 'Usuário ou senha inválidos.', null, 401);
            }

            session_regenerate_id(true);

            $_SESSION['usuario'] = [
                'id'    => (int)$user['id'],
                'nome'  => (string)$user['nome'],
                'email' => (string)$user['email'],
                'nivel' => (string)$user['nivel'],
                'ativo' => (int)$user['ativo'],
            ];
            $_SESSION['LAST_ACTIVITY'] = time();

            json_response(true, 'OK', ['usuario' => $_SESSION['usuario']]);
        }

        case 'usuario_atual': {
            $u = require_auth();
            json_response(true, 'OK', ['usuario' => $u]);
        }

        case 'logout': {
            destroy_user_session();
            json_response(true, 'OK', null);
        }

        case 'obter_home': {
            require_auth();
            $dados = home_obter_conteudo($conn);
            json_response(true, 'OK', $dados);
        }

        case 'listar_usuarios': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $res = usuarios_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_usuario': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $usuarioId = (int)($_GET['usuario_id'] ?? $body['usuario_id'] ?? 0);
            if ($usuarioId <= 0) {
                json_response(false, 'Usuário inválido.', null, 400);
            }

            $res = usuario_obter($conn, $usuarioId);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'salvar_usuario': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $usuarioId = (int)($body['usuario_id'] ?? 0);
            $nome = trim((string)($body['nome'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $nivel = trim((string)($body['nivel'] ?? 'operador'));
            $ativo = (int)($body['ativo'] ?? 1);
            $senha = isset($body['senha']) ? (string)$body['senha'] : null;

            $res = usuario_salvar(
                $conn,
                $usuarioId,
                $nome,
                $email,
                $nivel,
                $senha,
                $ativo,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'alterar_status_usuario': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $usuarioId = (int)($body['usuario_id'] ?? 0);
            $ativo = (int)($body['ativo'] ?? -1);

            $res = usuario_alterar_status(
                $conn,
                $usuarioId,
                $ativo,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'excluir_usuario': {
            $usuario = require_auth();
            usuarios_require_admin($usuario);

            $usuarioId = (int)($body['usuario_id'] ?? 0);

            $res = usuario_excluir(
                $conn,
                $usuarioId,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_fornecedores': {
            require_auth();
            $res = fornecedores_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_fornecedor': {
            require_auth();

            $fornecedor_id = (int)($_GET['fornecedor_id'] ?? $body['fornecedor_id'] ?? 0);
            if ($fornecedor_id <= 0) {
                json_response(false, 'Fornecedor inválido.', null, 400);
            }

            $res = fornecedor_obter($conn, $fornecedor_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'salvar_fornecedor': {
            $usuario = require_auth();

            $fornecedor_id = (int)($body['fornecedor_id'] ?? 0);
            $nome = trim((string)($body['nome'] ?? ''));
            $cnpj = trim((string)($body['cnpj'] ?? ''));
            $telefone = trim((string)($body['telefone'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $ativo = (int)($body['ativo'] ?? 1);
            $observacao = trim((string)($body['observacao'] ?? ''));

            if ($nome === '') {
                json_response(false, 'Nome do fornecedor obrigatório.', null, 400);
            }

            if (!in_array($ativo, [0, 1], true)) {
                json_response(false, 'Status inválido.', null, 400);
            }

            $res = fornecedor_salvar(
                $conn,
                $fornecedor_id,
                $nome,
                $cnpj,
                $telefone,
                $email,
                $ativo,
                $observacao,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_produtos': {
            require_auth();
            $res = produtos_listar($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_produto': {
            require_auth();

            $produto_id = (int)($_GET['produto_id'] ?? $body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produto_obter($conn, $produto_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'adicionar_produto': {
            $usuario = require_auth();

            $nome = trim((string)($body['nome'] ?? ''));
            $ncm  = isset($body['ncm']) ? trim((string)$body['ncm']) : null;
            $qtd  = (int)($body['quantidade'] ?? 0);

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            $res = produtos_adicionar(
                $conn,
                $nome,
                $ncm,
                $qtd,
                0,
                (int)$usuario['id']
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'criar_produto': {
            $usuario = require_auth();

            $nome = trim((string)($body['nome'] ?? ''));
            $ncm = isset($body['ncm']) ? trim((string)$body['ncm']) : null;

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            $qtd = (int)($body['quantidade'] ?? 0);
            $estoque_minimo = (int)($body['estoque_minimo'] ?? 0);

            $preco_custo = (isset($body['preco_custo']) && $body['preco_custo'] !== '')
                ? (float)$body['preco_custo']
                : null;

            $preco_venda = (isset($body['preco_venda']) && $body['preco_venda'] !== '')
                ? (float)$body['preco_venda']
                : null;

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            if ($estoque_minimo < 0) {
                json_response(false, 'Estoque mínimo inválido.', null, 400);
            }

            if ($preco_custo !== null && $preco_custo < 0) {
                json_response(false, 'Preço de custo inválido.', null, 400);
            }

            if ($preco_venda !== null && $preco_venda < 0) {
                json_response(false, 'Preço de venda inválido.', null, 400);
            }

            try {
                $fornecedores = normalizar_fornecedores_payload($conn, $body['fornecedores'] ?? []);
            } catch (InvalidArgumentException $e) {
                json_response(false, $e->getMessage(), null, 400);
            }

            $res = produtos_adicionar(
                $conn,
                $nome,
                $ncm,
                $qtd,
                $estoque_minimo,
                (int)$usuario['id'],
                $preco_custo,
                $preco_venda,
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'atualizar_produto': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            $nome = trim((string)($body['nome'] ?? ''));
            $ncm = isset($body['ncm']) ? trim((string)$body['ncm']) : null;
            $qtd = (int)($body['quantidade'] ?? 0);
            $estoque_minimo = (int)($body['estoque_minimo'] ?? 0);

            $preco_custo = (isset($body['preco_custo']) && $body['preco_custo'] !== '')
                ? (float)$body['preco_custo']
                : 0.0;

            $preco_venda = (isset($body['preco_venda']) && $body['preco_venda'] !== '')
                ? (float)$body['preco_venda']
                : 0.0;

            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            if ($nome === '') {
                json_response(false, 'Nome do produto obrigatório.', null, 400);
            }

            if ($qtd < 0) {
                json_response(false, 'Quantidade inválida.', null, 400);
            }

            if ($estoque_minimo < 0) {
                json_response(false, 'Estoque mínimo inválido.', null, 400);
            }

            if ($preco_custo < 0 || $preco_venda < 0) {
                json_response(false, 'Preços inválidos.', null, 400);
            }

            try {
                $fornecedores = normalizar_fornecedores_payload($conn, $body['fornecedores'] ?? []);
            } catch (InvalidArgumentException $e) {
                json_response(false, $e->getMessage(), null, 400);
            }

            $res = produtos_atualizar(
                $conn,
                $produto_id,
                $nome,
                $ncm,
                $qtd,
                $estoque_minimo,
                $preco_custo,
                $preco_venda,
                (int)$usuario['id'],
                $fornecedores
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'remover_produto': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produtos_remover($conn, $produto_id, (int)$usuario['id']);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'buscar_produtos': {
            require_auth();

            $q = trim((string)($_GET['q'] ?? $body['q'] ?? ''));
            $limit = (int)($_GET['limit'] ?? $body['limit'] ?? 10);
            $limit = max(1, min(25, $limit));

            $res = produtos_buscar($conn, $q, $limit);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'produto_resumo': {
            require_auth();

            $produto_id = (int)($_GET['produto_id'] ?? $body['produto_id'] ?? 0);
            if ($produto_id <= 0) {
                json_response(false, 'Produto inválido.', null, 400);
            }

            $res = produto_resumo($conn, $produto_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'listar_movimentacoes': {
            require_auth();

            $filtros = array_merge($_GET, $body);
            $res = mov_listar($conn, $filtros);

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? []);
        }

        case 'obter_movimentacao': {
            require_auth();

            $movimentacao_id = (int)($_GET['movimentacao_id'] ?? $body['movimentacao_id'] ?? 0);
            if ($movimentacao_id <= 0) {
                json_response(false, 'Movimentação inválida.', null, 400);
            }

            $res = mov_obter($conn, $movimentacao_id);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'registrar_movimentacao': {
            $usuario = require_auth();

            $produto_id = (int)($body['produto_id'] ?? 0);
            $tipo       = trim((string)($body['tipo'] ?? ''));
            $quantidade = (int)($body['quantidade'] ?? 0);

            $fornecedor_id = isset($body['fornecedor_id']) && $body['fornecedor_id'] !== ''
                ? (int)$body['fornecedor_id']
                : null;

            $preco_custo = isset($body['preco_custo']) && $body['preco_custo'] !== ''
                ? (float)$body['preco_custo']
                : null;

            $valor_unitario = isset($body['valor_unitario']) && $body['valor_unitario'] !== ''
                ? (float)$body['valor_unitario']
                : null;

            $observacao = isset($body['observacao']) && trim((string)$body['observacao']) !== ''
                ? trim((string)$body['observacao'])
                : null;

            if ($produto_id <= 0 || $quantidade <= 0) {
                json_response(false, 'Dados inválidos.', null, 400);
            }

            if (!in_array($tipo, ['entrada', 'saida', 'remocao'], true)) {
                json_response(false, 'Tipo inválido.', null, 400);
            }

            if ($preco_custo !== null && $preco_custo < 0) {
                json_response(false, 'Preço de custo inválido.', null, 400);
            }

            if ($valor_unitario !== null && $valor_unitario < 0) {
                json_response(false, 'Valor unitário inválido.', null, 400);
            }

            if ($tipo === 'entrada') {
                if ($fornecedor_id === null || $fornecedor_id <= 0) {
                    json_response(false, 'Na entrada é obrigatório informar o fornecedor.', null, 400);
                }

                if ($preco_custo === null || $preco_custo <= 0) {
                    json_response(false, 'Na entrada é obrigatório informar um preço de custo válido.', null, 400);
                }
            }

            if ($tipo !== 'entrada') {
                $fornecedor_id = null;
            }

            $res = mov_registrar(
                $conn,
                $produto_id,
                $tipo,
                $quantidade,
                (int)$usuario['id'],
                $preco_custo,
                $valor_unitario,
                $observacao,
                $fornecedor_id
            );

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'estoque_atual':
        case 'relatorio_estoque':
        case 'relatorio_estoque_atual': {
            require_auth();

            $res = relatorio_estoque_atual($conn);
            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        case 'relatorio':
        case 'relatorios':
        case 'relatorio_movimentacoes': {
            require_auth();

            $filtros = array_merge($_GET, $body);
            $res = relatorio($conn, $filtros);

            json_response($res['sucesso'] ?? false, $res['mensagem'] ?? '', $res['dados'] ?? null);
        }

        default:
            json_response(false, 'Ação inválida.', null, 400);
    }
} catch (Throwable $e) {
    logError('actions', 'Erro fatal', [
        'arquivo' => $e->getFile(),
        'linha'   => $e->getLine(),
        'erro'    => $e->getMessage()
    ]);

    json_response(false, 'Erro interno no servidor.', null, 500);
}