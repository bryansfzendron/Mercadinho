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
 * Repor —, porque e assim que o preco e pensado aqui. E nada e gravado nesta
 * tela: o botao leva ao Repor com custo e taxa preenchidos, e la o preco
 * passa pelo de/para e pelo segundo toque como qualquer outro.
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
        'SELECT li.*, p.nome AS pdv, p.id AS pdv_id
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

    $val     = validade_estado($item['validade'] ?? null);
    $custo   = promocao_custo($item, $usuario_id);
    $vdia    = promocao_venda_dia($item);
    $minimos = margens_minimos();
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
        'item'      => $item,
        'validade'  => $val,
        'custo'     => $custo,
        'venda_dia' => $vdia,
        'preco'     => $preco,
        'minimos'   => $minimos,
        'sugestao'  => $sug,
        'codigo'    => $codigo,
    ];
}
