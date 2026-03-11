<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

initLog('auditoria');

function auditoria_tabela_existe(mysqli $conn): bool
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'auditoria'
        LIMIT 1
    ");

    if (!$stmt) {
        $cache = false;
        return false;
    }

    $stmt->execute();
    $cache = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache;
}

function auditoria_json(mixed $dados): ?string
{
    if ($dados === null) {
        return null;
    }

    $json = json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $json === false ? null : $json;
}

function auditoria_registrar(
    mysqli $conn,
    ?int $usuarioId,
    string $acao,
    string $entidade,
    ?int $entidadeId = null,
    mixed $dadosAnteriores = null,
    mixed $dadosNovos = null
): bool {

    if (!auditoria_tabela_existe($conn)) {
        logWarning('auditoria', 'Tabela auditoria não encontrada', [
            'acao' => $acao,
            'entidade' => $entidade
        ]);
        return false;
    }

    try {

        $acao = trim($acao);
        $entidade = trim($entidade);

        if ($acao === '' || $entidade === '') {
            throw new InvalidArgumentException('Ação e entidade são obrigatórias.');
        }

        $jsonAntes  = auditoria_json($dadosAnteriores);
        $jsonDepois = auditoria_json($dadosNovos);

        $stmt = $conn->prepare("
            INSERT INTO auditoria
            (usuario_id, acao, entidade, entidade_id, dados_anteriores, dados_novos, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar auditoria.');
        }

        $stmt->bind_param(
            'ississ',
            $usuarioId,
            $acao,
            $entidade,
            $entidadeId,
            $jsonAntes,
            $jsonDepois
        );

        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            throw new RuntimeException('Erro ao executar auditoria.');
        }

        return true;

    } catch (Throwable $e) {

        logError('auditoria', 'Falha ao registrar auditoria', [
            'erro' => $e->getMessage(),
            'acao' => $acao ?? null,
            'entidade' => $entidade ?? null,
            'entidade_id' => $entidadeId ?? null
        ]);

        return false;
    }
}