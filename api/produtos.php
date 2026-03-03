<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

function produtos_listar(mysqli $conn): array
{
    try {
        // ✅ Ajustado para a tabela real: id, nome, quantidade, ativo
        $sql = "
            SELECT id, nome, quantidade, ativo
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

        // ✅ logError com assinatura correta (contexto, mensagem, array)
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

function produtos_adicionar(
    mysqli $conn,
    string $nome,
    int $quantidade,
    ?int $usuario_id
): array {
    try {

        // ✅ Ajustado: sua tabela não tem criado_por, então não insere isso
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