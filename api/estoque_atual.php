<?php
/**
 * api/estoque_atual.php
 * Retorna estoque atual + valor estimado
 */

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Sao_Paulo');
initLog('estoque_atual');

header('Content-Type: application/json; charset=utf-8');

try {
    $conn = db(); // ✅ padrão do seu projeto

    // Se sua tabela produtos NÃO tiver coluna ativo, remova o WHERE ativo = 1.
    $sql = "
        SELECT
            id,
            nome,
            quantidade,
            preco_custo,
            (quantidade * COALESCE(preco_custo,0)) AS valor_estimado
        FROM produtos
        WHERE ativo = 1
        ORDER BY nome
    ";

    $res = $conn->query($sql);

    $itens = [];
    $total_qtd = 0;
    $total_valor = 0.0;

    while ($r = $res->fetch_assoc()) {
        $qtd = (int)($r['quantidade'] ?? 0);
        $pc  = ($r['preco_custo'] !== null) ? (float)$r['preco_custo'] : null;
        $val = (float)($r['valor_estimado'] ?? 0);

        $total_qtd += $qtd;
        $total_valor += $val;

        $itens[] = [
            'id'             => (int)$r['id'],
            'nome'           => (string)$r['nome'],
            'quantidade'     => $qtd,
            'preco_custo'    => $pc,
            'valor_estimado' => $val
        ];
    }

    $conn->close();

    echo json_encode(
        resposta(true, 'Estoque atual gerado com sucesso.', [
            'itens' => $itens,
            'totais' => [
                'total_qtd' => $total_qtd,
                'total_valor' => $total_valor
            ]
        ]),
        JSON_UNESCAPED_UNICODE
    );

} catch (Throwable $e) {
    logError('estoque_atual', 'Erro ao gerar estoque atual', [
        'arquivo' => $e->getFile(),
        'linha'   => $e->getLine(),
        'erro'    => $e->getMessage()
    ]);

    echo json_encode(resposta(false, 'Erro interno ao gerar estoque atual.'), JSON_UNESCAPED_UNICODE);
}