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
