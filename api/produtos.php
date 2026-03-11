<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auditoria.php';

initLog('produtos');

function coluna_existe(mysqli $conn, string $tabela, string $coluna): bool
{
    static $cache = [];

    $dbRow = $conn->query("SELECT DATABASE() AS db");
    $db = '';
    if ($dbRow instanceof mysqli_result) {
        $assoc = $dbRow->fetch_assoc();
        $db = (string)($assoc['db'] ?? '');
        $dbRow->free();
    }

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
    if (!$stmt) {
        return false;
    }

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
function produtos_listar(mysqli $conn): array
{
    try {

        $hasCusto = coluna_existe($conn,'produtos','preco_custo');
        $hasVenda = coluna_existe($conn,'produtos','preco_venda');
        $hasEstoqueMinimo = coluna_existe($conn,'produtos','estoque_minimo');
        $hasNcm = coluna_existe($conn,'produtos','ncm');
        $hasAtivo = coluna_existe($conn,'produtos','ativo');

        $sql = "
            SELECT
                id,
                nome,
                ".($hasNcm ? "COALESCE(ncm,'') AS ncm," : "'' AS ncm,")."
                quantidade,
                ".($hasAtivo ? "COALESCE(ativo,1) AS ativo" : "1 AS ativo")."
                ".($hasEstoqueMinimo ? ",COALESCE(estoque_minimo,0) AS estoque_minimo" : ",0 AS estoque_minimo")."
                ".($hasCusto ? ",COALESCE(preco_custo,0) AS preco_custo" : ",0 AS preco_custo")."
                ".($hasVenda ? ",COALESCE(preco_venda,0) AS preco_venda" : ",0 AS preco_venda")."
            FROM produtos
            ORDER BY nome ASC,id ASC
        ";

        $res = $conn->query($sql);

        if (!$res) {
            throw new RuntimeException('Erro na consulta de produtos.');
        }

        $dados = [];

        while ($row = $res->fetch_assoc()) {

            $dados[] = [
                'id' => (int)$row['id'],
                'nome' => (string)$row['nome'],
                'ncm' => (string)($row['ncm'] ?? ''),
                'quantidade' => (int)$row['quantidade'],
                'ativo' => (int)$row['ativo'],
                'estoque_minimo' => (int)$row['estoque_minimo'],
                'preco_custo' => (float)$row['preco_custo'],
                'preco_venda' => (float)$row['preco_venda'],
            ];

        }

        $res->free();

        return resposta(true,'',$dados);

    } catch (Throwable $e) {

        logError('produtos','Erro ao listar produtos',[
            'arquivo'=>$e->getFile(),
            'linha'=>$e->getLine(),
            'erro'=>$e->getMessage()
        ]);

        return resposta(false,'Erro ao buscar produtos',[]);
    }
}
