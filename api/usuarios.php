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