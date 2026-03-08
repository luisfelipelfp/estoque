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