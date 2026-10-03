<?php
declare(strict_types=1);

/**
 * Promocao para o que esta perto de vencer.
 *
 * A pergunta de quem toca num produto da lista por vencimento e uma so: "por
 * quanto eu ponho isto para sair a tempo?". A resposta junta tres coisas que
 * o app ja sabe e que ninguem cruza de cabeca no corredor:
 *
 *  - quantos dias faltam (a validade do TouchPay);
 *  - quanto dele sai por dia (as vendas daquele ponto de venda);
 *  - quanto ele custou (as notas do atacado).
 *
 * A sugestao vem como TAXA sobre o custo — a mesma conta custo x taxa do
 * Repor —, porque e assim que o preco e pensado aqui. E vira PROMOCAO do
 * TouchPay, nao troca de preco: um desconto percentual com comeco e fim. O
 * preco do planograma fica intacto e volta sozinho quando a promocao acaba —
 * ninguem precisa lembrar de desfazer, e o relatorio de margem continua
 * comparando com o preco de verdade.
 */

/**
 * A taxa da escada, so pelos dias que faltam. Funcao pura.
 *
 * Quanto mais perto do fim, mais funda a promocao. Os degraus seguem o jeito
 * que se fala — dias, semanas, meses — e partem do 1,9 de todo dia: um
 * decimo a menos por degrau e desconto que o cliente percebe sem a loja
 * entregar a margem logo de cara.
 *
 * Mais de 60 dias nao pede promocao nenhuma so por causa da data.
 */
function promocao_taxa_por_dias(int $dias): ?float
{
    if ($dias <= 3) {
        return 1.4;
    }
    if ($dias <= 7) {
        return 1.5;
    }
    if ($dias <= 15) {
        return 1.6;
    }
    if ($dias <= 30) {
        return 1.7;
    }
    if ($dias <= 60) {
        return 1.8;
    }
    return null;
}

/**
 * O menor fator que nao tira dinheiro do bolso, em passos de 0,05. Funcao pura.
 *
 * O piso de prejuizo (1,132x, por exemplo) e conta exata; etiqueta com taxa
 * 1,132 ninguem le. Arredonda PARA CIMA, senao o arredondamento sozinho
 * empurraria a promocao para baixo do piso.
 */
function promocao_piso(?float $prejuizo): ?float
{
    if ($prejuizo === null || $prejuizo <= 0) {
        return null;
    }
    return ceil(round($prejuizo * 20, 6)) / 20;
}

/**
 * O que fazer com este item. Funcao pura.
 *
 * A data sozinha mente para os dois lados. Iogurte que vence em 5 dias e
 * vende 4 por dia com 6 na gondola sai sozinho — promocao ali e margem
 * jogada fora. Atum que vence em 50 dias e nao vendeu nenhum no mes nao sai
 * nunca no preco de hoje. Por isso o ritmo de venda mexe na escada:
 *
 *  - sai a tempo com folga (estoque acaba antes de 70% do prazo): mantem;
 *  - sai a tempo, mas no aperto: um degrau mais leve;
 *  - nao sai a tempo: o degrau da data;
 *  - nao sai nem na metade (ou nao vendeu nada no mes): um degrau mais fundo.
 *
 * Sem dado de venda, fica a escada pura, e a tela diz isso.
 *
 * E ha um piso: a taxa nunca desce do fator de prejuizo. Abaixo dele cada
 * venda tira dinheiro do bolso — ainda pode valer mais que jogar fora, mas
 * essa decisao e de quem esta olhando o produto, e o Repor deixa digitar.
 *
 * @param array $d dias, estoque, venda_dia (null = sem dado), custo, preco,
 *                 prejuizo (fator de corte, de margens_minimos)
 * @return array{acao:string, taxa:?float, preco:?float, dias_para_vender:?float,
 *               motivos:array<int,string>, opcoes:array}
 */
function promocao_sugerir(array $d): array
{
    $dias      = $d['dias'] ?? null;
    $estoque   = (float) ($d['estoque'] ?? 0);
    $venda_dia = isset($d['venda_dia']) ? (float) $d['venda_dia'] : null;
    $custo     = isset($d['custo']) && (float) $d['custo'] > 0 ? (float) $d['custo'] : null;
    $preco     = isset($d['preco']) && (float) $d['preco'] > 0 ? (float) $d['preco'] : null;
    $piso      = promocao_piso(isset($d['prejuizo']) ? (float) $d['prejuizo'] : null);

    $saida = ['acao' => 'manter', 'taxa' => null, 'preco' => null,
              'dias_para_vender' => null, 'motivos' => [], 'opcoes' => []];

    if ($dias === null) {
        return ['acao' => 'sem_validade', 'motivos' => ['Este item não tem validade cadastrada.']] + $saida;
    }
    if ($dias > 3650) {
        return ['acao' => 'suspeita',
                'motivos' => ['A validade está a mais de dez anos daqui — quase certo que é erro de digitação. '
                            . 'Confira a data na embalagem e corrija no Repor.']] + $saida;
    }
    if ($dias < 0) {
        return ['acao' => 'retirar',
                'motivos' => ['Produto vencido não pode ser vendido, nem em promoção (Código de Defesa do '
                            . 'Consumidor, art. 18, § 6º). Tire da prateleira e zere o estoque no Repor.']] + $saida;
    }
    if ($estoque <= 0) {
        return ['acao' => 'sem_estoque',
                'motivos' => ['Não há estoque deste item: não tem o que pôr em promoção.']] + $saida;
    }

    $taxa = promocao_taxa_por_dias($dias);
    $motivos = [];

    // ---- o ritmo de venda mexe na escada ----
    if ($venda_dia === null) {
        $motivos[] = 'Sem vendas registradas neste ponto de venda para medir o ritmo: a sugestão vai só pela data.';
    } elseif ($venda_dia <= 0) {
        // Nao vendeu nada no mes: no preco de hoje nao sai nunca, entao ate
        // quem tem dois meses pela frente ja merece o primeiro degrau.
        $taxa = ($taxa ?? ($dias <= 90 ? 1.8 : null));
        if ($taxa !== null) {
            $taxa = round($taxa - 0.1, 2);
        }
        $motivos[] = 'Não vendeu nenhuma unidade nos últimos 30 dias: no preço de hoje ele não sai.';
    } else {
        $para_vender = $estoque / $venda_dia;
        $saida['dias_para_vender'] = $para_vender;
        $ritmo = 'Vende ' . qtd_fmt(round($venda_dia, 1)) . ' por dia: as ' . qtd_fmt($estoque)
               . ' unidades acabam em ' . promocao_dias_texto($para_vender) . '.';

        if ($para_vender <= $dias * 0.7) {
            $motivos[] = $ritmo . ' Sai antes de vencer, com folga.';
            $taxa = null;
        } elseif ($para_vender <= $dias) {
            $motivos[] = $ritmo . ' Sai a tempo, mas no aperto.';
            $taxa = $taxa === null ? null : round($taxa + 0.1, 2);
        } elseif ($para_vender > $dias * 2) {
            $motivos[] = $ritmo . ' Não sai nem a metade antes de vencer.';
            $taxa = round(($taxa ?? 1.8) - 0.1, 2);
        } else {
            $motivos[] = $ritmo . ' Não sai tudo antes de vencer.';
            $taxa = $taxa ?? 1.8;
        }
    }

    // ---- o piso ----
    if ($taxa !== null && $piso !== null && $taxa < $piso) {
        $taxa = $piso;
        $motivos[] = 'A taxa parou no piso de ' . number_format($piso, 2, ',', '.')
                   . '×: abaixo disso cada venda dá prejuízo depois da maquininha, do condomínio e da franquia.';
    }

    // ---- o preco de hoje ja e promocao? ----
    // Sugestao que AUMENTA o preco nao e promocao. Quem ja esta vendendo a
    // 1,5x nao precisa ouvir "ponha a 1,6x".
    if ($taxa !== null && $custo !== null && $preco !== null && $preco <= round($custo * $taxa, 2) + 0.004) {
        $motivos[] = 'O preço de hoje (' . moeda($preco) . ', '
                   . number_format($preco / $custo, 2, ',', '.') . '×) já está nessa faixa ou abaixo.';
        $taxa = null;
    }

    if ($taxa === null) {
        if ($dias > 60 && !$motivos) {
            $motivos[] = 'Faltam mais de dois meses: ainda não pede promoção.';
        }
        $saida['motivos'] = $motivos;
        $saida['opcoes']  = promocao_opcoes($custo, $preco, $piso);
        return $saida;
    }

    if ($custo === null) {
        $motivos[] = 'Sem custo de nota para este produto: digite o custo no Repor e a taxa faz o preço.';
    }

    return [
        'acao'     => 'promocao',
        'taxa'     => $taxa,
        'preco'    => $custo === null ? null : round($custo * $taxa, 2),
        'motivos'  => $motivos,
        'opcoes'   => promocao_opcoes($custo, $preco, $piso),
    ] + $saida;
}

/**
 * As outras taxas, para quem quiser ir mais fundo ou mais leve. Funcao pura.
 *
 * Cada uma ja vem com o preco que daria e dois avisos: abaixo do piso (da
 * prejuizo) e "nao baixa" (o preco nao ficaria menor que o de hoje — nao e
 * promocao).
 *
 * @return array<int, array{taxa:float, preco:?float, abaixo_piso:bool, nao_baixa:bool}>
 */
function promocao_opcoes(?float $custo, ?float $preco, ?float $piso): array
{
    $saida = [];
    foreach ([1.4, 1.5, 1.6, 1.7, 1.8] as $t) {
        $p = $custo === null ? null : round($custo * $t, 2);
        $saida[] = [
            'taxa'        => $t,
            'preco'       => $p,
            'abaixo_piso' => $piso !== null && $t < $piso,
            'nao_baixa'   => $p !== null && $preco !== null && $p >= $preco,
        ];
    }
    return $saida;
}

/** "3 dias", "2 semanas", "4 meses" — do jeito que se fala. Funcao pura. */
function promocao_dias_texto(float $dias): string
{
    $d = (int) ceil($dias);
    if ($d <= 1) {
        return '1 dia';
    }
    if ($d < 14) {
        return $d . ' dias';
    }
    if ($d < 60) {
        return (int) round($d / 7) . ' semanas';
    }
    return (int) round($d / 30) . ' meses';
}

/**
 * O link para o Repor, ja com tudo preenchido. Funcao pura.
 *
 * Vai custo e taxa, nao o preco: no Repor o preco nasce da conta, e a linha
 * "R$ 2,25 x 1,6 = R$ 3,60" aparece por extenso — e a conferencia que pega o
 * custo errado antes de ele virar etiqueta.
 */
function promocao_link_repor(int $pdv_id, string $codigo, ?float $custo, ?float $taxa): string
{
    $q = ['pdv' => $pdv_id, 'codigo' => $codigo];
    if ($custo !== null) {
        $q['custo'] = number_format($custo, 2, ',', '');
    }
    if ($taxa !== null) {
        $q['taxa']  = number_format($taxa, 2, ',', '');
        $q['promo'] = 1;
    }
    return '/planograma?' . http_build_query($q);
}

// ---------------------------------------------------------------------
// O que fala com o banco
// ---------------------------------------------------------------------

/** Um item do espelho, com o PDV, so se o PDV estiver ligado. */
function promocao_item(int $item_id): ?array
{
    return q1(
        'SELECT li.*, p.nome AS pdv, p.id AS pdv_id, p.externo_id AS pdv_externo
           FROM loja_itens li
           JOIN loja_pdvs p ON p.id = li.pdv_id
          WHERE li.id = ? AND p.ativo = 1 AND p.unificado_para IS NULL',
        [$item_id]
    );
}

/**
 * Quanto deste item sai por dia, naquele ponto de venda.
 *
 * Os ultimos 30 dias, e so venda que valeu. Volta null quando o PDV nao tem
 * venda nenhuma na janela: ai o problema e o sync das vendas, nao o produto,
 * e "vende zero por dia" seria mentira.
 */
function promocao_venda_dia(array $item, int $janela = 30): ?float
{
    $desde = date('Y-m-d 00:00:00', strtotime('-' . $janela . ' days'));
    $pdv   = (int) $item['pdv_id'];

    $tem_vendas = (int) qv(
        'SELECT COUNT(*) FROM vendas v
          WHERE v.pdv_id = ? AND v.data_hora >= ? AND (v.resultado = ? OR v.resultado IS NULL)',
        [$pdv, $desde, 'Ok']
    );
    if ($tem_vendas === 0) {
        return null;
    }

    $qtd = (float) qv(
        'SELECT COALESCE(SUM(vi.quantidade), 0)
           FROM venda_itens vi
           JOIN vendas v ON v.id = vi.venda_id
          WHERE v.pdv_id = ? AND v.data_hora >= ? AND (v.resultado = ? OR v.resultado IS NULL)
            AND (vi.externo_produto_id = ? OR vi.ean = ?)',
        [$pdv, $desde, 'Ok',
         (int) ($item['externo_produto_id'] ?? 0) ?: -1,
         $item['ean'] ?: '__nada__']
    );
    return $qtd / $janela;
}

/**
 * O custo de uma unidade deste item, pelas notas.
 *
 * O das unidades que estao na prateleira (produto_custo_estoque), e so na
 * falta dele o da ultima nota. O TouchPay nao serve aqui: o custo de la vem
 * sempre zero.
 *
 * @return array{custo:?float, fonte:?string, produto_id:?int}
 */
function promocao_custo(array $item, int $usuario_id): array
{
    $pid = (int) ($item['produto_id'] ?? 0);
    if ($pid <= 0 && !empty($item['ean'])) {
        $p   = produto_por_ean((string) $item['ean']);
        $pid = $p ? (int) $p['id'] : 0;
    }
    if ($pid <= 0) {
        return ['custo' => null, 'fonte' => null, 'produto_id' => null];
    }

    $hist = produto_historico($pid, $usuario_id);
    $ce   = produto_custo_estoque($hist, (float) $item['estoque']);
    if ($ce['custo'] !== null) {
        return ['custo' => (float) $ce['custo'], 'fonte' => 'estoque', 'produto_id' => $pid];
    }
    $st = produto_estatisticas($hist);
    if (($st['n'] ?? 0) > 0) {
        return ['custo' => (float) $st['ultimo'], 'fonte' => 'ultima', 'produto_id' => $pid];
    }
    return ['custo' => null, 'fonte' => null, 'produto_id' => $pid];
}

/** Tudo que a tela precisa, num lugar so. */
function promocao_dados(int $item_id, int $usuario_id): ?array
{
    $item = promocao_item($item_id);
    if (!$item) {
        return null;
    }
    $d = promocao_analisar($item, $usuario_id, margens_minimos());
    $d['ativas'] = promocoes_do_item($item);
    return $d;
}

/**
 * A analise de um item, com os cortes ja calculados por quem chama.
 *
 * Separada de promocao_dados() porque a lista de sugestoes passa por isto
 * item a item, e calcular margens_minimos() (que soma o faturamento do mes)
 * uma vez por item seria a mesma conta repetida dezenas de vezes.
 */
function promocao_analisar(array $item, int $usuario_id, array $minimos): array
{
    $val     = validade_estado($item['validade'] ?? null);
    $custo   = promocao_custo($item, $usuario_id);
    $vdia    = promocao_venda_dia($item);
    $preco   = $item['preco_venda'] === null ? null : (float) $item['preco_venda'];

    $sug = promocao_sugerir([
        'dias'      => $val['dias'],
        'estoque'   => (float) $item['estoque'],
        'venda_dia' => $vdia,
        'custo'     => $custo['custo'],
        'preco'     => $preco,
        'prejuizo'  => $minimos['prejuizo'],
    ]);

    $codigo = (string) ($item['ean'] ?: pg_codigo_limpo($item['codigo'] ?? ''));

    return [
        'item'       => $item,
        'validade'   => $val,
        'custo'      => $custo,
        'venda_dia'  => $vdia,
        'preco'      => $preco,
        'minimos'    => $minimos,
        'sugestao'   => $sug,
        'codigo'     => $codigo,
        'percentual' => $preco !== null ? promocao_percentual($preco, $sug['preco']) : null,
    ];
}

// ---------------------------------------------------------------------
// A promocao do TouchPay (desconto com comeco e fim)
// ---------------------------------------------------------------------
//
// A sugestao fala em taxa sobre o custo, que e como o preco e pensado aqui.
// O TouchPay fala em PERCENTUAL sobre o preco de venda, que e como o
// desconto dele funciona. As funcoes abaixo traduzem uma lingua na outra.

/**
 * Quantos por cento tirar do preco de hoje para chegar no preco alvo. Funcao pura.
 *
 * Inteiro e arredondado PARA BAIXO: desconto de 26,7% vira 26%, e o preco
 * final fica um pouco ACIMA do alvo, nunca abaixo — o alvo ja vem respeitando
 * o piso de prejuizo, e arredondar para cima poderia atravessa-lo. Inteiro
 * porque e o que o painel deles mostra, e nao vale descobrir na pratica se o
 * servidor aceita casa decimal.
 *
 * Null quando o alvo nao baixa o preco (nao e promocao) ou falta numero.
 */
function promocao_percentual(?float $preco_hoje, ?float $preco_alvo): ?int
{
    if ($preco_hoje === null || $preco_hoje <= 0 || $preco_alvo === null || $preco_alvo <= 0) {
        return null;
    }
    $pct = (int) floor((1 - $preco_alvo / $preco_hoje) * 100 + 1e-9);
    return $pct >= 1 ? min($pct, 90) : null;
}

/** O preco no caixa com o desconto. Funcao pura. */
function promocao_preco_com(float $preco, float $percentual): float
{
    return round($preco * (1 - $percentual / 100), 2);
}

/** Em que pe esta uma promocao, pelas datas. Funcao pura. */
function promocao_status(string $inicio, string $fim, ?string $hoje = null): string
{
    $hoje = $hoje ?? date('Y-m-d');
    if ($fim < $hoje) {
        return 'encerrada';
    }
    return $inicio > $hoje ? 'agendada' : 'no ar';
}

/**
 * O que a pessoa pediu esta em condicoes de virar promocao? Funcao pura.
 *
 * Cada recusa aqui e um erro que custaria caro do lado de la: desconto de
 * 0% (nao e promocao), de 95% (o dedo escorregou), comecando ontem, ou que
 * dura um ano — promocao de vencimento que sobrevive ao produto.
 *
 * @return ?string a queixa, ou null quando esta tudo certo
 */
function promocao_validar(?float $percentual, ?string $inicio, ?string $fim, ?string $hoje = null): ?string
{
    $hoje = $hoje ?? date('Y-m-d');
    if ($percentual === null || $percentual < 1) {
        return 'Digite um desconto de pelo menos 1%.';
    }
    if ($percentual > 90) {
        return 'Desconto acima de 90% — confira o número.';
    }
    if (abs($percentual - round($percentual)) > 0.0001) {
        return 'O desconto vai em número inteiro (ex.: 25%).';
    }
    if ($inicio === null || $fim === null) {
        return 'Faltou a data de começo ou de fim.';
    }
    if ($inicio < $hoje) {
        return 'A promoção não pode começar no passado.';
    }
    if ($fim < $inicio) {
        return 'O fim vem antes do começo.';
    }
    if ((strtotime($fim) - strtotime($inicio)) / 86400 > 120) {
        return 'Mais de 120 dias de promoção — confira as datas.';
    }
    return null;
}

/**
 * O nome que vai para o TouchPay. Funcao pura.
 *
 * Neutro de proposito: nao se sabe onde o painel deles mostra isto, e
 * "vence dia 8" numa tela de cliente nao e o que se quer anunciar.
 */
function promocao_nome(string $descricao): string
{
    $d = trim(preg_replace('/\s+/', ' ', $descricao));
    return mb_substr('Promoção ' . ($d !== '' ? $d : 'do dia'), 0, 60);
}

/**
 * O corpo do POST /api/discountProducts. Funcao pura.
 *
 * Campo a campo o que o painel deles mandou na captura de 03/10/2026, so com
 * os valores trocados: `quantity: 1` e o desconto valer a partir de uma
 * unidade; os `id: 0` sao "regra nova".
 */
function promocao_corpo(int $produto_externo, int $pdv_externo, int $percentual,
                        string $inicio, string $fim, string $nome): array
{
    return [
        'type'                      => 'Percentage',
        'startsOn'                  => $inicio,
        'expiresOn'                 => $fim,
        'description'               => $nome,
        'discountProductRules'      => [[
            'id' => 0, 'productId' => (string) $produto_externo, 'quantity' => 1, 'amount' => $percentual,
        ]],
        'discountPointOfSaleRules'  => [[
            'id' => 0, 'pointOfSaleId' => $pdv_externo, 'discountBaseId' => 0,
        ]],
        'usage'                     => 0,
        'category'                  => 'Product',
    ];
}

// ---------------------------------------------------------------------
// O que esta no TouchPay
// ---------------------------------------------------------------------
//
// A lista de la e a verdade: inclui o que foi criado direto no painel, e o
// que alguem desligou por la. A tabela `promocoes` daqui so sabe o que saiu
// do app — serve de diario e de reserva quando o painel nao responde.

/**
 * A lista de promocoes do TouchPay, uma linha por produto x ponto de venda.
 * Funcao pura.
 *
 * Uma promocao deles pode cobrir varios produtos e varios PDVs; achatar em
 * pares e o que deixa responder "este produto, neste container, esta em
 * promocao?" sem laco dentro de laco em cada tela. Resposta conferida em
 * 03/10/2026:
 *
 *   {id, type, startsOn: "2026-10-03T00:00:00", expiresOn, isValid,
 *    description, discountPointOfSaleRules: [{pointOfSaleId, localName}],
 *    discountProductRules: [{productId: "7467", productCode,
 *                            productDescription, productDefaultPrice, amount}]}
 */
function promocoes_tp_normalizar(array $itens): array
{
    $saida = [];
    foreach ($itens as $d) {
        if (!is_array($d)) {
            continue;
        }
        $inicio = pg_data_iso($d['startsOn'] ?? null);
        $fim    = pg_data_iso($d['expiresOn'] ?? null);
        if ($inicio === null || $fim === null) {
            continue;
        }
        $tipo = (string) ($d['type'] ?? '');
        foreach ((array) ($d['discountProductRules'] ?? []) as $pr) {
            foreach ((array) ($d['discountPointOfSaleRules'] ?? []) as $pv) {
                $valor = (float) ($pr['amount'] ?? 0);
                $saida[] = [
                    'externo_id'      => (int) ($d['id'] ?? 0),
                    'tipo'            => $tipo,
                    'inicio'          => $inicio,
                    'fim'             => $fim,
                    'descricao'       => (string) ($d['description'] ?? ''),
                    // Ausente conta como valida: so o "false" explicito desliga.
                    'valido'          => ($d['isValid'] ?? true) !== false,
                    'pdv_externo'     => (int) ($pv['pointOfSaleId'] ?? 0),
                    'pdv_nome'        => (string) ($pv['localName'] ?? ''),
                    'produto_externo' => (int) ($pr['productId'] ?? 0),
                    'codigo'          => pg_codigo_limpo($pr['productCode'] ?? ''),
                    'produto'         => (string) ($pr['productDescription'] ?? ''),
                    'preco_padrao'    => isset($pr['productDefaultPrice']) ? (float) $pr['productDefaultPrice'] : null,
                    'valor'           => $valor,
                    'desconto'        => promocao_desconto_texto($tipo, $valor),
                ];
            }
        }
    }
    return $saida;
}

/**
 * "−29%", ou "−R$ 1,50" quando o desconto e em dinheiro. Funcao pura.
 *
 * So o tipo percentual foi visto ate agora. O resto e escrito em reais
 * porque o painel deles tem desconto em valor; o que nao se reconhece fica
 * com o numero cru, para ninguem ler 29 reais onde eram 29 de outra coisa.
 */
function promocao_desconto_texto(string $tipo, float $valor): string
{
    $n = rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',');
    if (strcasecmp($tipo, 'Percentage') === 0) {
        return '−' . $n . '%';
    }
    if (stripos($tipo, 'value') !== false || stripos($tipo, 'amount') !== false) {
        return '−' . moeda($valor);
    }
    return $tipo . ' ' . $n;
}

/** O preco no caixa com este desconto, quando da para saber. Funcao pura. */
function promocao_preco_final(string $tipo, ?float $preco, float $valor): ?float
{
    if ($preco === null || $preco <= 0) {
        return null;
    }
    if (strcasecmp($tipo, 'Percentage') === 0) {
        return promocao_preco_com($preco, $valor);
    }
    if (stripos($tipo, 'value') !== false || stripos($tipo, 'amount') !== false) {
        return max(0.0, round($preco - $valor, 2));
    }
    return null;
}

/** As que ainda valem: ligadas e que nao acabaram. Funcao pura. */
function promocoes_valendo(array $linhas, ?string $hoje = null): array
{
    $hoje = $hoje ?? date('Y-m-d');
    return array_values(array_filter($linhas,
        static fn (array $l): bool => $l['valido'] && $l['fim'] >= $hoje));
}

/**
 * As promocoes deste produto, neste PDV, que cruzam o periodo pedido. Funcao pura.
 *
 * Desligada nao conta: o que alguem desligou no painel nao briga com nada.
 */
function promocoes_que_cruzam(array $linhas, int $pdv_externo, int $produto_externo,
                              string $inicio, string $fim): array
{
    return array_values(array_filter($linhas, static fn (array $l): bool =>
        $l['valido']
        && $l['pdv_externo'] === $pdv_externo
        && $l['produto_externo'] === $produto_externo
        && $l['inicio'] <= $fim && $l['fim'] >= $inicio));
}

/**
 * A lista do TouchPay, ja achatada — ou null quando o painel nao respondeu.
 *
 * Uma ida por requisicao: a aba Promocoes pergunta por item, e a lista e a
 * mesma para todos.
 */
function promocoes_touchpay(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    try {
        return $cache = promocoes_tp_normalizar(tp_promocoes());
    } catch (TouchPayErro $e) {
        error_log('promocoes_touchpay: ' . $e->getMessage());
        return $cache = null;
    }
}

/**
 * As promocoes que ainda valem para este item.
 *
 * Do TouchPay quando ele responde; senao, as que o app criou — melhor avisar
 * pela metade do que dizer "nenhuma" com o painel fora do ar.
 */
function promocoes_do_item(array $item): array
{
    $produto = (int) ($item['externo_produto_id'] ?? 0);
    $pdv_ext = (int) ($item['pdv_externo'] ?? 0);
    if ($produto <= 0) {
        return [];
    }
    $tp = promocoes_touchpay();
    if ($tp !== null) {
        return array_values(array_filter(promocoes_valendo($tp),
            static fn (array $l): bool => $l['pdv_externo'] === $pdv_ext && $l['produto_externo'] === $produto));
    }
    try {
        $locais = q(
            'SELECT * FROM promocoes
              WHERE pdv_id = ? AND produto_externo_id = ? AND ok = 1 AND fim >= ?
           ORDER BY inicio',
            [(int) $item['pdv_id'], $produto, date('Y-m-d')]
        );
    } catch (Throwable $e) {
        // Tabela ainda nao criada pelo setup: a tela segue, so sem o aviso.
        error_log('promocoes_do_item: ' . $e->getMessage());
        return [];
    }
    return array_map(static fn (array $p): array => [
        'externo_id' => (int) ($p['externo_id'] ?? 0), 'tipo' => 'Percentage',
        'inicio' => $p['inicio'], 'fim' => $p['fim'], 'valido' => true,
        'valor' => (float) $p['percentual'], 'desconto' => promocao_desconto_texto('Percentage', (float) $p['percentual']),
    ], $locais);
}

/**
 * Cada linha do TouchPay com o que o app sabe dela: o nome do PDV daqui, o
 * item do espelho (para virar link e mostrar o preco do planograma) e se fui
 * eu que criei.
 */
function promocoes_enriquecer(array $linhas): array
{
    if (!$linhas) {
        return [];
    }
    $pdvs = [];
    foreach (q('SELECT id, nome, externo_id FROM loja_pdvs WHERE unificado_para IS NULL') as $p) {
        $pdvs[(int) $p['externo_id']] = $p;
    }

    $itens = [];
    foreach (q('SELECT li.id, li.externo_produto_id, li.preco_venda, li.validade, li.estoque, p.externo_id AS pdv_externo
                  FROM loja_itens li JOIN loja_pdvs p ON p.id = li.pdv_id
                 WHERE li.externo_produto_id IS NOT NULL AND p.unificado_para IS NULL') as $i) {
        $itens[(int) $i['pdv_externo'] . ':' . (int) $i['externo_produto_id']] = $i;
    }

    $doApp = [];
    try {
        foreach (q('SELECT externo_id FROM promocoes WHERE ok = 1 AND externo_id IS NOT NULL') as $p) {
            $doApp[(int) $p['externo_id']] = true;
        }
    } catch (Throwable $e) {
        error_log('promocoes_enriquecer: ' . $e->getMessage());
    }

    foreach ($linhas as &$l) {
        $item = $itens[$l['pdv_externo'] . ':' . $l['produto_externo']] ?? null;
        $l['pdv']       = $pdvs[$l['pdv_externo']]['nome'] ?? ($l['pdv_nome'] ?: ('PDV ' . $l['pdv_externo']));
        $l['item_id']   = $item ? (int) $item['id'] : null;
        $l['validade']  = $item['validade'] ?? null;
        // O preco do planograma e o que o caixa cobra; o "padrao" do
        // cadastro deles pode ser outro. Sem planograma, fica o padrao.
        $l['preco']     = $item && $item['preco_venda'] !== null ? (float) $item['preco_venda'] : $l['preco_padrao'];
        $l['preco_com'] = promocao_preco_final($l['tipo'], $l['preco'], $l['valor']);
        $l['do_app']    = isset($doApp[$l['externo_id']]);
    }
    unset($l);
    return $linhas;
}

/**
 * Cria a promocao no TouchPay.
 *
 * Mesmas tres ideias do Repor: reler antes de gravar, parar em vez de
 * atropelar, e registrar o que saiu daqui.
 *
 *  - O preco base e relido do planograma NA HORA: o desconto incide sobre o
 *    preco de la, e se ele mudou desde que a tela abriu, o "de R$ 4,29 por
 *    R$ 3,13" que a pessoa confirmou ja nao e verdade (409).
 *  - Promocao do mesmo produto e PDV com datas que se cruzam — criada por
 *    aqui ou direto no painel — para a criacao: duas promocoes empilhadas e
 *    desconto em cima de desconto, e ninguem sabe o que o caixa deles faz com
 *    isso. A lista e relida do TouchPay na hora, nao do cache da tela.
 *
 * @param array $dados item, percentual, inicio, fim, visto_preco
 * @return array{ok:bool, conflito?:bool, erro?:string, promocao?:array}
 */
function promocao_criar(array $u, array $dados): array
{
    $item = promocao_item((int) ($dados['item'] ?? 0));
    if (!$item) {
        return ['ok' => false, 'erro' => 'Item não encontrado na loja.'];
    }
    $produto = (int) ($item['externo_produto_id'] ?? 0);
    if ($produto <= 0) {
        return ['ok' => false, 'erro' => 'Este item não tem o código do produto no TouchPay. Puxe a loja de novo.'];
    }

    $pct    = pg_numero($dados['percentual'] ?? null);
    $inicio = pg_data_iso($dados['inicio'] ?? null);
    $fim    = pg_data_iso($dados['fim'] ?? null);
    $queixa = promocao_validar($pct, $inicio, $fim);
    if ($queixa !== null) {
        return ['ok' => false, 'erro' => $queixa];
    }
    $pct = (int) round($pct);

    $pdv = planograma_pdv((int) $item['pdv_id']);
    if (!$pdv) {
        return ['ok' => false, 'erro' => 'Ponto de venda desconhecido.'];
    }

    // Relida agora, e se o painel nao responder a criacao para: sem a lista
    // nao da para saber se ja existe promocao, e criar no escuro e o caso
    // que esta checagem existe para evitar.
    $cruzam = promocoes_que_cruzam(promocoes_tp_normalizar(tp_promocoes()),
                                   (int) $pdv['externo_id'], $produto, $inicio, $fim);
    if ($cruzam) {
        $p = $cruzam[0];
        return ['ok' => false, 'erro' => 'Já existe promoção deste produto neste ponto de venda ('
            . $p['desconto'] . ', de ' . pg_data_br($p['inicio']) . ' a ' . pg_data_br($p['fim'])
            . '). Ajuste as datas ou encerre a outra no painel.'];
    }

    // O preco de AGORA, do planograma — e sobre ele que o desconto incide.
    $ean   = (string) ($item['ean'] ?? '');
    $linha = pg_casar(tp_planograma_buscar(planograma_ativo($pdv), $ean !== '' ? $ean : (string) $produto),
                      $produto, $ean);
    if (!$linha || (float) ($linha['price'] ?? 0) <= 0) {
        return ['ok' => false, 'erro' => 'O produto não está no planograma deste ponto de venda: sem preço, não há sobre o que dar desconto.'];
    }
    $preco = (float) $linha['price'];

    $visto = pg_numero($dados['visto_preco'] ?? null);
    if ($visto !== null && abs($visto - $preco) > 0.009) {
        return ['ok' => false, 'conflito' => true,
                'erro' => 'O preço mudou para ' . moeda($preco) . ' desde que a tela abriu. Nada foi criado; confira de novo.'];
    }

    $linhaLocal = [
        'usuario_id'         => (int) ($u['id'] ?? 0) ?: null,
        'pdv_id'             => (int) $pdv['id'],
        'produto_externo_id' => $produto,
        'ean'                => $ean !== '' ? mb_substr($ean, 0, 14) : null,
        'descricao'          => mb_substr((string) $item['descricao'], 0, 255),
        'preco_base'         => $preco,
        'percentual'         => $pct,
        'preco_promo'        => promocao_preco_com($preco, $pct),
        'inicio'             => $inicio,
        'fim'                => $fim,
        'validade'           => $item['validade'] ?: null,
        'criado_em'          => date('Y-m-d H:i:s'),
    ];

    $corpo = promocao_corpo($produto, (int) $pdv['externo_id'], $pct, $inicio, $fim,
                            promocao_nome((string) $item['descricao']));
    try {
        $r = tp_promocao_criar($corpo);
    } catch (TouchPayErro $e) {
        promocao_registrar($linhaLocal + ['ok' => 0, 'erro' => mb_substr($e->getMessage(), 0, 255)]);
        throw $e;
    }

    $externo = is_array($r) && is_numeric($r['id'] ?? null) ? (int) $r['id'] : null;
    $linhaLocal += ['ok' => 1, 'externo_id' => $externo];
    promocao_registrar($linhaLocal);

    return ['ok' => true, 'promocao' => $linhaLocal];
}

/** Uma linha em promocoes. Nunca derruba a criacao: ela ja aconteceu la. */
function promocao_registrar(array $linha): void
{
    try {
        inserir('promocoes', $linha);
    } catch (Throwable $e) {
        error_log('promocoes: ' . $e->getMessage());
    }
}

/**
 * As promocoes que o app criou, das mais novas para as mais velhas.
 *
 * @param bool $so_recusadas so as que o TouchPay recusou — com a lista de la
 *                           respondendo, sao as unicas que ela nao mostra
 */
function promocoes_locais(int $limite = 60, bool $so_recusadas = false): array
{
    try {
        return q(
            'SELECT pr.*, p.nome AS pdv, u.nome AS usuario
               FROM promocoes pr
          LEFT JOIN loja_pdvs p ON p.id = pr.pdv_id
          LEFT JOIN usuarios  u ON u.id = pr.usuario_id
              WHERE ' . ($so_recusadas ? 'pr.ok = 0 AND pr.criado_em >= ?' : '1 = 1') . '
           ORDER BY pr.id DESC
              LIMIT ' . max(1, min(200, $limite)),
            $so_recusadas ? [date('Y-m-d 00:00:00', strtotime('-7 days'))] : []
        );
    } catch (Throwable $e) {
        error_log('promocoes_locais: ' . $e->getMessage());
        return [];
    }
}

/**
 * Os itens com validade nos proximos 60 dias (ou ja vencidos) e estoque,
 * cada um ja analisado.
 *
 * E a lista de trabalho da aba Promocoes: o que pede promocao agora e o que
 * tem de sair da prateleira. Item que ja tem promocao valendo — criada por
 * aqui ou no painel — sai da lista de sugestoes: ja foi resolvido.
 *
 * @return array{sugeridas:array, retirar:array}
 */
function promocoes_sugeridas(int $usuario_id): array
{
    $itens = q(
        'SELECT li.*, p.nome AS pdv, p.id AS pdv_id, p.externo_id AS pdv_externo
           FROM loja_itens li
           JOIN loja_pdvs p ON p.id = li.pdv_id
          WHERE p.ativo = 1 AND p.unificado_para IS NULL
            AND li.validade IS NOT NULL AND li.validade <= ?
            AND li.estoque > 0
       ORDER BY li.validade, li.descricao
          LIMIT 300',
        [date('Y-m-d', strtotime('+60 days'))]
    );

    $minimos   = margens_minimos();
    $sugeridas = [];
    $retirar   = [];
    foreach ($itens as $item) {
        $a = promocao_analisar($item, $usuario_id, $minimos);
        if ($a['sugestao']['acao'] === 'retirar') {
            $retirar[] = $a;
        } elseif ($a['sugestao']['acao'] === 'promocao' && !promocoes_do_item($item)) {
            $sugeridas[] = $a;
        }
    }
    return ['sugeridas' => $sugeridas, 'retirar' => $retirar];
}
