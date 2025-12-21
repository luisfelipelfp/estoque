<?php
/**
 * api/produtos.php
 * Funções de domínio de produtos
 * Compatível PHP 8.2+ / 8.5
 */

declare(strict_types=1);

require_once __DIR__ . '/log.php';

initLog('produtos');

/**
 * Lista produtos
 */
function produtos_listar(mysqli $conn): array
{
    $sql = "
        SELECT
            id,
            nome,
            quantidade,
            criado_em
        FROM produtos
        ORDER BY nome
    ";

    $res = $conn->query($sql);

    if ($res === false) {
        logError('produtos', 'Erro ao listar produtos', [
            'erro' => $conn->error
        ]);

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao buscar produtos'
        ];
    }

    $dados = [];
    while ($row = $res->fetch_assoc()) {
        $dados[] = $row;
    }

    return [
        'sucesso' => true,
        'dados'   => $dados
    ];
}

/**
 * Adiciona produto
 */
function produtos_adicionar(
    mysqli $conn,
    string $nome,
    int $quantidade,
    ?int $usuario_id
): array {

    $stmt = $conn->prepare(
        'INSERT INTO produtos (nome, quantidade, criado_por) VALUES (?, ?, ?)'
    );

    if (!$stmt) {
        logError('produtos', 'Erro prepare INSERT', [
            'erro' => $conn->error
        ]);

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao preparar inserção'
        ];
    }

    $stmt->bind_param('sii', $nome, $quantidade, $usuario_id);
    $ok = $stmt->execute();

    if (!$ok) {
        logError('produtos', 'Erro execute INSERT', [
            'erro' => $stmt->error
        ]);

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao adicionar produto'
        ];
    }

    return [
        'sucesso'  => true,
        'mensagem' => 'Produto adicionado com sucesso',
        'dados'    => [
            'id' => $stmt->insert_id
        ]
    ];
}

/**
 * Remove produto
 */
function produtos_remover(
    mysqli $conn,
    int $produto_id,
    ?int $usuario_id
): array {

    $stmt = $conn->prepare(
        'DELETE FROM produtos WHERE id = ?'
    );

    if (!$stmt) {
        logError('produtos', 'Erro prepare DELETE', [
            'erro' => $conn->error
        ]);

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao preparar remoção'
        ];
    }

    $stmt->bind_param('i', $produto_id);
    $ok = $stmt->execute();

    if (!$ok) {
        logError('produtos', 'Erro execute DELETE', [
            'erro' => $stmt->error
        ]);

        return [
            'sucesso'  => false,
            'mensagem' => 'Erro ao remover produto'
        ];
    }

    return [
        'sucesso'  => true,
        'mensagem' => 'Produto removido com sucesso'
    ];
}
