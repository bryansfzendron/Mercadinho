<?php
declare(strict_types=1);

/** Conexao PDO unica, criada sob demanda. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        cfg('db_host', 'localhost'),
        (int) cfg('db_porta', 3306),
        cfg('db_nome')
    );

    $pdo = new PDO($dsn, cfg('db_user'), cfg('db_senha'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    return $pdo;
}

/** SELECT que devolve todas as linhas. */
function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** SELECT que devolve a primeira linha ou null. */
function q1(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $linha = $st->fetch();
    return $linha === false ? null : $linha;
}

/** SELECT que devolve o primeiro valor da primeira linha. */
function qv(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** INSERT/UPDATE/DELETE. Devolve o numero de linhas afetadas. */
function exec_sql(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** INSERT que devolve o id gerado. */
function inserir(string $tabela, array $dados): int
{
    $cols  = array_keys($dados);
    $ph    = array_map(static fn($c) => ':' . $c, $cols);
    $sql   = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $tabela,
        implode(', ', $cols),
        implode(', ', $ph)
    );
    $st = db()->prepare($sql);
    foreach ($dados as $c => $v) {
        $st->bindValue(':' . $c, $v, valor_pdo_tipo($v));
    }
    $st->execute();
    return (int) db()->lastInsertId();
}

function valor_pdo_tipo($v): int
{
    if ($v === null)  return PDO::PARAM_NULL;
    if (is_int($v))   return PDO::PARAM_INT;
    if (is_bool($v))  return PDO::PARAM_BOOL;
    return PDO::PARAM_STR;
}
