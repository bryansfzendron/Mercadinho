<?php
declare(strict_types=1);

/**
 * Vendas do TouchPay.
 *
 * Mesmo desenho do espelho da loja: o n8n loga, pagina o GET /api/Transactions
 * e devolve lotes em /api/vendas/callback. Aqui a gente so grava.
 *
 * Duas coisas da API que ditam o formato (medidas contra a conta real):
 *  - cada transacao ja vem com os itens dentro, entao um lote traz venda e
 *    item de uma vez;
 *  - `price`/`paymentAmount` do item sao o TOTAL DA LINHA, nao o unitario.
 *    Um item com quantidade 4 veio com price 15,56 (unitario 3,89). Somar as
 *    linhas bate com o total da transacao; multiplicar por quantidade nao.
 *    O n8n ja manda separado (valor_total e quantidade) e o unitario e conta
 *    nossa, aqui.
 */

/** Janela de datas do proximo sync, olhando o que ja esta gravado. */
function vendas_janela(int $dias_primeira_carga = 365, int $sobreposicao = 3): array
{
    $ultima = qv('SELECT MAX(data_hora) FROM vendas');
    $ate    = date('Y-m-d');

    if (!$ultima) {
        return [date('Y-m-d', strtotime('-' . $dias_primeira_carga . ' days')), $ate];
    }
    // Volta alguns dias: transacao recem-feita ainda pode ser reconciliada
    // pelo TouchPay, e reimportar a mesma venda nao duplica nada.
    return [date('Y-m-d', strtotime($ultima . ' -' . $sobreposicao . ' days')), $ate];
}

/** Dispara a coleta no n8n. Fire-and-forget, igual aos outros fluxos. */
function vendas_disparar_sync(?string $desde = null, ?string $ate = null): array
{
    $webhook = (string) cfg('n8n_webhook_vendas');
    $email   = (string) cfg('touchpay_email');
    $senha   = (string) cfg('touchpay_senha');

    if ($webhook === '') {
        return ['ok' => false, 'erro' => 'n8n_webhook_vendas nao configurado'];
    }
    if ($email === '' || $senha === '') {
        return ['ok' => false, 'erro' => 'touchpay_email/touchpay_senha nao configurados'];
    }

    if ($desde === null || $ate === null) {
        [$desde, $ate] = vendas_janela();
    }

    $payload = json_encode([
        'callback_url' => url('/api/vendas/callback'),
        'token'        => (string) cfg('n8n_token'),
        'email'        => $email,
        'senha'        => $senha,
        'min_date'     => $desde,
        'max_date'     => $ate,
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

    // Timeout nao e falha: o n8n ja recebeu e segue coletando sozinho.
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return ['ok' => true, 'erro' => null, 'desde' => $desde, 'ate' => $ate];
    }
    if ($errno !== 0) {
        return ['ok' => false, 'erro' => 'Falha ao chamar o n8n: ' . curl_strerror($errno)];
    }
    if ($http >= 400) {
        return ['ok' => false, 'erro' => 'n8n respondeu HTTP ' . $http . ' ' . mb_substr((string) $resp, 0, 200)];
    }
    return ['ok' => true, 'erro' => null, 'desde' => $desde, 'ate' => $ate];
}

/**
 * Normaliza o corpo do callback. Aceita o corpo embrulhado em "body" pelo
 * mesmo motivo dos outros fluxos.
 *
 * @return array{token:string, status:string, erro:string, lote:int, lotes:int, vendas:array}
 */
function vendas_callback_normalizar(array $p): array
{
    if (isset($p['body']) && is_array($p['body'])) {
        $p = $p['body'];
    }

    $vendas = [];
    foreach (['vendas', 'transacoes', 'itens', 'items'] as $chave) {
        if (!empty($p[$chave]) && is_array($p[$chave])) {
            $vendas = array_values(array_filter($p[$chave], 'is_array'));
            break;
        }
    }

    return [
        'token'  => (string) ($p['token'] ?? ''),
        'status' => (string) ($p['status'] ?? 'ok'),
        'erro'   => (string) ($p['erro'] ?? ''),
        'lote'   => (int) ($p['lote'] ?? 1),
        'lotes'  => (int) ($p['lotes'] ?? 1),
        'vendas' => $vendas,
    ];
}

/**
 * Data do TouchPay ("2026-09-06T11:43:16-03:00") no horario de Sao Paulo.
 * Devolve null quando nao da para entender — venda sem data nao entra.
 */
function venda_data_hora($v): ?string
{
    $texto = trim((string) $v);
    if ($texto === '') {
        return null;
    }
    try {
        $d = new DateTimeImmutable($texto);
    } catch (Throwable $e) {
        return null;
    }
    return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

/**
 * Uma venda do payload virando as linhas que vao para o banco.
 * Funcao pura, sem banco: e o que os testes cobrem.
 *
 * @return array{venda:array, itens:array}|null
 */
function venda_normalizar(array $v): ?array
{
    $externo = (int) ($v['id'] ?? 0);
    $quando  = venda_data_hora($v['data'] ?? null);
    if ($externo <= 0 || $quando === null) {
        return null;
    }

    $itens = [];
    foreach (is_array($v['itens'] ?? null) ? $v['itens'] : [] as $it) {
        if (!is_array($it)) {
            continue;
        }
        // Quantidade sempre float, e nunca zero: alem de nao existir venda
        // de zero unidade, ela e divisor do unitario logo abaixo.
        $qtd = num_br($it['quantidade'] ?? 1);
        if ($qtd <= 0) {
            $qtd = 1.0;
        }
        $total = num_br($it['valor_total'] ?? 0);

        $itens[] = [
            'externo_produto_id' => (int) ($it['produto_id_externo'] ?? 0) ?: null,
            'ean'                => ean_normalizado($it['ean'] ?? null),
            'codigo'             => mb_substr(trim((string) ($it['codigo'] ?? '')), 0, 60) ?: null,
            'descricao'          => mb_substr(decodificar_html((string) ($it['descricao'] ?? '')), 0, 255),
            'categoria'          => mb_substr(trim((string) ($it['categoria'] ?? '')), 0, 120) ?: null,
            'quantidade'         => $qtd,
            'valor_total'        => $total,
            // O unitario nao vem da API: e o total da linha dividido pela
            // quantidade. Guardar pronto poupa a divisao em todo relatorio.
            'valor_unitario'     => round($total / $qtd, 4),
        ];
    }

    return [
        'venda' => [
            'externo_id'      => $externo,
            'uuid'            => mb_substr(trim((string) ($v['uuid'] ?? '')), 0, 60) ?: null,
            'pdv_externo_id'  => (int) ($v['pdv_id'] ?? 0) ?: null,
            'pdv_nome'        => trim((string) ($v['pdv_nome'] ?? '')),
            'data_hora'       => $quando,
            'resultado'       => mb_substr(trim((string) ($v['resultado'] ?? '')), 0, 30) ?: null,
            'forma_pagamento' => mb_substr(trim((string) ($v['forma_pagamento'] ?? '')), 0, 30) ?: null,
            'bandeira'        => mb_substr(trim((string) ($v['bandeira'] ?? '')), 0, 30) ?: null,
            'valor_total'     => num_br($v['valor_total'] ?? 0),
            'valor_pago'      => num_br($v['valor_pago'] ?? 0),
            'codigo'          => mb_substr(trim((string) ($v['codigo'] ?? '')), 0, 60) ?: null,
        ],
        'itens' => $itens,
    ];
}

/**
 * Normaliza o lote inteiro, jogando fora o que nao da para gravar.
 *
 * A mesma venda pode chegar duas vezes se o TouchPay ganhar transacoes novas
 * no meio da paginacao. Guardar por externo_id resolve antes de o banco
 * reclamar da chave unica e derrubar o lote todo.
 *
 * @return array[] uma entrada {venda, itens} por transacao
 */
function vendas_normalizar_lote(array $vendas): array
{
    $porExterno = [];
    foreach ($vendas as $bruta) {
        if (!is_array($bruta)) {
            continue;
        }
        $v = venda_normalizar($bruta);
        if ($v !== null) {
            $porExterno[$v['venda']['externo_id']] = $v;
        }
    }
    return array_values($porExterno);
}

/**
 * Grava um lote de vendas.
 *
 * Idempotente por (fonte, externo_id): as vendas do lote sao apagadas antes
 * de entrar de novo, entao reimportar a mesma janela corrige em vez de
 * duplicar. Os itens vao junto pelo ON DELETE CASCADE.
 *
 * @return array{ok:bool, vendas:int, itens:int, vinculados:int, mensagem:string}
 */
function vendas_processar_callback(array $p): array
{
    if (($p['status'] ?? 'ok') === 'erro') {
        return [
            'ok' => true, 'vendas' => 0, 'itens' => 0, 'vinculados' => 0,
            'mensagem' => 'erro do n8n: ' . mb_substr((string) $p['erro'], 0, 300),
        ];
    }

    // Janela sem venda nenhuma e resposta valida, nao falha: acontece toda
    // vez que o sync roda duas vezes seguidas.
    if (!$p['vendas']) {
        return ['ok' => true, 'vendas' => 0, 'itens' => 0, 'vinculados' => 0, 'mensagem' => 'nada novo'];
    }

    $normalizadas = vendas_normalizar_lote($p['vendas']);
    if (!$normalizadas) {
        return ['ok' => false, 'vendas' => 0, 'itens' => 0, 'vinculados' => 0, 'mensagem' => 'lote sem venda utilizavel'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // ---- PDVs do lote, resolvidos uma vez so ----
        $pdvs = [];
        foreach ($normalizadas as $n) {
            $ext = $n['venda']['pdv_externo_id'];
            if ($ext !== null && !isset($pdvs[$ext])) {
                // Mesmo cadastro do espelho da loja: se o PDV ainda nao foi
                // sincronizado, o resolver cria com o nome que veio na venda.
                // false: quem sincroniza preco e estoque e o outro fluxo; a
                // venda so nao pode ficar sem PDV.
                $pdvs[$ext] = loja_pdv_resolver(['id' => $ext, 'nome' => $n['venda']['pdv_nome']], false);
            }
        }

        // ---- produtos do lote, num SELECT so ----
        $eans = [];
        foreach ($normalizadas as $n) {
            foreach ($n['itens'] as $it) {
                if ($it['ean'] !== null) {
                    $eans[$it['ean']] = true;
                }
            }
        }
        $porEan = [];
        if ($eans) {
            $lista  = array_keys($eans);
            $marcas = implode(',', array_fill(0, count($lista), '?'));
            foreach (q('SELECT id, ean FROM produtos WHERE ean IN (' . $marcas . ')', $lista) as $linha) {
                $porEan[(string) $linha['ean']] = (int) $linha['id'];
            }
        }

        // ---- fora as versoes antigas destas mesmas vendas ----
        $externos = array_map(static fn (array $n): int => $n['venda']['externo_id'], $normalizadas);
        foreach (array_chunk($externos, 500) as $parte) {
            $marcas = implode(',', array_fill(0, count($parte), '?'));
            exec_sql(
                'DELETE FROM vendas WHERE fonte = ? AND externo_id IN (' . $marcas . ')',
                array_merge(['touchpay'], $parte)
            );
        }

        $agora = date('Y-m-d H:i:s');

        // ---- as vendas, em bloco ----
        // Uma venda por INSERT dava mil idas ao banco por lote e nao caberia
        // nos 30s da hospedagem; assim sao poucas viagens.
        $linhas_venda = [];
        foreach ($normalizadas as $n) {
            $v = $n['venda'];
            $linhas_venda[] = [
                'touchpay',
                $v['externo_id'],
                $v['uuid'],
                $v['pdv_externo_id'] !== null ? ($pdvs[$v['pdv_externo_id']] ?? null) : null,
                $v['data_hora'],
                $v['resultado'],
                $v['forma_pagamento'],
                $v['bandeira'],
                $v['valor_total'],
                $v['valor_pago'],
                $v['codigo'],
                $agora,
            ];
        }
        inserir_em_blocos('vendas', [
            'fonte', 'externo_id', 'uuid', 'pdv_id', 'data_hora', 'resultado',
            'forma_pagamento', 'bandeira', 'valor_total', 'valor_pago', 'codigo', 'atualizado_em',
        ], $linhas_venda);

        // ---- de volta os ids gerados, para amarrar os itens ----
        $ids = [];
        foreach (array_chunk($externos, 500) as $parte) {
            $marcas = implode(',', array_fill(0, count($parte), '?'));
            foreach (q(
                'SELECT id, externo_id FROM vendas WHERE fonte = ? AND externo_id IN (' . $marcas . ')',
                array_merge(['touchpay'], $parte)
            ) as $linha) {
                $ids[(int) $linha['externo_id']] = (int) $linha['id'];
            }
        }

        // ---- os itens, tambem em bloco ----
        $vinculados = 0;
        $linhas_item = [];
        foreach ($normalizadas as $n) {
            $venda_id = $ids[$n['venda']['externo_id']] ?? null;
            if ($venda_id === null) {
                continue;
            }
            foreach ($n['itens'] as $it) {
                $produto_id = $it['ean'] !== null && isset($porEan[$it['ean']]) ? $porEan[$it['ean']] : null;
                if ($produto_id !== null) {
                    $vinculados++;
                }
                $linhas_item[] = [
                    $venda_id,
                    $produto_id,
                    $it['externo_produto_id'],
                    $it['ean'],
                    $it['codigo'],
                    $it['descricao'],
                    $it['categoria'],
                    $it['quantidade'],
                    $it['valor_total'],
                    $it['valor_unitario'],
                ];
            }
        }
        $itens_gravados = inserir_em_blocos('venda_itens', [
            'venda_id', 'produto_id', 'externo_produto_id', 'ean', 'codigo',
            'descricao', 'categoria', 'quantidade', 'valor_total', 'valor_unitario',
        ], $linhas_item);

        $pdo->commit();
        return [
            'ok'         => true,
            'vendas'     => count($normalizadas),
            'itens'      => $itens_gravados,
            'vinculados' => $vinculados,
            'mensagem'   => 'gravado',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'vendas' => 0, 'itens' => 0, 'vinculados' => 0, 'mensagem' => $e->getMessage()];
    }
}

/** Quanto ja entrou, para a tela de inicio mostrar sem abrir relatorio. */
function vendas_resumo(): array
{
    $r = q1(
        'SELECT COUNT(*) AS vendas, MIN(data_hora) AS primeira, MAX(data_hora) AS ultima,
                COALESCE(SUM(valor_pago), 0) AS total
           FROM vendas WHERE resultado = ? OR resultado IS NULL',
        ['Ok']
    );
    return $r ?: ['vendas' => 0, 'primeira' => null, 'ultima' => null, 'total' => 0];
}

// ---------------------------------------------------------------------
// Relatorio
// ---------------------------------------------------------------------

/** Os agrupamentos que a tela oferece: rotulo e a coluna que os define. */
function vendas_agrupamentos(): array
{
    return [
        'produto'   => ['Produto',            'COALESCE(vi.ean, vi.codigo, vi.descricao)'],
        'categoria' => ['Categoria',          "COALESCE(vi.categoria, 'sem categoria')"],
        'dia'       => ['Dia',                'DATE(v.data_hora)'],
        'mes'       => ['Mês',                "DATE_FORMAT(v.data_hora, '%Y-%m')"],
        'pdv'       => ['Ponto de venda',     "COALESCE(p.nome, 'sem PDV')"],
        'forma'     => ['Forma de pagamento', "COALESCE(v.forma_pagamento, 'desconhecida')"],
        'hora'      => ['Hora do dia',        'HOUR(v.data_hora)'],
        'semana'    => ['Dia da semana',      'DAYOFWEEK(v.data_hora)'],
    ];
}

/**
 * Monta o WHERE das vendas a partir dos filtros da tela.
 *
 * @return array{0:string, 1:array} trecho SQL e os parametros
 */
function vendas_filtro_sql(array $f): array
{
    // So venda que valeu. O resultado fica gravado para poder filtrar, mas o
    // relatorio de faturamento nao pode somar transacao negada.
    $onde = ['(v.resultado = ? OR v.resultado IS NULL)'];
    $args = ['Ok'];

    if (!empty($f['de'])) {
        $onde[] = 'v.data_hora >= ?';
        $args[] = $f['de'] . ' 00:00:00';
    }
    if (!empty($f['ate'])) {
        $onde[] = 'v.data_hora <= ?';
        $args[] = $f['ate'] . ' 23:59:59';
    }
    if (!empty($f['pdv_id'])) {
        $onde[] = 'v.pdv_id = ?';
        $args[] = (int) $f['pdv_id'];
    }
    if (!empty($f['forma'])) {
        $onde[] = 'v.forma_pagamento = ?';
        $args[] = (string) $f['forma'];
    }
    return [implode(' AND ', $onde), $args];
}

/** Faturamento por forma de pagamento: a base da taxa da maquininha. */
function vendas_por_forma(array $f): array
{
    [$onde, $args] = vendas_filtro_sql($f);
    return q(
        'SELECT COALESCE(v.forma_pagamento, ?) AS forma,
                COUNT(*) AS n, COALESCE(SUM(v.valor_pago), 0) AS total
           FROM vendas v WHERE ' . $onde . '
       GROUP BY v.forma_pagamento ORDER BY total DESC',
        array_merge(['desconhecida'], $args)
    );
}

/**
 * Ultimo valor unitario liquido pago em cada produto, da NFC-e.
 * E o custo real que o TouchPay nao tem — la o costOfSale vem sempre zero.
 *
 * @param int[] $ids
 * @return array<int,float> produto_id => custo unitario
 */
function vendas_custo_por_produto(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $custos = [];
    foreach (array_chunk($ids, 500) as $parte) {
        $marcas = implode(',', array_fill(0, count($parte), '?'));
        // A nota mais recente de cada produto manda. Duas notas no mesmo
        // instante empatam; a primeira que vier resolve, e a diferenca entre
        // elas nao muda o relatorio.
        $linhas = q(
            'SELECT i.produto_id, i.valor_unitario_liquido
               FROM itens i
               JOIN notas n ON n.id = i.nota_id
              WHERE n.status = ? AND i.produto_id IN (' . $marcas . ')
                AND i.valor_unitario_liquido > 0
           ORDER BY n.emissao DESC, n.id DESC',
            array_merge(['ok'], $parte)
        );
        foreach ($linhas as $l) {
            $pid = (int) $l['produto_id'];
            if (!isset($custos[$pid])) {
                $custos[$pid] = (float) $l['valor_unitario_liquido'];
            }
        }
    }
    return $custos;
}

/** Dias do periodo filtrado, para ratear os custos fixos do mes. */
function vendas_dias_do_periodo(array $f): int
{
    $de  = !empty($f['de'])  ? strtotime((string) $f['de'])  : null;
    $ate = !empty($f['ate']) ? strtotime((string) $f['ate']) : null;
    if (!$de || !$ate || $ate < $de) {
        return 30;
    }
    return (int) max(1, round(($ate - $de) / 86400) + 1);
}

/**
 * Junta as linhas do banco em grupos, aplicando o custo de cada produto.
 * Funcao pura — e onde mora a conta que os testes cobrem.
 *
 * @param array            $linhas  uma linha por (grupo, produto)
 * @param array<int,float> $custos  produto_id => custo unitario da NFC-e
 */
function vendas_agrupar(array $linhas, array $custos, float $cmv_padrao_pct, float $pct_variavel): array
{
    $grupos = [];
    $cmv_total = 0.0;
    $receita_total = 0.0;
    $receita_com_nota = 0.0;

    foreach ($linhas as $l) {
        $g       = (string) ($l['grupo'] ?? '');
        $receita = num_br($l['receita'] ?? 0);
        $qtd     = num_br($l['quantidade'] ?? 0);
        $pid     = (int) ($l['produto_id'] ?? 0);

        // Custo de nota quando existe; senao o percentual padrao.
        $de_nota = $pid > 0 && isset($custos[$pid]);
        $custo   = $de_nota ? $custos[$pid] * $qtd : $receita * $cmv_padrao_pct / 100;

        $receita_total += $receita;
        $cmv_total     += $custo;
        if ($de_nota) {
            $receita_com_nota += $receita;
        }

        if (!isset($grupos[$g])) {
            $grupos[$g] = [
                'grupo' => $g, 'descricao' => (string) ($l['descricao'] ?? $g),
                'ean' => $l['ean'] ?? null, 'quantidade' => 0.0, 'receita' => 0.0,
                'custo' => 0.0, 'vendas' => 0, 'com_nota' => false, 'produtos' => 0,
            ];
        }
        $grupos[$g]['quantidade'] += $qtd;
        $grupos[$g]['receita']    += $receita;
        $grupos[$g]['custo']      += $custo;
        $grupos[$g]['vendas']     += (int) ($l['vendas'] ?? 0);
        $grupos[$g]['produtos']++;
        $grupos[$g]['com_nota'] = $grupos[$g]['com_nota'] || $de_nota;
    }

    foreach ($grupos as &$g) {
        // Margem de contribuicao: tira o custo da mercadoria e os percentuais
        // que acompanham o faturamento. Custo fixo nao entra aqui — ratear
        // energia por produto seria invencao.
        $g['bruto'] = $g['receita'] - $g['custo'];
        $g['contribuicao'] = $g['bruto'] - $g['receita'] * $pct_variavel / 100;
        $g['fator'] = $g['custo'] > 0 ? $g['receita'] / $g['custo'] : null;
    }
    unset($g);

    usort($grupos, static fn (array $a, array $b): int => $b['receita'] <=> $a['receita']);

    return [
        'linhas'    => $grupos,
        'cmv'       => $cmv_total,
        // Diagnostico: quanto do faturamento tem custo de nota de verdade, e
        // nao o percentual chutado.
        'cobertura' => $receita_total > 0 ? $receita_com_nota / $receita_total * 100 : 0.0,
    ];
}

/** O relatorio inteiro: linhas agrupadas e resultado do periodo. */
function vendas_relatorio(array $f): array
{
    $agrupamentos = vendas_agrupamentos();
    $chave = isset($agrupamentos[$f['agrupar'] ?? '']) ? (string) $f['agrupar'] : 'produto';
    [$rotulo, $coluna] = $agrupamentos[$chave];

    [$onde, $args] = vendas_filtro_sql($f);

    $busca = trim((string) ($f['busca'] ?? ''));
    if ($busca !== '') {
        $onde .= ' AND (vi.descricao LIKE ? OR vi.ean LIKE ? OR vi.codigo LIKE ? OR vi.categoria LIKE ?)';
        $curinga = '%' . $busca . '%';
        array_push($args, $curinga, $curinga, $curinga, $curinga);
    }

    // Uma linha por (grupo, produto): o custo e por produto, entao o CMV so
    // fecha se o produto vier separado dentro do grupo. A dobra em grupo
    // acontece no PHP, em vendas_agrupar().
    $linhas = q(
        'SELECT ' . $coluna . ' AS grupo,
                vi.produto_id,
                MIN(vi.descricao) AS descricao,
                MIN(vi.ean) AS ean,
                SUM(vi.quantidade) AS quantidade,
                SUM(vi.valor_total) AS receita,
                COUNT(DISTINCT v.id) AS vendas
           FROM venda_itens vi
           JOIN vendas v ON v.id = vi.venda_id
      LEFT JOIN loja_pdvs p ON p.id = v.pdv_id
          WHERE ' . $onde . '
       GROUP BY grupo, vi.produto_id
          LIMIT 20000',
        $args
    );

    $p = custos_parametros();
    $por_forma = vendas_por_forma($f);
    $pct_variavel = custos_pct_variavel($por_forma, $p);

    $agrupado = vendas_agrupar(
        $linhas,
        vendas_custo_por_produto(array_column($linhas, 'produto_id')),
        (float) $p['cmv_padrao_pct'],
        $pct_variavel
    );

    return [
        'rotulo'       => $rotulo,
        'agrupar'      => $chave,
        'linhas'       => $agrupado['linhas'],
        'por_forma'    => $por_forma,
        'resultado'    => custos_resultado($por_forma, $agrupado['cmv'], vendas_dias_do_periodo($f), $p),
        'pct_variavel' => $pct_variavel,
        'parametros'   => $p,
        'cobertura'    => $agrupado['cobertura'],
    ];
}

/** PDVs que tem venda, para o filtro da tela. */
function vendas_pdvs(): array
{
    return q(
        'SELECT p.id, p.nome, COUNT(*) AS vendas
           FROM vendas v JOIN loja_pdvs p ON p.id = v.pdv_id
       GROUP BY p.id, p.nome ORDER BY p.nome'
    );
}
