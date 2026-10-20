<?php
declare(strict_types=1);

/**
 * Repor em lote: a planilha do Repor.
 *
 * Cada linha e um produto da gondola (codigo, entrada, validade, preco...).
 * Para cada uma o app faz o mesmo que o Repor faz por bipe — planograma_procurar()
 * e planograma_salvar() — so que sem digitar. Nada aqui reimplementa a conversa
 * com o TouchPay: quem escreve na loja continua sendo planograma_salvar().
 *
 * A quantidade da planilha e ENTRADA: soma ao estoque que ja esta la. "Coloquei
 * mais 12" e o que se sabe de pe no corredor; o numero absoluto so existe depois
 * de ler o estoque, e isso muda a cada venda da maquina.
 *
 * O que nao existe no TouchPay (nem no catalogo) nao e cadastrado: vira a lista
 * "sem cadastro", para pedir o cadastro. O app nao tem como criar produto la.
 *
 * Tudo que e regra e funcao pura (testes/planilha_repor.php). Banco e TouchPay
 * ficam nas funcoes repor_lote_* e repor_*_linha, no fim.
 */

const REPOR_MAX_LINHAS = 500;
/** Taxa (markup) que o Repor propoe quando a planilha nao traz a sua. */
const REPOR_TAXA_PADRAO = 1.90;
/** Uma linha 'gravando' parada mais que isso morreu no meio da gravacao. */
const REPOR_GRAVANDO_MINUTOS = 5;

const REPOR_COLUNAS = [
    'codigo', 'descricao', 'entrada', 'validade', 'preco', 'custo', 'taxa',
    'necessaria', 'critico', 'pdv',
];

const REPOR_COLUNAS_TEXTO = ['codigo', 'validade', 'pdv'];

const REPOR_SINONIMOS = [
    'codigo'     => ['EAN', 'GTIN', 'CODIGO DE BARRAS', 'COD BARRAS', 'CODIGO BARRAS'],
    'descricao'  => ['PRODUTO', 'NOME'],
    'entrada'    => ['QTD', 'QTDE', 'QUANTIDADE', 'REPOR', 'REPOSICAO'],
    'validade'   => ['VENCIMENTO', 'DATA VALIDADE', 'DATA DE VALIDADE'],
    'preco'      => ['PRECO VENDA', 'PRECO DE VENDA', 'VALOR'],
    'custo'      => ['CUSTO UNITARIO', 'PRECO CUSTO', 'PRECO DE CUSTO'],
    'taxa'       => ['MARKUP'],
    'necessaria' => ['QTD NECESSARIA', 'QUANTIDADE NECESSARIA'],
    'critico'    => ['QTD CRITICO', 'QUANTIDADE CRITICA', 'MINIMO', 'ESTOQUE MINIMO'],
    'pdv'        => ['PONTO DE VENDA', 'LOJA'],
];

// =====================================================================
// O modelo para baixar
// =====================================================================

function repor_spec(): array
{
    return [
        'colunas'    => REPOR_COLUNAS,
        'texto'      => REPOR_COLUNAS_TEXTO,
        'larguras'   => ['descricao' => 34, 'codigo' => 18, 'pdv' => 20],
        'exemplo'    => [
            ['7891000100103', 'ARROZ TIPO 1 5KG', 12, '15/03/2027', '', 21.5, '', 12, 4, ''],
            ['7896007811021', 'REFRIGERANTE COLA 2L', 24, '', 11.9, '', '', '', '', ''],
        ],
        'instrucoes' => [
            ['codigo', 'sim', 'EAN (código de barras) ou código de balança. O app procura no planograma e, se não estiver, no cadastro do TouchPay.'],
            ['descricao', 'não', 'Só para você se achar: aparece na prévia e na lista de produtos sem cadastro.'],
            ['entrada', 'não', 'Quantidade que você COLOCOU agora. Soma ao estoque que já está no TouchPay (a prévia mostra de → para). Número inteiro.'],
            ['validade', 'não', 'dd/mm/aaaa. Se já houver validade, o app mantém a que vence primeiro. Vazio = não mexe.'],
            ['preco', 'não*', 'Preço de venda. Vazio = não mexe (ou calcula custo × taxa, se houver custo).'],
            ['custo', 'não', 'Custo unitário. Com custo e sem preço, o preço sai de custo × taxa.'],
            ['taxa', 'não', 'Multiplicador do custo (1,90 = +90%). Vazio = a taxa escolhida na tela.'],
            ['necessaria', 'não', 'Quantidade necessária da gôndola. Vazio = não mexe.'],
            ['critico', 'não', 'Quantidade crítica (alerta de reposição). Vazio = não mexe.'],
            ['pdv', 'não', 'Nome (ou número) do ponto de venda. Vazio = o PDV escolhido na tela.'],
            ['', '', '* Produto que ainda NÃO está no planograma só entra com preço (ou custo). Sem isso a linha fica como pendência.'],
            ['', '', 'Produto que não existe nem no cadastro do TouchPay não é criado: aparece na lista "sem cadastro" para você pedir o cadastro.'],
        ],
        'titulo'     => 'Como preencher a aba "Itens" (Repor em lote)',
        'intro'      => 'Uma linha por produto. Apague as 2 linhas de exemplo antes de enviar. Campo vazio = o app não mexe nele.',
    ];
}

// =====================================================================
// Regras puras
// =====================================================================

/**
 * Preco de venda de uma linha: o preco digitado, ou custo x taxa. Pura.
 * Mesma conta de precoSugerido() em assets/planograma.js — se uma mudar, a
 * outra precisa mudar junto.
 */
function repor_preco(?float $preco, ?float $custo, ?float $taxa): ?float
{
    if ($preco !== null && $preco > 0) {
        return round($preco, 2);
    }
    if ($custo !== null && $taxa !== null && $custo > 0 && $taxa > 0) {
        return round($custo * $taxa, 2);
    }
    return null;
}

/**
 * Tira da lista os PDVs que o TouchPay nao devolve mais. Pura.
 * Sem a resposta do painel ($vivos null ou vazio), nao esconde ninguem:
 * painel fora do ar nao pode deixar a lista vazia.
 *
 * @param ?int[] $vivos ids do TouchPay (planograma_sincronizar_pdvs)
 */
function repor_pdvs_vivos(array $pdvs, ?array $vivos): array
{
    if (!$vivos) {
        return $pdvs;
    }
    return array_values(array_filter($pdvs, static fn($p) => in_array((int) $p['externo_id'], $vivos, true)));
}

/** Acha o PDV pelo nome (sem acento/caixa) ou pelo id. Pura. */
function repor_pdv_achar(string $texto, array $pdvs): ?array
{
    $alvo = normalizar_texto($texto);
    if ($alvo === '') {
        return null;
    }
    foreach ($pdvs as $p) {
        if (normalizar_texto((string) $p['nome']) === $alvo) {
            return $p;
        }
    }
    if (ctype_digit(trim($texto))) {
        foreach ($pdvs as $p) {
            if ((int) $p['id'] === (int) $texto) {
                return $p;
            }
        }
    }
    return null;
}

/**
 * Valida e normaliza as linhas do modelo. Qualquer erro recusa o arquivo.
 *
 * @param array<int, array<string,string>> $linhas saida de planilha_linhas_assoc()
 * @param ?int  $pdv_padrao id local do PDV escolhido na tela
 * @param array $pdvs       planograma_pdvs(): precisam de id, nome, planograma_id
 * @return array{itens:list<array>, erros:list<array{linha:int,msg:string}>}
 */
function repor_ler_linhas(array $linhas, ?int $pdv_padrao, float $taxa_padrao, array $pdvs,
                          ?string $hoje = null): array
{
    $erros = [];
    $itens = [];
    $vistos = []; // "pdv|codigo" => linha

    if (!$linhas) {
        return ['itens' => [], 'erros' => [['linha' => 1, 'msg' => 'A planilha não tem nenhum produto.']]];
    }
    if (count($linhas) > REPOR_MAX_LINHAS) {
        return ['itens' => [], 'erros' => [['linha' => 1,
            'msg' => 'Máximo de ' . REPOR_MAX_LINHAS . ' produtos por planilha (veio com ' . count($linhas) . ').']]];
    }

    // Texto digitado que nao e numero e erro, nao "campo vazio": calar aqui
    // gravaria o produto sem o preco que a pessoa achou que tinha mandado.
    $numero = static function (string $campo, string $bruto, int $n, string $nome, bool $maior_que_zero)
        use (&$erros): ?float {
        if ($bruto === '') {
            return null;
        }
        $v = pg_numero($bruto);
        if ($v === null) {
            $erros[] = ['linha' => $n, 'msg' => $nome . ': "' . $bruto . '" não é número.'];
            return null;
        }
        if ($v < 0 || ($maior_que_zero && $v <= 0)) {
            $erros[] = ['linha' => $n, 'msg' => $nome . ' precisa ser '
                . ($maior_que_zero ? 'maior que zero.' : 'zero ou mais.')];
            return null;
        }
        return $v;
    };

    foreach ($linhas as $n => $r) {
        $antes = count($erros);

        $codigo = mercado_chave($r['codigo']);
        if ($codigo === null) {
            $erros[] = ['linha' => $n, 'msg' => 'Código "' . $r['codigo'] . '" inválido (use o EAN, com 6 dígitos ou mais).'];
            continue;
        }

        // PDV: o da linha, senao o da tela.
        $pdv = null;
        if ($r['pdv'] !== '') {
            $pdv = repor_pdv_achar($r['pdv'], $pdvs);
            if ($pdv === null) {
                $erros[] = ['linha' => $n, 'msg' => 'PDV "' . $r['pdv'] . '" não encontrado. Use: '
                    . implode(', ', array_map(static fn($p) => $p['nome'], $pdvs)) . '.'];
            }
        } elseif ($pdv_padrao !== null) {
            foreach ($pdvs as $p) {
                if ((int) $p['id'] === $pdv_padrao) {
                    $pdv = $p;
                }
            }
            if ($pdv === null) {
                $erros[] = ['linha' => $n, 'msg' => 'O PDV escolhido não existe mais.'];
            }
        } else {
            $erros[] = ['linha' => $n, 'msg' => 'Informe o PDV (coluna "pdv" ou o campo na tela).'];
        }
        if ($pdv !== null && (int) ($pdv['planograma_id'] ?? 0) <= 0) {
            $erros[] = ['linha' => $n, 'msg' => 'O PDV "' . $pdv['nome'] . '" não tem planograma ativo no TouchPay.'];
        }

        $entrada = $numero('entrada', $r['entrada'], $n, 'entrada', false);
        if ($entrada !== null && abs($entrada - round($entrada)) > 0.0009) {
            $erros[] = ['linha' => $n, 'msg' => 'entrada precisa ser um número inteiro (veio ' . $r['entrada'] . ').'];
        }
        $preco      = $numero('preco', $r['preco'], $n, 'preço', true);
        $custo      = $numero('custo', $r['custo'], $n, 'custo', true);
        $taxa       = $numero('taxa', $r['taxa'], $n, 'taxa', true);
        $necessaria = $numero('necessaria', $r['necessaria'], $n, 'necessária', false);
        $critico    = $numero('critico', $r['critico'], $n, 'crítico', false);

        $validade = null;
        if ($r['validade'] !== '') {
            $validade = pg_data_iso($r['validade']);
            if ($validade === null && is_numeric(str_replace(',', '.', $r['validade']))) {
                // Excel que converteu a data em numero serial
                $validade = substr((string) planilha_data($r['validade']), 0, 10) ?: null;
            }
            if ($validade === null) {
                $erros[] = ['linha' => $n, 'msg' => 'Validade "' . $r['validade'] . '" inválida (use dd/mm/aaaa).'];
            } elseif (!pg_validade_plausivel($validade, $hoje)) {
                $erros[] = ['linha' => $n, 'msg' => 'Validade ' . pg_data_br($validade) . ' fora do razoável. Confira o ano.'];
            }
        }

        $preco_final = repor_preco($preco, $custo, $taxa ?? $taxa_padrao);

        $faz_algo = ($entrada !== null && $entrada > 0) || $validade !== null || $preco_final !== null
            || $necessaria !== null || $critico !== null;
        if (!$faz_algo && count($erros) === $antes) {
            $erros[] = ['linha' => $n, 'msg' => 'Linha sem nada para fazer: preencha entrada, validade, preço/custo, necessária ou crítico.'];
        }

        if ($pdv !== null) {
            $chave = $pdv['id'] . '|' . $codigo;
            if (isset($vistos[$chave])) {
                $erros[] = ['linha' => $n, 'msg' => 'Código ' . $codigo . ' repetido (já está na linha ' . $vistos[$chave]
                    . ' para o mesmo PDV). Some as entradas numa linha só.'];
            }
            $vistos[$chave] = $n;
        }

        if (count($erros) > $antes) {
            continue;
        }
        $itens[] = [
            'linha'      => $n,
            'pdv_id'     => (int) $pdv['id'],
            'pdv'        => (string) $pdv['nome'],
            'codigo'     => $codigo,
            'descricao'  => mb_substr($r['descricao'], 0, 255),
            'entrada'    => $entrada !== null && $entrada > 0 ? round($entrada) : null,
            'validade'   => $validade,
            'preco'      => $preco_final,
            'custo'      => $custo,
            'necessaria' => $necessaria,
            'critico'    => $critico,
        ];
    }

    usort($erros, static fn($a, $b) => $a['linha'] <=> $b['linha']);
    return ['itens' => $itens, 'erros' => $erros];
}

/**
 * O que a conferencia viu e o que vai mudar, para uma linha. Pura.
 *
 * @param array $achado saida de planograma_procurar() (onde, entrada, produto)
 * @param array $item   uma linha de repor_ler_linhas()
 * @return array{classe:string, produto_id:int, codigo:string, descricao:string,
 *               mudancas:list<array>, pendencia:?string, nota:?string, visto:array}
 */
function repor_plano(array $achado, array $item): array
{
    $onde = $achado['onde'] ?? 'nenhum';
    $base = [
        'classe' => 'sem_cadastro', 'produto_id' => 0, 'codigo' => $item['codigo'],
        'descricao' => $item['descricao'], 'mudancas' => [], 'pendencia' => null,
        'nota' => null, 'visto' => [],
    ];
    $entrada = (float) ($item['entrada'] ?? 0);

    if ($onde === 'cadastro') {
        $p = $achado['produto'];
        $m = [];
        $pendencia = null;
        if ($item['preco'] === null) {
            $pendencia = 'Ainda não está no planograma: informe o preço (ou o custo).';
        } else {
            $m[] = ['campo' => 'preco', 'de' => null, 'para' => $item['preco']];
            $m[] = ['campo' => 'necessaria', 'de' => null, 'para' => (float) ($item['necessaria'] ?? 0)];
            $m[] = ['campo' => 'critico', 'de' => null, 'para' => (float) ($item['critico'] ?? 0)];
            if ($entrada > 0) {
                $m[] = ['campo' => 'estoque', 'de' => 0.0, 'para' => $entrada];
            }
            if ($item['validade'] !== null) {
                $m[] = ['campo' => 'validade', 'de' => null, 'para' => $item['validade']];
            }
        }
        return ['classe' => 'cadastro', 'produto_id' => (int) $p['produto_id'],
                'descricao' => $p['descricao'] ?: $item['descricao'],
                'mudancas' => $m, 'pendencia' => $pendencia] + $base;
    }

    if ($onde !== 'planograma') {
        return $base;
    }

    $e = $achado['entrada'];
    $visto = [
        'preco' => (float) $e['preco'], 'estoque' => (float) $e['estoque'],
        'necessaria' => (float) $e['necessaria'], 'critico' => (float) $e['critico'],
        'validade' => $e['validade'] ?? null,
    ];
    $m = pg_mudancas($visto, [
        'preco' => $item['preco'], 'necessaria' => $item['necessaria'], 'critico' => $item['critico'],
        'estoque' => $entrada > 0 ? $visto['estoque'] + $entrada : null,
    ]);

    $nota = null;
    if ($item['validade'] !== null) {
        $dec = pg_validade_decidir($visto['validade'], $item['validade']);
        if ($dec['acao'] === 'gravar' && $visto['validade'] !== $item['validade']) {
            $m[] = ['campo' => 'validade', 'de' => $visto['validade'], 'para' => $item['validade']];
        } elseif ($dec['acao'] === 'manter') {
            $nota = $dec['motivo'];
        }
    }

    return ['classe' => $m ? 'planograma' : 'sem_mudanca', 'produto_id' => (int) $e['produto_id'],
            'descricao' => $e['descricao'] ?: $item['descricao'],
            'mudancas' => $m, 'nota' => $nota, 'visto' => $visto] + $base;
}

/** A classe do plano vira o status da linha. Pura. */
function repor_status_do_plano(array $plano): string
{
    if ($plano['classe'] === 'cadastro' && $plano['pendencia'] !== null) {
        return 'pendencia';
    }
    return $plano['classe'];
}

/**
 * O corpo para planograma_salvar(), a partir de uma leitura FRESCA do produto.
 *
 * O estoque vai como alvo absoluto (lido + entrada), porque o PUT do TouchPay
 * define, nao soma. Se esta linha ja tentou antes e parou no meio ($previo),
 * e o estoque lido ja e o alvo de entao, a entrada JA entrou: nao soma de novo.
 *
 * @param array $plano  repor_plano() da leitura de agora
 * @param ?array $previo {estoque_base, estoque_alvo} da tentativa anterior
 * @return array{dados:array, estoque_base:?float, estoque_alvo:?float, ja_aplicado:bool}
 */
function repor_dados_salvar(array $plano, array $item, ?array $previo = null): array
{
    $entrada = (float) ($item['entrada'] ?? 0);
    $lido    = (float) ($plano['visto']['estoque'] ?? 0.0);
    $alvo    = null;
    $ja      = false;

    if ($entrada > 0) {
        if ($previo && isset($previo['estoque_alvo'], $previo['estoque_base'])
            && abs($lido - (float) $previo['estoque_alvo']) <= 0.0009
            && abs((float) $previo['estoque_alvo'] - (float) $previo['estoque_base']) > 0.0009) {
            $ja = true; // a tentativa anterior chegou a gravar o estoque
        } else {
            $alvo = $lido + $entrada;
        }
    }

    $dados = [
        'pdv'          => (int) $item['pdv_id'],
        'produto_id'   => (int) $plano['produto_id'],
        'codigo'       => $item['codigo'],
        'descricao'    => $plano['descricao'],
        'preco'        => $item['preco'],
        'necessaria'   => $item['necessaria'],
        'critico'      => $item['critico'],
        'estoque'      => $alvo,
        'validade'     => $item['validade'],
        'validade_acao' => 'auto',
    ];
    if ($plano['classe'] === 'planograma' || $plano['classe'] === 'sem_mudanca') {
        // O que acabou de ser lido: se alguem mexer entre a leitura e a
        // gravacao, planograma_salvar() acusa conflito em vez de sobrescrever.
        $dados['visto'] = $plano['visto'];
    }

    return ['dados' => $dados, 'estoque_base' => $entrada > 0 ? $lido : null,
            'estoque_alvo' => $alvo, 'ja_aplicado' => $ja];
}

/**
 * 404 do TouchPay quase sempre e planograma/PDV que mudou ou sumiu: a mensagem
 * crua ("Not Found (GET /api/Planograms/96856)") nao diz o que fazer. Pura.
 */
function repor_dica_erro(string $msg): string
{
    if (str_contains($msg, '404') || stripos($msg, 'not found') !== false) {
        return $msg . ' — o planograma deste PDV pode ter mudado ou o PDV não existe mais no TouchPay. '
            . 'Volte, escolha o PDV de novo e envie a planilha outra vez.';
    }
    return $msg;
}

/** "estoque 5 → 17; preço 9,50 → 11,90" para a coluna de resultado. Pura. */
function repor_mudancas_texto(array $mudancas): string
{
    $rot = ['preco' => 'preço', 'estoque' => 'estoque', 'necessaria' => 'necessária',
            'critico' => 'crítico', 'validade' => 'validade'];
    $fmt = static function ($campo, $v): string {
        if ($v === null) {
            return '—';
        }
        if ($campo === 'validade') {
            return pg_data_br((string) $v);
        }
        $n = (float) $v;
        return $campo === 'preco' ? number_format($n, 2, ',', '.')
            : rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',');
    };
    $partes = [];
    foreach ($mudancas as $m) {
        $partes[] = ($rot[$m['campo']] ?? $m['campo']) . ' ' . $fmt($m['campo'], $m['de'])
            . ' → ' . $fmt($m['campo'], $m['para']);
    }
    return implode('; ', $partes);
}

/** A lista dos que nao existem no TouchPay, em CSV (; e BOM). Pura. */
function repor_sem_cadastro_csv(array $linhas): string
{
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['codigo', 'descricao', 'pdv', 'entrada', 'validade'], ';', '"', '');
    foreach ($linhas as $l) {
        $d = $l['dados'];
        fputcsv($fh, [
            $l['codigo'], $d['descricao'] ?? '', $d['pdv'] ?? '', $d['entrada'] ?? '',
            !empty($d['validade']) ? pg_data_br($d['validade']) : '',
        ], ';', '"', '');
    }
    rewind($fh);
    $csv = (string) stream_get_contents($fh);
    fclose($fh);
    return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $csv);
}

// =====================================================================
// Banco e TouchPay
// =====================================================================

/** Cria o lote e as linhas (todas 'pendente'). Devolve o id do lote. */
function repor_lote_criar(int $usuario_id, ?int $pdv_padrao, string $arquivo, array $itens,
                          ?int $nota_id = null): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lote_id = inserir('planograma_lotes', [
            'usuario_id'    => $usuario_id,
            'pdv_padrao_id' => $pdv_padrao,
            'nota_id'       => $nota_id,
            'arquivo'       => mb_substr($arquivo, 0, 190) ?: null,
            'criado_em'     => date('Y-m-d H:i:s'),
        ]);
        foreach ($itens as $it) {
            inserir('planograma_lote_linhas', [
                'lote_id'   => $lote_id,
                'linha'     => $it['linha'],
                'pdv_id'    => $it['pdv_id'],
                'codigo'    => $it['codigo'],
                'descricao' => $it['descricao'] !== '' ? $it['descricao'] : null,
                'dados'     => json_encode($it, JSON_UNESCAPED_UNICODE),
                'status'    => 'pendente',
            ]);
        }
        $pdo->commit();
        return $lote_id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Uma linha do banco com os JSON abertos e o texto do que muda. */
function repor_linha_abrir(array $row): array
{
    $dados = json_decode((string) $row['dados'], true) ?: [];
    $plano = $row['plano'] !== null ? (json_decode((string) $row['plano'], true) ?: null) : null;
    return [
        'id'        => (int) $row['id'],
        'linha'     => (int) $row['linha'],
        'pdv_id'    => $row['pdv_id'] !== null ? (int) $row['pdv_id'] : null,
        'codigo'    => (string) $row['codigo'],
        'descricao' => (string) ($row['descricao'] ?? ''),
        'status'    => (string) $row['status'],
        'mensagem'  => (string) ($row['mensagem'] ?? ''),
        'dados'     => $dados,
        'plano'     => $plano,
    ];
}

/** A linha como a tela entende (sem o JSON cru). */
function repor_linha_tela(array $l): array
{
    $plano = $l['plano'] ?? null;
    return [
        'linha'     => $l['linha'],
        'codigo'    => $l['codigo'],
        'descricao' => ($plano['descricao'] ?? '') ?: $l['descricao'],
        'pdv'       => (string) ($l['dados']['pdv'] ?? ''),
        'entrada'   => $l['dados']['entrada'] ?? null,
        'validade'  => $l['dados']['validade'] ?? null,
        'status'    => $l['status'],
        'mensagem'  => $l['mensagem'],
        'mudancas'  => $plano ? repor_mudancas_texto($plano['mudancas'] ?? []) : '',
        'pendencia' => $plano['pendencia'] ?? null,
        // O aviso da correcao na nota vai junto do que a conferencia achou.
        'nota'      => implode(' ', array_filter([
            $l['dados']['corrigido'] ?? null, $plano['nota'] ?? null,
        ])) ?: null,
    ];
}

/**
 * O lote do usuario, com as linhas. Antes de devolver, liberta as que ficaram
 * 'gravando' por mais de REPOR_GRAVANDO_MINUTOS: morreram no meio, e a pessoa
 * precisa saber que o estado delas no TouchPay e incerto.
 *
 * @return ?array{lote:array, linhas:list<array>}
 */
function repor_lote_carregar(int $lote_id, int $usuario_id): ?array
{
    $lote = q1('SELECT * FROM planograma_lotes WHERE id = ? AND usuario_id = ?', [$lote_id, $usuario_id]);
    if (!$lote) {
        return null;
    }
    exec_sql(
        'UPDATE planograma_lote_linhas
            SET status = ?, mensagem = ?
          WHERE lote_id = ? AND status = ? AND atualizado_em < (NOW() - INTERVAL ' . REPOR_GRAVANDO_MINUTOS . ' MINUTE)',
        ['erro', 'Parou no meio da gravação. Confira o estoque no TouchPay antes de tentar de novo.',
         $lote_id, 'gravando']
    );
    $linhas = array_map('repor_linha_abrir', q(
        'SELECT * FROM planograma_lote_linhas WHERE lote_id = ? ORDER BY linha',
        [$lote_id]
    ));
    return ['lote' => $lote, 'linhas' => $linhas];
}

/** Uma linha do lote, do usuario certo. */
function repor_linha_carregar(int $lote_id, int $linha, int $usuario_id): ?array
{
    $row = q1(
        'SELECT l.* FROM planograma_lote_linhas l
           JOIN planograma_lotes p ON p.id = l.lote_id
          WHERE l.lote_id = ? AND l.linha = ? AND p.usuario_id = ?',
        [$lote_id, $linha, $usuario_id]
    );
    return $row ? repor_linha_abrir($row) : null;
}

function repor_linha_salvar(int $id, string $status, ?array $plano, string $mensagem = ''): void
{
    exec_sql(
        'UPDATE planograma_lote_linhas SET status = ?, plano = ?, mensagem = ? WHERE id = ?',
        [$status, $plano === null ? null : json_encode($plano, JSON_UNESCAPED_UNICODE),
         mb_substr($mensagem, 0, 500) ?: null, $id]
    );
}

/** Fase 1: le o TouchPay e guarda o que vai mudar. Nao escreve em lugar nenhum. */
function repor_conferir_linha(int $lote_id, int $linha, int $usuario_id): ?array
{
    $l = repor_linha_carregar($lote_id, $linha, $usuario_id);
    if (!$l) {
        return null;
    }
    // O que ja foi gravado (ou esta sendo) nao se reconfere.
    if (in_array($l['status'], ['gravada', 'gravando'], true)) {
        return repor_linha_tela($l);
    }
    // O item pode ter sido corrigido na nota depois que o lote nasceu.
    $l = repor_linha_seguir_nota($l, $usuario_id);
    try {
        $achado = planograma_procurar((int) $l['pdv_id'], $l['codigo']);
        $plano = repor_plano($achado, $l['dados']);
        $status = repor_status_do_plano($plano);
        $msg = $status === 'sem_cadastro' ? 'Não existe no planograma nem no cadastro do TouchPay.'
            : ($plano['pendencia'] ?? '');
        repor_linha_salvar($l['id'], $status, $plano, $msg);
        $l['plano'] = $plano;
        $l['status'] = $status;
        $l['mensagem'] = $msg;
    } catch (TouchPayErro $e) {
        $msg = repor_dica_erro($e->getMessage());
        repor_linha_salvar($l['id'], 'erro', $l['plano'], $msg);
        $l['status'] = 'erro';
        $l['mensagem'] = $msg;
    }
    return repor_linha_tela($l);
}

/**
 * Fase 2: grava UMA linha no TouchPay.
 *
 * Reserva a linha com um UPDATE condicional antes de escrever — e o que impede
 * duas abas, um F5 ou um clique duplo de somar a entrada duas vezes. Depois
 * le o produto de novo (o estoque muda a cada venda da maquina) e so entao
 * monta o alvo, que e a leitura + entrada.
 */
function repor_gravar_linha(array $u, int $lote_id, int $linha): ?array
{
    $usuario_id = (int) $u['id'];
    $l = repor_linha_carregar($lote_id, $linha, $usuario_id);
    if (!$l) {
        return null;
    }

    // Corrigido na nota depois da conferencia: o que a pessoa viu era de outro
    // codigo. Confere de novo e devolve, sem gravar; o proximo clique grava.
    if (repor_linha_seguir_nota($l, $usuario_id)['dados'] !== $l['dados']) {
        return repor_conferir_linha($lote_id, $linha, $usuario_id);
    }

    $reservou = exec_sql(
        'UPDATE planograma_lote_linhas SET status = ?, mensagem = NULL
          WHERE id = ? AND status IN (?, ?, ?, ?)',
        ['gravando', $l['id'], 'pendente', 'planograma', 'cadastro', 'erro']
    );
    if ($reservou === 0) {
        // Ja gravada, sendo gravada, ou sem o que gravar: devolve como esta.
        return repor_linha_tela(repor_linha_carregar($lote_id, $linha, $usuario_id) ?? $l);
    }

    $previo = $l['plano']['gravacao'] ?? null;
    $r = null;
    $plano = null;
    // No erro o plano volta ao banco COM o marcador do estoque (base e alvo):
    // e ele que impede a proxima tentativa de somar a entrada de novo.
    $plano_com_marcador = static function () use (&$plano, &$previo, $l): ?array {
        $p = $plano ?? $l['plano'];
        if ($p !== null && !isset($p['gravacao']) && $previo !== null) {
            $p['gravacao'] = $previo;
        }
        return $p;
    };
    try {
        for ($tentativa = 0; $tentativa < 2; $tentativa++) {
            $achado = planograma_procurar((int) $l['pdv_id'], $l['codigo']);
            $plano = repor_plano($achado, $l['dados']);
            $status = repor_status_do_plano($plano);

            if (!in_array($status, ['planograma', 'cadastro'], true)) {
                $msg = $status === 'sem_cadastro' ? 'Não existe no planograma nem no cadastro do TouchPay.'
                    : ($status === 'pendencia' ? (string) $plano['pendencia'] : 'Já está como a planilha pede.');
                repor_linha_salvar($l['id'], $status, $plano, $msg);
                $l['plano'] = $plano; $l['status'] = $status; $l['mensagem'] = $msg;
                return repor_linha_tela($l);
            }

            $m = repor_dados_salvar($plano, $l['dados'], $previo);
            // Guarda o alvo ANTES de escrever: se parar no meio, a proxima
            // tentativa sabe se o estoque chegou a entrar.
            $plano['gravacao'] = ['estoque_base' => $m['estoque_base'], 'estoque_alvo' => $m['estoque_alvo']];
            exec_sql('UPDATE planograma_lote_linhas SET plano = ? WHERE id = ?',
                [json_encode($plano, JSON_UNESCAPED_UNICODE), $l['id']]);

            $r = planograma_salvar($u, (int) $l['pdv_id'], $m['dados']);
            if (empty($r['conflito'])) {
                break;
            }
            $previo = $plano['gravacao']; // alguem mexeu no meio: le de novo uma vez
        }

        if ($r === null || !empty($r['conflito'])) {
            throw new TouchPayErro('Alguém mexeu neste produto durante a gravação. Tente de novo.');
        }
        $feito = $r['mudancas'] ?? [];
        $txt = $feito ? repor_mudancas_texto($feito) : 'nada precisou mudar';
        repor_linha_salvar($l['id'], 'gravada', $plano, $txt);
        $l['plano'] = $plano; $l['status'] = 'gravada'; $l['mensagem'] = $txt;
    } catch (TouchPayErro $e) {
        $l['plano'] = $plano_com_marcador();
        $msg = repor_dica_erro($e->getMessage());
        repor_linha_salvar($l['id'], 'erro', $l['plano'], $msg);
        $l['status'] = 'erro'; $l['mensagem'] = $msg;
    } catch (Throwable $e) {
        error_log('repor lote: ' . $e->getMessage());
        $l['plano'] = $plano_com_marcador();
        repor_linha_salvar($l['id'], 'erro', $l['plano'], 'Erro inesperado: ' . $e->getMessage());
        $l['status'] = 'erro'; $l['mensagem'] = 'Erro inesperado: ' . $e->getMessage();
    }
    return repor_linha_tela($l);
}

/** Os ultimos lotes do usuario, para a tela do Repor. */
function repor_lotes_recentes(int $usuario_id, int $limite = 5): array
{
    return q(
        'SELECT p.id, p.arquivo, p.criado_em,
                COUNT(l.id) AS total,
                SUM(l.status = ?) AS gravadas,
                SUM(l.status = ?) AS sem_cadastro
           FROM planograma_lotes p
      LEFT JOIN planograma_lote_linhas l ON l.lote_id = p.id
          WHERE p.usuario_id = ?
       GROUP BY p.id
       ORDER BY p.id DESC
          LIMIT ' . max(1, min(20, $limite)),
        ['gravada', 'sem_cadastro', $usuario_id]
    );
}
