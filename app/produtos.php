<?php
declare(strict_types=1);

/**
 * Acha ou cria o estabelecimento. O CNPJ e a chave quando existe;
 * notas lancadas a mao podem nao ter, ai casa pelo nome.
 */
function estabelecimento_resolver(?string $cnpj, ?string $nome, ?string $municipio = null, ?string $uf = null): ?int
{
    $cnpj = so_digitos($cnpj);
    $cnpj = strlen($cnpj) === 14 ? $cnpj : null;
    $nome = decodificar_html($nome);
    $nome = $nome !== '' ? $nome : ($cnpj ? 'CNPJ ' . $cnpj : '');

    if ($nome === '' && $cnpj === null) {
        return null;
    }

    if ($cnpj !== null) {
        $id = qv('SELECT id FROM estabelecimentos WHERE cnpj = ?', [$cnpj]);
        if ($id) {
            // Completa dados que faltavam de um cadastro anterior
            exec_sql(
                'UPDATE estabelecimentos
                    SET nome      = COALESCE(NULLIF(nome, ""), ?),
                        municipio = COALESCE(municipio, ?),
                        uf        = COALESCE(uf, ?)
                  WHERE id = ?',
                [$nome, $municipio ?: null, $uf ?: null, $id]
            );
            return (int) $id;
        }
    } else {
        $id = qv(
            'SELECT id FROM estabelecimentos WHERE cnpj IS NULL AND nome = ? LIMIT 1',
            [$nome]
        );
        if ($id) {
            return (int) $id;
        }
    }

    try {
        return inserir('estabelecimentos', [
            'cnpj'      => $cnpj,
            'nome'      => mb_substr($nome, 0, 190),
            'municipio' => $municipio ? mb_substr($municipio, 0, 120) : null,
            'uf'        => $uf ? mb_substr(strtoupper($uf), 0, 2) : null,
        ]);
    } catch (PDOException $e) {
        // Corrida no indice unico de CNPJ: alguem inseriu antes
        if ($cnpj !== null) {
            $id = qv('SELECT id FROM estabelecimentos WHERE cnpj = ?', [$cnpj]);
            if ($id) {
                return (int) $id;
            }
        }
        throw $e;
    }
}

/**
 * Resolve (ou cria) o produto canonico de um item de nota.
 *
 * Ordem de identificacao:
 *   1. EAN, quando o emitente informou (chave global, funciona entre lojas)
 *   2. CNPJ da loja + codigo interno do produto (sempre existe, mas so vale la)
 *   3. Descricao normalizada (fallback)
 *
 * Sempre grava o alias loja+codigo, para que um EAN bipado no futuro
 * alcance compras que vieram SEM GTIN.
 *
 * @param array $item  chaves: descricao, ean, codigo, unidade
 */
function produto_resolver(array $item, ?int $estab_id): int
{
    $ean     = ean_normalizado($item['ean'] ?? null);
    $desc    = decodificar_html($item['descricao'] ?? '');
    $desc    = $desc !== '' ? mb_substr($desc, 0, 255) : 'SEM DESCRICAO';
    $norm    = normalizar_texto($desc);
    $unidade = mb_substr(trim((string) ($item['unidade'] ?? '')), 0, 10) ?: null;
    $cod     = mb_substr(trim((string) ($item['codigo'] ?? '')), 0, 60);

    $produto_id = null;

    if ($ean !== null) {
        $produto_id = qv('SELECT id FROM produtos WHERE ean = ?', [$ean]);

        if (!$produto_id && $norm !== '') {
            // Ja compramos esse produto antes, mas naquela vez veio SEM GTIN.
            // Agora que temos o EAN, adotamos o cadastro existente.
            $orfao = qv(
                'SELECT id FROM produtos WHERE ean IS NULL AND descricao_norm = ? LIMIT 1',
                [$norm]
            );
            if ($orfao) {
                exec_sql('UPDATE produtos SET ean = ? WHERE id = ?', [$ean, $orfao]);
                $produto_id = $orfao;
            }
        }
    }

    if (!$produto_id && $cod !== '' && $estab_id) {
        $produto_id = qv(
            'SELECT produto_id FROM produto_aliases WHERE estabelecimento_id = ? AND cod_interno = ?',
            [$estab_id, $cod]
        );
    }

    if (!$produto_id && $norm !== '') {
        $produto_id = qv(
            'SELECT id FROM produtos WHERE descricao_norm = ? ORDER BY (ean IS NULL) ASC, id ASC LIMIT 1',
            [$norm]
        );
    }

    if (!$produto_id) {
        try {
            $produto_id = inserir('produtos', [
                'ean'            => $ean,
                'descricao'      => $desc,
                'descricao_norm' => $norm,
                'unidade'        => $unidade,
            ]);
        } catch (PDOException $e) {
            $produto_id = $ean !== null ? qv('SELECT id FROM produtos WHERE ean = ?', [$ean]) : null;
            if (!$produto_id) {
                throw $e;
            }
        }
    }

    $produto_id = (int) $produto_id;

    if ($cod !== '' && $estab_id) {
        produto_alias_gravar($produto_id, $estab_id, $cod, $desc);
    }

    return $produto_id;
}

function produto_alias_gravar(int $produto_id, ?int $estab_id, string $cod, ?string $desc_original): void
{
    if ($cod === '' || !$estab_id) {
        return;
    }
    exec_sql(
        'INSERT INTO produto_aliases (produto_id, estabelecimento_id, cod_interno, descricao_original)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE produto_id = VALUES(produto_id)',
        [$produto_id, $estab_id, $cod, $desc_original ? mb_substr($desc_original, 0, 255) : null]
    );
}

/** Vincula um EAN a um produto que ainda nao tinha. Usado no cadastro manual. */
function produto_definir_ean(int $produto_id, string $ean): bool
{
    $ean = ean_normalizado($ean);
    if ($ean === null) {
        return false;
    }
    $dono = qv('SELECT id FROM produtos WHERE ean = ?', [$ean]);
    if ($dono && (int) $dono !== $produto_id) {
        return false; // ja pertence a outro produto
    }
    exec_sql('UPDATE produtos SET ean = ? WHERE id = ?', [$ean, $produto_id]);
    return true;
}

/** Busca um produto por EAN bipado, incluindo aliases. */
function produto_por_ean(string $ean_bruto): ?array
{
    $ean = ean_normalizado($ean_bruto);
    if ($ean === null) {
        return null;
    }
    return q1('SELECT * FROM produtos WHERE ean = ?', [$ean]);
}

/** Historico de precos pagos de um produto, do mais recente para o mais antigo. */
function produto_historico(int $produto_id, int $usuario_id): array
{
    return q(
        'SELECT i.quantidade, i.unidade, i.valor_unitario, i.valor_total, i.desconto,
                i.descricao_original,
                n.id AS nota_id, n.emissao, n.origem,
                est.nome AS loja, est.municipio, est.uf
           FROM itens i
           JOIN notas n ON n.id = i.nota_id
      LEFT JOIN estabelecimentos est ON est.id = n.estabelecimento_id
          WHERE i.produto_id = ? AND n.usuario_id = ? AND n.status = "ok"
       ORDER BY n.emissao DESC, n.id DESC
          LIMIT 200',
        [$produto_id, $usuario_id]
    );
}

/** Estatisticas do historico: menor, maior, ultimo e media do valor unitario. */
function produto_estatisticas(array $historico): array
{
    $precos = [];
    foreach ($historico as $h) {
        $p = (float) $h['valor_unitario'];
        if ($p > 0) {
            $precos[] = $p;
        }
    }
    if (!$precos) {
        return ['n' => 0];
    }
    return [
        'n'      => count($precos),
        'ultimo' => $precos[0],
        'min'    => min($precos),
        'max'    => max($precos),
        'media'  => array_sum($precos) / count($precos),
    ];
}
