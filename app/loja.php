<?php
declare(strict_types=1);

/**
 * Espelho do TouchPay: preco de venda e estoque atual de cada ponto de venda.
 *
 * O n8n faz o trabalho (login, planograma, inventario paginado) e devolve um
 * POST por PDV em /api/loja/callback. Aqui a gente so troca as linhas daquele
 * PDV numa transacao e amarra cada item ao produto do Mercadinho pelo EAN.
 *
 * As credenciais do TouchPay ficam no config.php, que nao vai para o git —
 * o workflow do n8n as recebe no corpo, igual ao token do fluxo da NFC-e.
 */

/** Dispara a sincronizacao no n8n. Fire-and-forget, como o fluxo da nota. */
function loja_disparar_sync(array $pos_ids = []): array
{
    $webhook = (string) cfg('n8n_webhook_touchpay');
    $email   = (string) cfg('touchpay_email');
    $senha   = (string) cfg('touchpay_senha');

    if ($webhook === '') {
        return ['ok' => false, 'erro' => 'n8n_webhook_touchpay nao configurado'];
    }
    if ($email === '' || $senha === '') {
        return ['ok' => false, 'erro' => 'touchpay_email/touchpay_senha nao configurados'];
    }

    $payload = json_encode([
        'callback_url' => url('/api/loja/callback'),
        'token'        => (string) cfg('n8n_token'),
        'email'        => $email,
        'senha'        => $senha,
        'pos_ids'      => array_values(array_map('intval', $pos_ids)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    // Timeout nao e falha: o n8n ja recebeu e segue raspando sozinho.
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return ['ok' => true, 'erro' => null];
    }
    if ($errno !== 0) {
        return ['ok' => false, 'erro' => 'Falha ao chamar o n8n: ' . curl_strerror($errno)];
    }
    if ($http >= 400) {
        return ['ok' => false, 'erro' => 'n8n respondeu HTTP ' . $http . ' ' . mb_substr((string) $resp, 0, 200)];
    }
    return ['ok' => true, 'erro' => null];
}

/**
 * Normaliza o corpo do callback. Aceita o payload aninhado que o n8n manda e
 * tambem um corpo embrulhado em "body", pelo mesmo motivo do fluxo da NFC-e.
 *
 * @return array{token:string, status:string, erro:string, pos:array, itens:array}
 */
function loja_callback_normalizar(array $p): array
{
    if (isset($p['body']) && is_array($p['body'])) {
        $p = $p['body'];
    }

    $itens = [];
    foreach (['itens', 'items', 'dados'] as $chave) {
        if (!empty($p[$chave]) && is_array($p[$chave])) {
            $itens = array_values(array_filter($p[$chave], 'is_array'));
            break;
        }
    }

    return [
        'token'  => (string) ($p['token'] ?? ''),
        'status' => (string) ($p['status'] ?? 'ok'),
        'erro'   => (string) ($p['erro'] ?? ''),
        'pos'    => is_array($p['pos'] ?? null) ? $p['pos'] : [],
        'itens'  => $itens,
    ];
}

/** Acha (ou cria) o PDV espelhado. */
function loja_pdv_resolver(array $pos): ?int
{
    $externo = (int) ($pos['id'] ?? 0);
    if ($externo <= 0) {
        return null;
    }
    $nome = trim((string) ($pos['nome'] ?? '')) ?: ('PDV ' . $externo);

    $id = qv('SELECT id FROM loja_pdvs WHERE fonte = ? AND externo_id = ?', ['touchpay', $externo]);
    if ($id) {
        exec_sql(
            'UPDATE loja_pdvs SET nome = ?, tipo = ?, atualizado_em = NOW() WHERE id = ?',
            [mb_substr($nome, 0, 120), mb_substr((string) ($pos['tipo'] ?? ''), 0, 40) ?: null, $id]
        );
        return (int) $id;
    }

    return (int) inserir('loja_pdvs', [
        'fonte'         => 'touchpay',
        'externo_id'    => $externo,
        'nome'          => mb_substr($nome, 0, 120),
        'tipo'          => mb_substr((string) ($pos['tipo'] ?? ''), 0, 40) ?: null,
        'atualizado_em' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Grava o lote de um ponto de venda.
 *
 * As linhas do PDV sao trocadas por inteiro: o TouchPay manda o inventario
 * completo, entao apagar e regravar deixa o espelho igual a origem sem
 * precisar adivinhar o que saiu do planograma.
 *
 * @return array{ok:bool, itens:int, mensagem:string}
 */
function loja_processar_callback(array $p): array
{
    if (($p['status'] ?? 'ok') === 'erro') {
        return ['ok' => true, 'itens' => 0, 'mensagem' => 'erro do n8n: ' . mb_substr((string) $p['erro'], 0, 300)];
    }

    $pos = $p['pos'];
    if (!$pos || (int) ($pos['id'] ?? 0) <= 0) {
        return ['ok' => false, 'itens' => 0, 'mensagem' => 'payload sem o ponto de venda'];
    }
    if (!$p['itens']) {
        return ['ok' => false, 'itens' => 0, 'mensagem' => 'payload sem itens'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdv_id = loja_pdv_resolver($pos);
        if ($pdv_id === null) {
            throw new RuntimeException('nao consegui resolver o ponto de venda');
        }

        exec_sql('DELETE FROM loja_itens WHERE pdv_id = ?', [$pdv_id]);

        // Um SELECT so para amarrar tudo por EAN, em vez de uma consulta por
        // item: sao mais de mil linhas por PDV e o servidor corta em 30s.
        $eans = [];
        foreach ($p['itens'] as $it) {
            $ean = ean_normalizado($it['ean'] ?? null);
            if ($ean !== null) {
                $eans[$ean] = true;
            }
        }
        $porEan = [];
        if ($eans) {
            $lista = array_keys($eans);
            $marcas = implode(',', array_fill(0, count($lista), '?'));
            foreach (q('SELECT id, ean FROM produtos WHERE ean IN (' . $marcas . ')', $lista) as $linha) {
                $porEan[(string) $linha['ean']] = (int) $linha['id'];
            }
        }

        $agora = date('Y-m-d H:i:s');
        $vinculados = 0;

        // Sao mais de mil linhas por PDV e a hospedagem corta em 30s: inserir
        // uma a uma nao cabe. Vai em blocos, um INSERT com varias linhas.
        $colunas = [
            'pdv_id', 'produto_id', 'externo_produto_id', 'ean', 'codigo', 'descricao',
            'categoria', 'preco_venda', 'estoque', 'reservado', 'custo_medio',
            'minimo', 'capacidade', 'unidade', 'imagem', 'atualizado_em',
        ];
        $linhas = [];

        foreach ($p['itens'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $ean = ean_normalizado($it['ean'] ?? null);
            $produto_id = $ean !== null && isset($porEan[$ean]) ? $porEan[$ean] : null;
            if ($produto_id !== null) {
                $vinculados++;
            }

            $preco = $it['preco'] ?? null;

            $linhas[] = [
                $pdv_id,
                $produto_id,
                (int) ($it['produto_id_externo'] ?? 0) ?: null,
                $ean,
                mb_substr(trim((string) ($it['codigo'] ?? '')), 0, 60) ?: null,
                mb_substr(decodificar_html((string) ($it['descricao'] ?? '')), 0, 255),
                mb_substr(trim((string) ($it['categoria'] ?? '')), 0, 120) ?: null,
                // Sem planograma o produto fica sem preco de venda; guardar
                // NULL e mais honesto do que gravar zero.
                $preco === null || $preco === '' ? null : num_br($preco),
                num_br($it['estoque'] ?? 0),
                num_br($it['reservado'] ?? 0),
                num_br($it['custo_medio'] ?? 0),
                isset($it['minimo']) && $it['minimo'] !== null ? num_br($it['minimo']) : null,
                isset($it['capacidade']) && $it['capacidade'] !== null ? num_br($it['capacidade']) : null,
                mb_substr(trim((string) ($it['unidade'] ?? '')), 0, 10) ?: null,
                mb_substr(trim((string) ($it['imagem'] ?? '')), 0, 500) ?: null,
                $agora,
            ];
        }

        $gravados = loja_inserir_em_blocos($colunas, $linhas);

        exec_sql('UPDATE loja_pdvs SET atualizado_em = ? WHERE id = ?', [$agora, $pdv_id]);

        $pdo->commit();
        return [
            'ok'         => true,
            'itens'      => $gravados,
            'vinculados' => $vinculados,
            'mensagem'   => 'gravado',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'itens' => 0, 'mensagem' => $e->getMessage()];
    }
}

/**
 * INSERT de varias linhas por vez em loja_itens.
 *
 * O bloco de 200 e por causa do limite de placeholders do driver: 200 linhas
 * x 16 colunas = 3200 parametros, bem abaixo do teto e com poucas viagens ao
 * banco.
 *
 * @param string[] $colunas
 * @param array[]  $linhas  cada linha na mesma ordem de $colunas
 */
function loja_inserir_em_blocos(array $colunas, array $linhas, int $bloco = 200): int
{
    if (!$linhas) {
        return 0;
    }
    $campos = '`' . implode('`, `', $colunas) . '`';
    $marca  = '(' . implode(', ', array_fill(0, count($colunas), '?')) . ')';
    $total  = 0;

    foreach (array_chunk($linhas, $bloco) as $parte) {
        $sql = 'INSERT INTO loja_itens (' . $campos . ') VALUES '
             . implode(', ', array_fill(0, count($parte), $marca));
        $args = [];
        foreach ($parte as $linha) {
            foreach ($linha as $valor) {
                $args[] = $valor;
            }
        }
        exec_sql($sql, $args);
        $total += count($parte);
    }
    return $total;
}

/**
 * O que a loja cobra e quanto tem em estoque, por PDV.
 * Busca pelo EAN e, como reserva, pelo produto ja vinculado.
 */
function loja_por_ean(?string $ean_bruto, ?int $produto_id = null): array
{
    $ean = ean_normalizado($ean_bruto);
    if ($ean === null && $produto_id === null) {
        return [];
    }

    $onde = [];
    $args = [];
    if ($ean !== null) {
        $onde[] = 'li.ean = ?';
        $args[] = $ean;
    }
    if ($produto_id !== null) {
        $onde[] = 'li.produto_id = ?';
        $args[] = $produto_id;
    }

    return q(
        'SELECT li.preco_venda, li.estoque, li.reservado, li.custo_medio, li.descricao,
                li.unidade, li.atualizado_em,
                p.nome AS pdv, p.externo_id AS pdv_externo
           FROM loja_itens li
           JOIN loja_pdvs p ON p.id = li.pdv_id
          WHERE ' . implode(' OR ', $onde) . '
       ORDER BY p.nome',
        $args
    );
}

/**
 * Preco e estoque de varios produtos de uma vez, somando os pontos de venda.
 *
 * Uma consulta so para a lista inteira: uma por produto derrubaria a pagina
 * de produtos, que mostra ate 300 linhas.
 *
 * @param int[] $produto_ids
 * @return array<int, array{estoque:float, preco:?float, pdvs:int}>
 */
function loja_por_produtos(array $produto_ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $produto_ids))));
    if (!$ids) {
        return [];
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));

    $linhas = q(
        'SELECT produto_id,
                SUM(estoque)     AS estoque,
                MAX(preco_venda) AS preco,
                COUNT(*)         AS pdvs
           FROM loja_itens
          WHERE produto_id IN (' . $marcas . ')
       GROUP BY produto_id',
        $ids
    );

    $mapa = [];
    foreach ($linhas as $l) {
        $mapa[(int) $l['produto_id']] = [
            'estoque' => (float) $l['estoque'],
            'preco'   => $l['preco'] === null ? null : (float) $l['preco'],
            'pdvs'    => (int) $l['pdvs'],
        ];
    }
    return $mapa;
}

/**
 * Catalogo da loja: tudo que o TouchPay mandou, com filtro e ordem.
 *
 * @param string $ordem  nome | preco | preco_desc | estoque | estoque_desc | categoria
 */
function loja_listar(
    string $busca = '',
    int $pdv_id = 0,
    string $ordem = 'nome',
    bool $so_com_estoque = false,
    int $limite = 400
): array {
    $onde = ['1 = 1'];
    $args = [];

    if ($busca !== '') {
        $ean = ean_normalizado($busca);
        $onde[] = '(li.descricao LIKE ? OR li.ean = ? OR li.codigo LIKE ? OR li.categoria LIKE ?)';
        $args[] = '%' . $busca . '%';
        $args[] = $ean ?? '__nada__';
        $args[] = '%' . $busca . '%';
        $args[] = '%' . $busca . '%';
    }
    if ($pdv_id > 0) {
        $onde[] = 'li.pdv_id = ?';
        $args[] = $pdv_id;
    }
    if ($so_com_estoque) {
        $onde[] = 'li.estoque > 0';
    }

    // Lista fixa: nada aqui pode vir do usuario direto para dentro do SQL.
    $ordens = [
        'nome'         => 'li.descricao ASC',
        'preco'        => 'li.preco_venda IS NULL, li.preco_venda ASC',
        'preco_desc'   => 'li.preco_venda IS NULL, li.preco_venda DESC',
        'estoque'      => 'li.estoque ASC, li.descricao ASC',
        'estoque_desc' => 'li.estoque DESC, li.descricao ASC',
        'categoria'    => 'li.categoria ASC, li.descricao ASC',
    ];
    $por = $ordens[$ordem] ?? $ordens['nome'];

    return q(
        'SELECT li.id, li.produto_id, li.ean, li.codigo, li.descricao, li.categoria,
                li.preco_venda, li.estoque, li.reservado, li.custo_medio, li.unidade,
                li.imagem, li.atualizado_em,
                p.id AS pdv_id, p.nome AS pdv
           FROM loja_itens li
           JOIN loja_pdvs p ON p.id = li.pdv_id
          WHERE ' . implode(' AND ', $onde) . '
       ORDER BY ' . $por . '
          LIMIT ' . (int) $limite,
        $args
    );
}

/** Totais do catalogo da loja, com os mesmos filtros da listagem. */
function loja_totais(string $busca = '', int $pdv_id = 0, bool $so_com_estoque = false): array
{
    $todos = loja_listar($busca, $pdv_id, 'nome', $so_com_estoque, 100000);
    $valor = 0.0;
    $comEstoque = 0;
    foreach ($todos as $l) {
        if ((float) $l['estoque'] > 0) {
            $comEstoque++;
            $valor += (float) $l['estoque'] * (float) ($l['preco_venda'] ?? 0);
        }
    }
    return [
        'itens'        => count($todos),
        'com_estoque'  => $comEstoque,
        'valor_venda'  => $valor,
    ];
}

/** Resumo para a tela inicial: quantos PDVs, itens e quando foi a ultima carga. */
function loja_resumo(): array
{
    $pdvs = q(
        'SELECT p.id, p.nome, p.atualizado_em, COUNT(li.id) AS itens,
                SUM(CASE WHEN li.produto_id IS NOT NULL THEN 1 ELSE 0 END) AS vinculados
           FROM loja_pdvs p
      LEFT JOIN loja_itens li ON li.pdv_id = p.id
       GROUP BY p.id, p.nome, p.atualizado_em
       ORDER BY p.nome'
    );
    return $pdvs;
}
