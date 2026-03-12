<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

initLog('auditoria');

function auditoria_registrar(
    mysqli $conn,
    ?int $usuarioId,
    string $acao,
    string $entidade,
    ?int $entidadeId,
    mixed $dadosAnteriores,
    mixed $dadosNovos
): bool {

    try {

        if ($acao === '' || $entidade === '') {
            return false;
        }

        $dadosAntesJson = null;
        $dadosDepoisJson = null;

        if ($dadosAnteriores !== null) {
            $dadosAntesJson = json_encode(
                $dadosAnteriores,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        if ($dadosNovos !== null) {
            $dadosDepoisJson = json_encode(
                $dadosNovos,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $stmt = $conn->prepare("
            INSERT INTO auditoria
            (
                usuario_id,
                acao,
                entidade,
                entidade_id,
                dados_anteriores,
                dados_novos,
                data
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, NOW()
            )
        ");

        if (!$stmt) {

            logError('auditoria', 'Falha ao preparar INSERT de auditoria', [
                'acao' => $acao,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId
            ]);

            return false;
        }

        $stmt->bind_param(
            'ississ',
            $usuarioId,
            $acao,
            $entidade,
            $entidadeId,
            $dadosAntesJson,
            $dadosDepoisJson
        );

        $ok = $stmt->execute();

        if (!$ok) {

            logError('auditoria', 'Erro ao executar INSERT de auditoria', [
                'acao' => $acao,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'erro_mysql' => $stmt->error
            ]);

            $stmt->close();
            return false;
        }

        $stmt->close();

        logInfo('auditoria', 'Registro de auditoria criado', [
            'acao' => $acao,
            'entidade' => $entidade,
            'entidade_id' => $entidadeId,
            'usuario_id' => $usuarioId
        ]);

        return true;

    } catch (Throwable $e) {

        logError('auditoria', 'Erro ao registrar auditoria', [
            'acao' => $acao,
            'entidade' => $entidade,
            'entidade_id' => $entidadeId,
            'erro' => $e->getMessage(),
            'arquivo' => $e->getFile(),
            'linha' => $e->getLine()
        ]);

        return false;
    }
}   