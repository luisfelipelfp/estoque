<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

function produtos_listar(mysqli $conn): array
{
    try {
        $sql = "
            SELECT id, nome, quantidade, criado_em
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

        logError(
            'produtos',
            'Erro ao listar produtos',
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        );

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao buscar produtos',
            'dados'    => []
        ];
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
            'INSERT INTO produtos (nome, quantidade, criado_por) VALUES (?, ?, ?)'
        );

        // SEMPRE 3 parâmetros
        $stmt->bind_param(
            'sii',
            $nome,
            $quantidade,
            $usuario_id
        );

        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return [
            'sucesso'  => true,
            'mensagem' => 'Produto adicionado com sucesso',
            'dados'    => ['id' => $id]
        ];

    } catch (Throwable $e) {

        logError(
            'produtos',
            'Erro ao adicionar produto',
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        );

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

        $stmt = $conn->prepare(
            'DELETE FROM produtos WHERE id = ?'
        );

        $stmt->bind_param('i', $produto_id);
        $stmt->execute();
        $stmt->close();

        return [
            'sucesso'  => true,
            'mensagem' => 'Produto removido com sucesso',
            'dados'    => null
        ];

    } catch (Throwable $e) {

        logError(
            'produtos',
            'Erro ao remover produto',
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        );

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao remover produto',
            'dados'    => null
        ];
    }
}
