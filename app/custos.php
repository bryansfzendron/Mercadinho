<?php
declare(strict_types=1);

/**
 * O que sobra da venda.
 *
 * Tres camadas, porque elas se comportam de forma diferente:
 *
 *  1. **Custo da mercadoria (CMV)** — por produto. Sai da NFC-e: o ultimo
 *     valor unitario liquido que voce pagou naquele produto. Quando o produto
 *     ainda nao tem nota, cai no percentual padrao (o seu palpite de 55%).
 *  2. **Percentuais sobre o faturamento** — condominio, franquia e a taxa da
 *     maquininha. A taxa depende da forma de pagamento, que vem em cada
 *     transacao, entao ela e calculada venda a venda e nao por media chutada.
 *  3. **Custos fixos do mes** — energia e sistema. Nao se dividem por produto
 *     sem inventar rateio, entao entram so no resultado do periodo, rateados
 *     por dia.
 */

/** Valores iniciais. Os do TouchPay/PagBank sao os de tabela — confira no app. */
function custos_padrao(): array
{
    return [
        // Percentual do faturamento bruto.
        'condominio_pct'  => 5.0,
        'franquia_pct'    => 5.0,
        // Taxa da maquininha por forma de pagamento (Moderninha Smart 2).
        'taxa_debito'     => 1.23,
        'taxa_credito'    => 2.92,
        'taxa_pix'        => 0.35,
        'taxa_voucher'    => 3.50,
        // Custo da mercadoria para produto sem nota fiscal ainda.
        'cmv_padrao_pct'  => 55.0,
        // Fixos do mes inteiro, em reais.
        'fixo_energia'    => 300.0,
        'fixo_sistema'    => 149.0,
    ];
}

/** Parametros gravados, completados com os padroes. */
function custos_parametros(): array
{
    $valores = custos_padrao() + metas_padrao();
    foreach (q('SELECT chave, valor FROM custos_parametros') as $linha) {
        $chave = (string) $linha['chave'];
        if (array_key_exists($chave, $valores)) {
            $valores[$chave] = (float) $linha['valor'];
        }
    }
    return $valores;
}

/**
 * Grava so os parametros conhecidos, para a tabela nao virar depositario de
 * lixo. As metas moram na mesma tabela: do ponto de vista do banco sao a
 * mesma coisa, um numero que o dono ajusta na tela.
 */
function custos_salvar(array $novos): int
{
    $conhecidos = custos_padrao() + metas_padrao();
    $agora = date('Y-m-d H:i:s');
    $n = 0;
    foreach ($novos as $chave => $valor) {
        if (!array_key_exists($chave, $conhecidos)) {
            continue;
        }
        $v = max(0.0, num_br($valor));
        exec_sql(
            'INSERT INTO custos_parametros (chave, valor, atualizado_em) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = VALUES(atualizado_em)',
            [$chave, $v, $agora]
        );
        $n++;
    }
    return $n;
}

/** A taxa da maquininha daquela forma de pagamento, em percentual. */
function custo_taxa_da_forma(?string $forma, array $p): float
{
    switch (strtolower((string) $forma)) {
        case 'debit':   return (float) $p['taxa_debito'];
        case 'credit':  return (float) $p['taxa_credito'];
        case 'pix':     return (float) $p['taxa_pix'];
        case 'voucher': return (float) $p['taxa_voucher'];
        // Forma desconhecida nao inventa taxa: melhor faltar custo do que
        // aparecer um numero que ninguem sabe de onde veio.
        default:        return 0.0;
    }
}

/**
 * O resultado do periodo.
 *
 * @param array $por_forma  [['forma' => 'Debit', 'total' => 5975.27], ...]
 * @param float $cmv        custo da mercadoria ja somado (NFC-e + padrao)
 * @param int   $dias       dias do periodo, para ratear os fixos do mes
 * @param array $p          parametros de custo
 */
function custos_resultado(array $por_forma, float $cmv, int $dias, array $p): array
{
    $receita = 0.0;
    $taxa = 0.0;
    $vendas = 0;
    foreach ($por_forma as $linha) {
        $total = (float) ($linha['total'] ?? 0);
        $receita += $total;
        $vendas += (int) ($linha['n'] ?? 0);
        $taxa += $total * custo_taxa_da_forma($linha['forma'] ?? null, $p) / 100;
    }

    $condominio = $receita * (float) $p['condominio_pct'] / 100;
    $franquia   = $receita * (float) $p['franquia_pct'] / 100;
    // Mes comercial de 30 dias: o periodo filtrado quase nunca e um mes
    // fechado, e ratear por dia e o unico jeito honesto de comparar.
    $fixos = ((float) $p['fixo_energia'] + (float) $p['fixo_sistema']) * max(1, $dias) / 30;

    $lucro = $receita - $cmv - $taxa - $condominio - $franquia - $fixos;

    return [
        'receita'    => $receita,
        'cmv'        => $cmv,
        'taxa'       => $taxa,
        'condominio' => $condominio,
        'franquia'   => $franquia,
        'fixos'      => $fixos,
        'lucro'      => $lucro,
        // Contagem junto do dinheiro: quando o numero diverge do painel do
        // TouchPay, e ela que diz se faltou venda ou se foi valor.
        'vendas'     => $vendas,
        'margem'     => $receita > 0 ? $lucro / $receita * 100 : 0.0,
        'dias'       => $dias,
    ];
}

/**
 * Quanto de cada real de venda vai embora em percentual (maquininha media,
 * condominio e franquia). E o que da para descontar por produto sem inventar
 * rateio de custo fixo.
 */
function custos_pct_variavel(array $por_forma, array $p): float
{
    $receita = 0.0;
    $taxa = 0.0;
    foreach ($por_forma as $linha) {
        $total = (float) ($linha['total'] ?? 0);
        $receita += $total;
        $taxa += $total * custo_taxa_da_forma($linha['forma'] ?? null, $p) / 100;
    }
    $taxa_media = $receita > 0 ? $taxa / $receita * 100 : 0.0;
    return $taxa_media + (float) $p['condominio_pct'] + (float) $p['franquia_pct'];
}

/**
 * Os percentuais que acompanham o faturamento, medidos no mix de pagamento
 * real dos ultimos meses. E o que falta descontar do preco de venda para
 * saber se comprar por X vale a pena.
 */
function custos_variavel_atual(int $dias = 90): array
{
    $p = custos_parametros();
    $por_forma = vendas_por_forma([
        'de'  => date('Y-m-d', strtotime('-' . $dias . ' days')),
        'ate' => date('Y-m-d'),
    ]);

    return [
        'pct'        => custos_pct_variavel($por_forma, $p),
        'condominio' => (float) $p['condominio_pct'],
        'franquia'   => (float) $p['franquia_pct'],
        // Sem venda no periodo nao da para saber o mix; a taxa fica de fora e
        // a tela avisa, em vez de inventar uma media.
        'tem_mix'    => $por_forma !== [],
    ];
}
