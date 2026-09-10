<?php
declare(strict_types=1);

/**
 * Registra a nota como pendente e devolve o id.
 * Se o usuario ja escaneou essa chave antes, devolve a nota existente.
 *
 * @return array{nota_id:int, duplicada:bool, chave:?string}
 */
function nota_criar_pendente(int $usuario_id, string $qr_url): array
{
    $chave = chave_do_qrcode($qr_url);

    if ($chave !== null) {
        $ja = q1(
            'SELECT id, status FROM notas WHERE usuario_id = ? AND chave = ?',
            [$usuario_id, $chave]
        );
        if ($ja) {
            return ['nota_id' => (int) $ja['id'], 'duplicada' => true, 'chave' => $chave];
        }
    }

    $id = inserir('notas', [
        'usuario_id' => $usuario_id,
        'chave'      => $chave,
        'origem'     => 'qrcode',
        'status'     => 'pendente',
        'qr_url'     => $qr_url,
    ]);

    return ['nota_id' => $id, 'duplicada' => false, 'chave' => $chave];
}

/**
 * Dispara o workflow do n8n e volta na hora (fire-and-forget).
 * O n8n devolve o resultado depois, via POST em /api/callback.
 */
function nota_disparar_n8n(int $nota_id, string $qr_url): array
{
    $webhook = (string) cfg('n8n_webhook');
    if ($webhook === '') {
        return ['ok' => false, 'erro' => 'n8n_webhook nao configurado'];
    }

    $payload = json_encode([
        'nota_id'      => $nota_id,
        'qrcode'       => $qr_url,
        'callback_url' => url('/api/callback'),
        'token'        => (string) cfg('n8n_token'),
        'guardar_html' => (bool) cfg('guardar_html', false),
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
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    // Timeout aqui nao e falha: o n8n ja recebeu e segue processando sozinho.
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        exec_sql('UPDATE notas SET status = ? WHERE id = ?', ['processando', $nota_id]);
        return ['ok' => true, 'erro' => null];
    }
    if ($errno !== 0) {
        $msg = 'Falha ao chamar o n8n: ' . curl_strerror($errno);
        nota_erro($nota_id, $msg);
        return ['ok' => false, 'erro' => $msg];
    }
    if ($http >= 400) {
        $msg = 'n8n respondeu HTTP ' . $http . ' ' . mb_substr((string) $resp, 0, 200);
        nota_erro($nota_id, $msg);
        return ['ok' => false, 'erro' => $msg];
    }

    exec_sql('UPDATE notas SET status = ? WHERE id = ?', ['processando', $nota_id]);
    return ['ok' => true, 'erro' => null];
}

function nota_erro(int $nota_id, string $msg): void
{
    exec_sql(
        'UPDATE notas SET status = ?, erro_msg = ?, processado_em = NOW() WHERE id = ?',
        ['erro', mb_substr($msg, 0, 500), $nota_id]
    );
}

/** Colunas de cabecalho que, no formato achatado, se repetem em cada linha. */
const COLUNAS_CABECALHO = [
    'chave', 'emitente', 'cnpj', 'inscricao_estadual', 'municipio', 'uf',
    'modelo', 'serie', 'numero_nota', 'numero', 'emissao',
    'valor_total_produtos', 'desconto_total_nota', 'valor_total_nota',
    'url_consulta', 'consultado_em',
];

/**
 * Aceita os dois formatos que o n8n pode mandar:
 *
 *  a) aninhado   {nota_id, token, status, nota:{...}, itens:[{...}]}
 *  b) achatado   uma linha por item, com as colunas de cabecalho repetidas
 *                (o mesmo formato que ia para o Google Sheets) — seja em
 *                "itens"/"dados"/"data", seja como lista na raiz do corpo.
 *
 * @return array{nota_id:int, token:string, status:string, erro:string, cab:array, itens:array, html:?string}
 */
function callback_normalizar(array $p): array
{
    // O n8n as vezes embrulha o corpo em "body".
    if (isset($p['body']) && is_array($p['body'])) {
        $p = $p['body'];
    }
    // Corpo veio como lista de linhas, sem envelope.
    if (array_is_list($p)) {
        $p = ['itens' => $p];
    }

    $linhas = [];
    foreach (['itens', 'dados', 'data', 'rows', 'items'] as $chave) {
        if (!empty($p[$chave]) && is_array($p[$chave])) {
            $linhas = array_values(array_filter($p[$chave], 'is_array'));
            break;
        }
    }

    $cab = is_array($p['nota'] ?? null) ? $p['nota'] : [];

    // Formato achatado: o cabecalho esta repetido dentro das linhas.
    if (!$cab && $linhas) {
        $primeira = $linhas[0];
        foreach (COLUNAS_CABECALHO as $coluna) {
            if (isset($primeira[$coluna]) && $primeira[$coluna] !== '') {
                $cab[$coluna] = $primeira[$coluna];
            }
        }
    }

    // nota_id e token podem vir no envelope ou repetidos nas linhas.
    $nota_id = (int) ($p['nota_id'] ?? ($linhas[0]['nota_id'] ?? 0));
    $token   = (string) ($p['token'] ?? ($linhas[0]['token'] ?? ''));

    return [
        'nota_id' => $nota_id,
        'token'   => $token,
        'status'  => (string) ($p['status'] ?? 'ok'),
        'erro'    => (string) ($p['erro'] ?? ''),
        'cab'     => $cab,
        'itens'   => $linhas,
        'html'    => isset($p['html']) && is_string($p['html']) ? $p['html'] : null,
    ];
}

/**
 * Grava o resultado que o n8n devolveu.
 *
 * @param array $p payload ja normalizado por callback_normalizar()
 * @return array{ok:bool, itens:int, mensagem:string}
 */
function nota_processar_callback(array $p): array
{
    $nota_id = (int) ($p['nota_id'] ?? 0);
    if ($nota_id <= 0) {
        return ['ok' => false, 'itens' => 0, 'mensagem' => 'nota_id ausente'];
    }

    $nota = q1('SELECT id, usuario_id FROM notas WHERE id = ?', [$nota_id]);
    if (!$nota) {
        return ['ok' => false, 'itens' => 0, 'mensagem' => 'nota nao encontrada'];
    }

    if (($p['status'] ?? 'ok') === 'erro') {
        nota_erro($nota_id, (string) ($p['erro'] ?: 'erro no n8n'));
        return ['ok' => true, 'itens' => 0, 'mensagem' => 'erro registrado'];
    }

    $cab   = $p['cab'];
    $itens = $p['itens'];

    if (!$itens) {
        nota_erro($nota_id, 'o n8n nao devolveu nenhum item');
        return ['ok' => true, 'itens' => 0, 'mensagem' => 'sem itens'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $estab_id = estabelecimento_resolver(
            $cab['cnpj'] ?? null,
            $cab['emitente'] ?? null,
            $cab['municipio'] ?? null,
            $cab['uf'] ?? null
        );

        $chave = so_digitos($cab['chave'] ?? '');
        $chave = strlen($chave) === 44 ? $chave : null;

        $html_gz = null;
        if (cfg('guardar_html', false) && !empty($p['html'])) {
            $html_gz = gzencode($p['html'], 6);
            // MEDIUMBLOB comporta 16 MB; se estourar, e melhor nao gravar nada.
            if ($html_gz !== false && strlen($html_gz) > 15 * 1024 * 1024) {
                $html_gz = null;
            }
        }

        $st = $pdo->prepare(
            'UPDATE notas
                SET estabelecimento_id = :estab,
                    chave              = COALESCE(:chave, chave),
                    modelo             = :modelo,
                    serie              = :serie,
                    numero             = :numero,
                    emissao            = :emissao,
                    valor_produtos     = :vprod,
                    desconto_total     = :vdesc,
                    valor_total        = :vtotal,
                    url_consulta       = :url,
                    html_gz            = COALESCE(:html, html_gz),
                    status             = :status,
                    erro_msg           = NULL,
                    processado_em      = NOW()
              WHERE id = :id'
        );
        $st->bindValue(':estab',  $estab_id, valor_pdo_tipo($estab_id));
        $st->bindValue(':chave',  $chave, valor_pdo_tipo($chave));
        $st->bindValue(':modelo', mb_substr((string) ($cab['modelo'] ?? ''), 0, 5) ?: null);
        $st->bindValue(':serie',  mb_substr((string) ($cab['serie'] ?? ''), 0, 10) ?: null);
        $st->bindValue(':numero', mb_substr((string) ($cab['numero_nota'] ?? $cab['numero'] ?? ''), 0, 20) ?: null);
        $st->bindValue(':emissao', data_mysql($cab['emissao'] ?? null));
        $st->bindValue(':vprod',  num_br($cab['valor_total_produtos'] ?? null));
        $st->bindValue(':vdesc',  num_br($cab['desconto_total_nota'] ?? null));
        $st->bindValue(':vtotal', num_br($cab['valor_total_nota'] ?? $cab['valor_total'] ?? null));
        $st->bindValue(':url',    (string) ($cab['url_consulta'] ?? '') ?: null);
        $st->bindValue(':html',   $html_gz, $html_gz === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(':status', 'ok');
        $st->bindValue(':id',     $nota_id, PDO::PARAM_INT);
        $st->execute();

        // Reprocessar a mesma nota nao pode duplicar itens
        exec_sql('DELETE FROM itens WHERE nota_id = ?', [$nota_id]);

        $gravados = 0;
        $soma_liquida = 0.0;
        foreach ($itens as $it) {
            if (!is_array($it)) {
                continue;
            }
            $produto_id = produto_resolver([
                'descricao' => $it['descricao'] ?? '',
                'ean'       => $it['ean'] ?? null,
                'codigo'    => $it['codigo'] ?? null,
                'unidade'   => $it['unidade'] ?? null,
            ], $estab_id);

            $quantidade = num_br($it['quantidade'] ?? 0);
            $unitario   = num_br($it['valor_unitario'] ?? 0);
            $bruto      = num_br($it['valor_total_item'] ?? $it['valor_total'] ?? 0);
            $desconto   = num_br($it['desconto_item'] ?? $it['desconto'] ?? 0);

            // A nota nem sempre traz o total do item; quando falta, reconstroi.
            if ($bruto <= 0 && $quantidade > 0 && $unitario > 0) {
                $bruto = round($quantidade * $unitario, 2);
            }
            $liquido = round(max(0, $bruto - $desconto), 2);

            inserir('itens', [
                'nota_id'            => $nota_id,
                'produto_id'         => $produto_id,
                'item_num'           => isset($it['item']) ? (int) $it['item'] : null,
                'descricao_original' => mb_substr(decodificar_html((string) ($it['descricao'] ?? '')), 0, 255),
                'cod_interno'        => mb_substr(trim((string) ($it['codigo'] ?? '')), 0, 60) ?: null,
                'ean_original'       => mb_substr(trim((string) ($it['ean'] ?? '')), 0, 20) ?: null,
                'ncm'                => mb_substr(so_digitos($it['ncm'] ?? ''), 0, 10) ?: null,
                'cest'               => mb_substr(so_digitos($it['cest'] ?? ''), 0, 10) ?: null,
                'cfop'               => mb_substr(so_digitos($it['cfop'] ?? ''), 0, 6) ?: null,
                'quantidade'         => $quantidade,
                'unidade'            => mb_substr(trim((string) ($it['unidade'] ?? '')), 0, 10) ?: null,
                'valor_unitario'     => $unitario,
                'valor_total'        => $bruto,
                'desconto'           => $desconto,
                'valor_total_liquido'    => $liquido,
                'valor_unitario_liquido' => $quantidade > 0 ? round($liquido / $quantidade, 4) : $liquido,
            ]);
            $gravados++;
            $soma_liquida += $liquido;
        }

        // Quando a nota nao informou o total, usa a soma dos itens ja descontados.
        if (num_br($cab['valor_total_nota'] ?? $cab['valor_total'] ?? null) <= 0) {
            exec_sql('UPDATE notas SET valor_total = ? WHERE id = ?', [$soma_liquida, $nota_id]);
        }

        $pdo->commit();
        return ['ok' => true, 'itens' => $gravados, 'mensagem' => 'gravado'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        nota_erro($nota_id, 'falha ao gravar: ' . $e->getMessage());
        return ['ok' => false, 'itens' => 0, 'mensagem' => $e->getMessage()];
    }
}

/** Apaga a nota e seus itens (a FK cuida dos itens). Respeita o dono. */
function nota_excluir(int $nota_id, int $usuario_id): bool
{
    return exec_sql(
        'DELETE FROM notas WHERE id = ? AND usuario_id = ?',
        [$nota_id, $usuario_id]
    ) > 0;
}

/**
 * Devolve uma nota travada para "pendente", para poder disparar de novo.
 * So mexe no que nao terminou: uma nota "ok" nunca e reaberta por engano.
 */
function nota_reabrir(int $nota_id, int $usuario_id): bool
{
    return exec_sql(
        'UPDATE notas SET status = ?, erro_msg = NULL, processado_em = NULL
          WHERE id = ? AND usuario_id = ? AND status <> ?',
        ['pendente', $nota_id, $usuario_id, 'ok']
    ) > 0;
}

/** Cabecalho + itens de uma nota, respeitando o dono. */
function nota_carregar(int $nota_id, int $usuario_id): ?array
{
    $nota = q1(
        'SELECT n.*, est.nome AS loja, est.cnpj, est.municipio, est.uf
           FROM notas n
      LEFT JOIN estabelecimentos est ON est.id = n.estabelecimento_id
          WHERE n.id = ? AND n.usuario_id = ?',
        [$nota_id, $usuario_id]
    );
    if (!$nota) {
        return null;
    }
    $nota['itens'] = q(
        'SELECT i.*, p.ean AS produto_ean
           FROM itens i
      LEFT JOIN produtos p ON p.id = i.produto_id
          WHERE i.nota_id = ?
       ORDER BY i.item_num ASC, i.id ASC',
        [$nota_id]
    );
    return $nota;
}

/**
 * Recalcula os valores de um item a partir da quantidade, do total bruto e do
 * desconto.
 *
 * Funcao pura, e a ancora e o TOTAL: quem edita mexe em quanto veio na caixa,
 * nao no que pagou. Trocar "1 CX" por "12 UN" mantem os R$ 24,00 da nota e
 * derruba o unitario para R$ 2,00 sozinho.
 *
 * @return array{quantidade:float, valor_total:float, desconto:float,
 *               valor_unitario:float, valor_total_liquido:float,
 *               valor_unitario_liquido:float}
 */
function item_valores(float $quantidade, float $bruto, float $desconto): array
{
    // Arredonda ANTES de conferir: 0,00004 e maior que zero, mas vira 0 na
    // coluna DECIMAL(14,4) e derrubaria a divisao do unitario.
    $quantidade = round($quantidade, 4);
    $quantidade = $quantidade > 0 ? $quantidade : 1.0;
    $bruto      = round(max(0.0, $bruto), 2);
    $desconto   = round(min(max(0.0, $desconto), $bruto), 2);
    $liquido    = round($bruto - $desconto, 2);

    return [
        'quantidade'             => $quantidade,
        'valor_total'            => $bruto,
        'desconto'               => $desconto,
        'valor_unitario'         => round($bruto / $quantidade, 4),
        'valor_total_liquido'    => $liquido,
        'valor_unitario_liquido' => round($liquido / $quantidade, 4),
    ];
}

/**
 * Corrige um item de uma nota ja gravada. Respeita o dono.
 *
 * Existe por causa da caixa fechada: a nota diz "1 CX C/12 REFRI" com o GTIN da
 * caixa, mas o que sai da prateleira e a lata. Aqui a mesma compra vira "12 UN"
 * com o codigo de barras da lata, e o historico de preco passa a falar na
 * unidade que voce realmente vende.
 *
 * @param array $in descricao, ean, quantidade, unidade, valor_total, desconto
 * @return array{ok:bool, msg:string}
 */
function nota_item_editar(int $nota_id, int $item_id, int $usuario_id, array $in): array
{
    $item = q1(
        'SELECT i.*, n.estabelecimento_id, p.ean AS produto_ean
           FROM itens i
           JOIN notas n ON n.id = i.nota_id
      LEFT JOIN produtos p ON p.id = i.produto_id
          WHERE i.id = ? AND i.nota_id = ? AND n.usuario_id = ?',
        [$item_id, $nota_id, $usuario_id]
    );
    if (!$item) {
        return ['ok' => false, 'msg' => 'Nao encontrei esse item.'];
    }

    $descricao = decodificar_html((string) ($in['descricao'] ?? ''));
    if ($descricao === '') {
        return ['ok' => false, 'msg' => 'A descricao do item nao pode ficar vazia.'];
    }
    $descricao = mb_substr($descricao, 0, 255);

    $quantidade = num_br($in['quantidade'] ?? 0);
    if ($quantidade <= 0) {
        return ['ok' => false, 'msg' => 'A quantidade precisa ser maior que zero.'];
    }
    $bruto = num_br($in['valor_total'] ?? 0);
    if ($bruto <= 0) {
        return ['ok' => false, 'msg' => 'O valor total do item precisa ser maior que zero.'];
    }
    $desconto = num_br($in['desconto'] ?? 0);
    if ($desconto > $bruto) {
        return ['ok' => false, 'msg' => 'O desconto nao pode passar do total do item.'];
    }

    // Campo em branco nao desfaz vinculo nenhum: so quem digita um codigo troca
    // o produto. Assim salvar so a quantidade nunca perde o GTIN que ja estava la.
    $ean_digitado = trim((string) ($in['ean'] ?? ''));
    $ean = ean_normalizado($ean_digitado);
    if ($ean_digitado !== '' && $ean === null) {
        return ['ok' => false, 'msg' => 'Codigo de barras invalido: use 8, 12, 13 ou 14 digitos.'];
    }

    $v        = item_valores($quantidade, $bruto, $desconto);
    $unidade  = mb_substr(trim((string) ($in['unidade'] ?? '')), 0, 10) ?: null;
    $estab_id = $item['estabelecimento_id'] !== null ? (int) $item['estabelecimento_id'] : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $produto_id = $item['produto_id'] !== null ? (int) $item['produto_id'] : null;
        $trocou     = $ean !== null && $ean !== $item['produto_ean'];

        if ($trocou) {
            $produto_id = produto_do_ean($ean, $produto_id, $descricao, $unidade);
            // O codigo interno da loja passa a apontar para o produto certo:
            // a proxima nota dessa loja ja cai no lugar, sem repetir a correcao.
            produto_alias_gravar($produto_id, $estab_id, (string) ($item['cod_interno'] ?? ''), $descricao);
        }

        exec_sql(
            'UPDATE itens
                SET produto_id             = ?,
                    descricao_original     = ?,
                    quantidade             = ?,
                    unidade                = ?,
                    valor_unitario         = ?,
                    valor_total            = ?,
                    desconto               = ?,
                    valor_total_liquido    = ?,
                    valor_unitario_liquido = ?
              WHERE id = ?',
            [
                $produto_id,
                $descricao,
                $v['quantidade'],
                $unidade,
                $v['valor_unitario'],
                $v['valor_total'],
                $v['desconto'],
                $v['valor_total_liquido'],
                $v['valor_unitario_liquido'],
                $item_id,
            ]
        );

        // O cabecalho anda pela diferenca, e nao pela soma dos itens: uma nota
        // pode ter frete ou desconto proprio, que nao esta em item nenhum.
        $d_bruto = $v['valor_total']         - (float) $item['valor_total'];
        $d_desc  = $v['desconto']            - (float) $item['desconto'];
        $d_liq   = $v['valor_total_liquido'] - (float) $item['valor_total_liquido'];
        if (abs($d_bruto) > 0.004 || abs($d_desc) > 0.004 || abs($d_liq) > 0.004) {
            exec_sql(
                'UPDATE notas
                    SET valor_produtos = GREATEST(0, COALESCE(valor_produtos, 0) + ?),
                        desconto_total = GREATEST(0, COALESCE(desconto_total, 0) + ?),
                        valor_total    = GREATEST(0, COALESCE(valor_total, 0) + ?)
                  WHERE id = ?',
                [$d_bruto, $d_desc, $d_liq, $nota_id]
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'msg' => 'Falha ao salvar o item: ' . $e->getMessage()];
    }

    $msg = 'Item corrigido: ' . qtd_fmt($v['quantidade']) . ' ' . ($unidade ?: 'un')
         . ' a ' . moeda($v['valor_unitario_liquido']) . ' cada.';
    if ($trocou) {
        $msg .= ' Codigo de barras ' . $ean . ' vinculado.';
    }
    return ['ok' => true, 'msg' => $msg];
}

/**
 * Tira um item da nota. Respeita o dono.
 *
 * Serve para o que a nota traz e a prateleira nao ve: a sacola cobrada a parte,
 * a linha duplicada pelo caixa, o item que voltou. O cabecalho desce junto pelo
 * valor do item, pela mesma razao de nota_item_editar() — frete e desconto de
 * nota nao estao em item nenhum e nao podem sumir no caminho.
 *
 * @return array{ok:bool, msg:string}
 */
function nota_item_excluir(int $nota_id, int $item_id, int $usuario_id): array
{
    $item = q1(
        'SELECT i.id, i.descricao_original, i.valor_total, i.desconto, i.valor_total_liquido
           FROM itens i
           JOIN notas n ON n.id = i.nota_id
          WHERE i.id = ? AND i.nota_id = ? AND n.usuario_id = ?',
        [$item_id, $nota_id, $usuario_id]
    );
    if (!$item) {
        return ['ok' => false, 'msg' => 'Nao encontrei esse item.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        exec_sql('DELETE FROM itens WHERE id = ?', [$item_id]);
        exec_sql(
            'UPDATE notas
                SET valor_produtos = GREATEST(0, COALESCE(valor_produtos, 0) - ?),
                    desconto_total = GREATEST(0, COALESCE(desconto_total, 0) - ?),
                    valor_total    = GREATEST(0, COALESCE(valor_total, 0) - ?)
              WHERE id = ?',
            [
                (float) $item['valor_total'],
                (float) $item['desconto'],
                (float) $item['valor_total_liquido'],
                $nota_id,
            ]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'msg' => 'Falha ao remover o item: ' . $e->getMessage()];
    }

    $restam = (int) qv('SELECT COUNT(*) FROM itens WHERE nota_id = ?', [$nota_id]);
    $msg = 'Item removido. ' . ($restam > 0
        ? 'Restam ' . $restam . ' na nota.'
        : 'A nota ficou sem itens — se ela nao serve mais, remova a nota inteira.');

    return ['ok' => true, 'msg' => $msg];
}
