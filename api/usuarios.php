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

function usuarios_listar(mysqli $conn): array
{
    try {
        $sql = "
            SELECT
                id,
                nome,
                email,
                nivel,
                criado_em
            FROM usuarios
            ORDER BY nome ASC, id ASC
        ";

        $res = $conn->query($sql);
        $dados = [];

        while ($row = $res->fetch_assoc()) {
            $dados[] = [
                'id'        => (int)$row['id'],
                'nome'      => (string)$row['nome'],
                'email'     => (string)$row['email'],
                'nivel'     => (string)$row['nivel'],
                'criado_em' => (string)$row['criado_em'],
            ];
        }

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
                nivel,
                criado_em
            FROM usuarios
            WHERE id = ?
            LIMIT 1
        ");
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
    int $usuarioLogadoId
): array {
    $nome = trim($nome);
    $email = trim($email);
    $nivel = trim($nivel);
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

    if ($usuarioId <= 0 && ($senha === null || $senha === '')) {
        return resposta(false, 'A senha é obrigatória para novo usuário.');
    }

    try {
        $stmtDup = $conn->prepare("
            SELECT id
            FROM usuarios
            WHERE email = ?
              AND id <> ?
            LIMIT 1
        ");
        $stmtDup->bind_param('si', $email, $usuarioId);
        $stmtDup->execute();
        $duplicado = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();

        if ($duplicado) {
            return resposta(false, 'Já existe um usuário com este e-mail.');
        }

        if ($usuarioId > 0) {
            if ($senha !== null && $senha !== '') {
                $hash = password_hash($senha, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("
                    UPDATE usuarios
                    SET nome = ?, email = ?, nivel = ?, senha = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('ssssi', $nome, $email, $nivel, $hash, $usuarioId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    UPDATE usuarios
                    SET nome = ?, email = ?, nivel = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('sssi', $nome, $email, $nivel, $usuarioId);
                $stmt->execute();
                $stmt->close();
            }

            logInfo('usuarios', 'Usuário atualizado', [
                'usuario_id'        => $usuarioId,
                'usuario_logado_id' => $usuarioLogadoId,
                'email'             => $email,
                'nivel'             => $nivel
            ]);

            return resposta(true, 'Usuário atualizado com sucesso.', [
                'id' => $usuarioId
            ]);
        }

        $hash = password_hash((string)$senha, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO usuarios (nome, email, senha, nivel)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('ssss', $nome, $email, $hash, $nivel);
        $stmt->execute();
        $novoId = (int)$stmt->insert_id;
        $stmt->close();

        logInfo('usuarios', 'Usuário criado', [
            'usuario_id'        => $novoId,
            'usuario_logado_id' => $usuarioLogadoId,
            'email'             => $email,
            'nivel'             => $nivel
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