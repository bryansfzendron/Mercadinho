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
 *  3. **Custos fixos do mes** — energia, sistema e internet. Nao se dividem
 *     por produto sem inventar rateio, entao entram so no resultado do
 *     periodo, rateados por dia.
 *
 * As tres sao por ponto de venda: cada container tem a sua conta de luz, a sua
 * internet e as vezes ate o seu condominio. Os valores em custos_parametros sao
 * o padrao, e o que difere num PDV mora em custos_pdv.
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
        // Fixos do mes inteiro, em reais, POR PONTO DE VENDA. O valor aqui e
        // o padrao; cada PDV pode ter o seu em Configuracoes > Taxas.
        'fixo_energia'    => 300.0,
        'fixo_sistema'    => 149.0,
        'fixo_internet'   => 0.0,
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

/**
 * O que sai todo mes independente de vender, em UM ponto de venda: energia,
 * sistema e internet.
 *
 * Uma funcao so soma os tres para o proximo custo que aparecer mexer aqui e
 * mais nada — os containers novos ja trouxeram a internet, e nao serao os
 * ultimos. Quem soma os containers e custos_fixo_mensal_total().
 */
function custos_fixos_mensais(array $p): float
{
    return (float) ($p['fixo_energia'] ?? 0)
         + (float) ($p['fixo_sistema'] ?? 0)
         + (float) ($p['fixo_internet'] ?? 0);
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
 * Os percentuais que acompanham o faturamento, medidos no mix de pagamento
 * real dos ultimos meses. E o que falta descontar do preco de venda para
 * saber se comprar por X vale a pena.
 */
function custos_variavel_atual(int $dias = 90): array
{
    $p = custos_parametros();
    $por_pdv_forma = vendas_por_pdv_forma([
        'de'  => date('Y-m-d', strtotime('-' . $dias . ' days')),
        'ate' => date('Y-m-d'),
    ]);
    $por_forma = vendas_juntar_formas($por_pdv_forma);
    $imposto_pct = custos_imposto_pct();

    return [
        'pct'        => custos_pct_variavel_pdvs(
            $por_pdv_forma,
            custos_params_dos_pdvs(array_column($por_pdv_forma, 'pdv_id')),
            $imposto_pct
        ),
        'condominio' => (float) $p['condominio_pct'],
        'franquia'   => (float) $p['franquia_pct'],
        // Imposto e por CNPJ, entao e sempre o mesmo numero independente do
        // mix de PDV/forma — diferente das outras camadas, que variam com ele.
        'imposto'    => $imposto_pct,
        // Sem venda no periodo nao da para saber o mix; a taxa fica de fora e
        // a tela avisa, em vez de inventar uma media.
        'tem_mix'    => $por_forma !== [],
    ];
}

/**
 * Os dois fatores de corte, tirados dos numeros dele e nao de regra de bolso.
 *
 * Vender por V um produto que custou C sobra `V - C - V*pct`. Isso zera quando
 * C = V*(1-pct), ou seja quando o fator V/C chega em `1/(1-pct)`. Com 11,68%
 * de maquininha, condominio e franquia, o piso e 1,132x — qualquer coisa
 * abaixo disso da prejuizo em cada unidade vendida, por mais que o preco
 * pareca o dobro do custo.
 *
 * O segundo corte poe o custo fixo do mes na conta como percentual do
 * faturamento: com R$ 449 de fixos sobre R$ 14,7 mil, sao mais
 * 3,05%, e o fator saudavel sobe para 1,173x.
 *
 * @param float      $pct_variavel  maquininha + condominio + franquia
 * @param float|null $pct_fixo      fixos do mes sobre o faturamento, se der para saber
 */
function custos_minimos(float $pct_variavel, ?float $pct_fixo = null): array
{
    $fator = static function (float $pct): ?float {
        // Percentual em 100% ou mais nao tem fator que salve.
        return $pct >= 100 ? null : 1 / (1 - $pct / 100);
    };

    return [
        'pct_variavel' => $pct_variavel,
        'pct_fixo'     => $pct_fixo,
        'prejuizo'     => $fator($pct_variavel),
        'operacao'     => $pct_fixo === null ? null : $fator($pct_variavel + $pct_fixo),
    ];
}

/**
 * Como esta a margem deste produto perto dos cortes.
 *
 * @return array{fator:?float, veredito:string, sobra:float, sobra_apos_fixo:?float}
 */
function custo_diagnostico(float $venda, ?float $custo, array $minimos): array
{
    if ($custo === null || $custo <= 0 || $venda <= 0) {
        return ['fator' => null, 'veredito' => 'sem_custo', 'sobra' => 0.0, 'sobra_apos_fixo' => null];
    }

    $fator = $venda / $custo;
    $sobra = $venda - $custo - $venda * $minimos['pct_variavel'] / 100;
    $apos  = $minimos['pct_fixo'] === null ? null : $sobra - $venda * $minimos['pct_fixo'] / 100;

    if ($minimos['prejuizo'] !== null && $fator < $minimos['prejuizo']) {
        $veredito = 'prejuizo';
    } elseif ($minimos['operacao'] !== null && $fator < $minimos['operacao']) {
        $veredito = 'aperto';
    } else {
        $veredito = 'ok';
    }

    return ['fator' => $fator, 'veredito' => $veredito, 'sobra' => $sobra, 'sobra_apos_fixo' => $apos];
}

/** Rotulo e classe de cor de cada veredito, para as telas nao repetirem isso. */
function custo_veredito_rotulo(string $veredito): array
{
    switch ($veredito) {
        case 'prejuizo':  return ['Prejuízo', 'margem-ruim', 'selo-erro'];
        case 'aperto':    return ['Não paga a operação', 'margem-aperto', 'selo-pendente'];
        case 'sem_custo': return ['Sem custo de nota', 'margem-neutra', 'selo-manual'];
        default:          return ['Saudável', 'margem-boa', 'selo-ok'];
    }
}

/**
 * O quanto os fixos do mes pesam sobre o faturamento, para virar o segundo
 * corte. Sem faturamento nao da para saber, e a tela mostra so o primeiro.
 */
function custos_pct_fixo(?float $faturamento_mes, ?array $p = null, ?float $fixo_mes = null): ?float
{
    if ($faturamento_mes === null || $faturamento_mes <= 0) {
        return null;
    }
    // Os parametros vem de fora quando quem chama ja os tem em maos — e e o
    // que deixa esta funcao testavel sem banco.
    // O total somado vem de fora quando ha mais de um PDV: cada container tem o
    // seu fixo, e a soma nao sai de um unico conjunto de parametros.
    $p = $p ?? custos_parametros();
    return ($fixo_mes ?? custos_fixos_mensais($p)) / $faturamento_mes * 100;
}

// ---------------------------------------------------------------------
// Imposto (Simples Nacional, Anexo I — CNAE 4712-1/00)
// ---------------------------------------------------------------------

/**
 * Tabela do Anexo I do Simples Nacional (comercio), LC 123/2006 com a
 * redacao da LC 155/2016 — em vigor desde 2018. E o anexo do CNAE
 * 4712-1/00 (comercio varejista de mercadorias em geral, com predominancia
 * de produtos alimenticios — minimercados, mercearias e armazens).
 *
 * RBT12 e a receita bruta acumulada nos ultimos 12 meses. A aliquota da
 * tabela e nominal: quem sai de cada venda e a aliquota efetiva, calculada
 * em custos_aliquota_efetiva_simples().
 *
 * @return array<int, array{teto:float, aliquota:float, deducao:float}>
 */
function custos_simples_anexo1(): array
{
    return [
        ['teto' =>    180000.0, 'aliquota' =>  4.00, 'deducao' =>      0.0],
        ['teto' =>    360000.0, 'aliquota' =>  7.30, 'deducao' =>   5940.0],
        ['teto' =>    720000.0, 'aliquota' =>  9.50, 'deducao' =>  13860.0],
        ['teto' =>   1800000.0, 'aliquota' => 10.70, 'deducao' =>  22500.0],
        ['teto' =>   3600000.0, 'aliquota' => 14.30, 'deducao' =>  87300.0],
        ['teto' =>   4800000.0, 'aliquota' => 19.00, 'deducao' => 378000.0],
    ];
}

/** A faixa da tabela que cobre aquele RBT12 — a ultima quando estoura o teto. */
function custos_faixa_simples(float $rbt12): array
{
    $tabela = custos_simples_anexo1();
    foreach ($tabela as $faixa) {
        if ($rbt12 <= $faixa['teto']) {
            return $faixa;
        }
    }
    // RBT12 acima de R$ 4,8 milhoes estoura o teto do Simples: fica a ultima
    // faixa, que e o melhor palpite ate a empresa migrar de regime.
    return end($tabela);
}

/**
 * Quao perto o RBT12 esta do teto da faixa atual — e o que faz a tela avisar
 * antes da aliquota subir, em vez de so mostrar depois que ja subiu.
 *
 * @return array{teto:float, falta:float, pct:float, ultima_faixa:bool, proxima_aliquota:?float}
 */
function custos_proximidade_faixa_simples(float $rbt12): array
{
    $rbt12 = max(0.0, $rbt12);
    $tabela = custos_simples_anexo1();

    $indice = 0;
    foreach ($tabela as $i => $faixa) {
        $indice = $i;
        if ($rbt12 <= $faixa['teto']) {
            break;
        }
    }
    $atual = $tabela[$indice];
    $ultima = $indice === count($tabela) - 1;

    return [
        'teto'             => $atual['teto'],
        'falta'            => max(0.0, $atual['teto'] - $rbt12),
        'pct'              => $atual['teto'] > 0 ? min(100.0, $rbt12 / $atual['teto'] * 100) : 0.0,
        'ultima_faixa'     => $ultima,
        'proxima_aliquota' => $ultima ? null : $tabela[$indice + 1]['aliquota'],
    ];
}

/**
 * A aliquota que sai de cada venda, em percentual — nao a nominal da tabela.
 * Formula do art. 18 da LC 123/2006: (RBT12 x aliquota nominal − parcela a
 * deduzir) / RBT12. E o que faz o Simples ser progressivo sem virar degrau:
 * uma empresa no fim de uma faixa nao paga o nominal da faixa inteira.
 */
function custos_aliquota_efetiva_simples(float $rbt12): float
{
    if ($rbt12 <= 0) {
        // Sem 12 meses de historico ainda: usa o nominal da primeira faixa,
        // que e o que a Receita cobra ate existir RBT12 de verdade.
        return custos_simples_anexo1()[0]['aliquota'];
    }

    $faixa = custos_faixa_simples($rbt12);
    $efetiva = ($rbt12 * $faixa['aliquota'] / 100 - $faixa['deducao']) / $rbt12 * 100;
    return max(0.0, $efetiva);
}

/**
 * Data em que a operacao atual assumiu a loja. Receita de antes disso era de
 * quem tinha o CNPJ antes e nao entra no RBT12, mesmo que o historico de
 * vendas do TouchPay va mais para tras — se um dia a loja trocar de dono de
 * novo, o lugar de ajustar e este.
 */
function custos_inicio_atividade(): string
{
    return '2026-09-01';
}

/**
 * Quantos meses de atividade ha desde o inicio, contando o mes corrente como
 * um mes inteiro mesmo que tenha comecado no meio dele — e a mesma contagem
 * que o Simples usa pra empresa em inicio de atividade (o PA de abertura ja
 * conta como mes 1).
 */
function custos_meses_atividade(string $inicio, string $ate): int
{
    $di = new DateTimeImmutable($inicio);
    $da = new DateTimeImmutable($ate);
    $meses = ((int) $da->format('Y') - (int) $di->format('Y')) * 12
           + ((int) $da->format('n') - (int) $di->format('n')) + 1;
    return max(1, $meses);
}

/**
 * Faturamento bruto da empresa inteira (todos os PDVs ativos, todas as
 * formas) nos 12 meses terminando em $ate, sem passar de custos_inicio_atividade().
 * O Simples e apurado por CNPJ, nao por container — por isso esta conta
 * nunca filtra por PDV especifico, ao contrario do resto de custos.php.
 *
 * Usa o mesmo filtro de vendas_filtro_sql(): PDV desligado fica de fora
 * porque, como a tela de PDVs explica, pode ser um container que esta na
 * mesma conta do TouchPay mas nao e seu — contar a receita dele inflaria o
 * RBT12 e jogaria a aliquota para uma faixa que nao e a real.
 *
 * Com menos de 12 meses de atividade, a receita do periodo e anualizada
 * (receita / meses x 12) — a regra oficial do Simples para empresa nova.
 * Sem isso a aliquota apareceria artificialmente baixa nos primeiros meses
 * e daria um salto de repente quando o primeiro ano fechasse.
 */
function custos_rbt12(?string $ate = null): float
{
    $ate = $ate ?? date('Y-m-d');
    $inicio = custos_inicio_atividade();
    $de_completo = date('Y-m-d', strtotime($ate . ' -12 months'));
    $de = max($de_completo, $inicio);

    [$onde, $args] = vendas_filtro_sql(['de' => $de, 'ate' => $ate]);
    $receita = (float) qv("SELECT COALESCE(SUM(v.valor_pago), 0) FROM vendas v WHERE $onde", $args);

    if ($de_completo < $inicio) {
        $meses = custos_meses_atividade($inicio, $ate);
        return $receita / $meses * 12;
    }
    return $receita;
}

/**
 * O percentual de imposto de hoje, pelo Simples Nacional. Variavel de
 * proposito: a aliquota efetiva sobe com o RBT12, entao um numero fixo
 * chutado erraria assim que o faturamento empurrasse a empresa para a
 * proxima faixa, prejuizo ou sobra impossivel de explicar sozinho.
 */
function custos_imposto_pct(): float
{
    return custos_aliquota_efetiva_simples(custos_rbt12());
}

// ---------------------------------------------------------------------
// Custos por ponto de venda
// ---------------------------------------------------------------------

/**
 * Os parametros de um PDV: o padrao global com o que aquele PDV sobrescreve.
 *
 * Container tem energia, internet e ate condominio proprios. Quem nao tem
 * diferenca nao configura nada e continua no padrao — e por isso a tabela
 * guarda so a excecao, nao uma copia dos valores para cada PDV.
 */
function custos_parametros_pdv(int $pdv_id): array
{
    $valores = custos_parametros();
    if ($pdv_id <= 0) {
        return $valores;
    }
    foreach (q('SELECT chave, valor FROM custos_pdv WHERE pdv_id = ?', [$pdv_id]) as $linha) {
        $chave = (string) $linha['chave'];
        if (array_key_exists($chave, $valores)) {
            $valores[$chave] = (float) $linha['valor'];
        }
    }
    return $valores;
}

/**
 * O que aquele PDV tem de proprio, sem o padrao por baixo. E o que a tela de
 * ajuste precisa para mostrar campo vazio no que segue o padrao.
 *
 * @return array<string,float>
 */
function custos_overrides_pdv(int $pdv_id): array
{
    $fora = [];
    foreach (q('SELECT chave, valor FROM custos_pdv WHERE pdv_id = ?', [$pdv_id]) as $linha) {
        $fora[(string) $linha['chave']] = (float) $linha['valor'];
    }
    return $fora;
}

/**
 * Grava o que difere naquele PDV. Campo vazio apaga a excecao e volta ao
 * padrao — e assim que se desfaz uma diferenca sem precisar saber o valor
 * global de cor.
 */
function custos_salvar_pdv(int $pdv_id, array $novos): int
{
    if ($pdv_id <= 0) {
        return 0;
    }
    $conhecidos = custos_padrao();
    $agora = date('Y-m-d H:i:s');
    $n = 0;

    foreach ($conhecidos as $chave => $_) {
        if (!array_key_exists($chave, $novos)) {
            continue;
        }
        $bruto = trim((string) $novos[$chave]);
        if ($bruto === '') {
            exec_sql('DELETE FROM custos_pdv WHERE pdv_id = ? AND chave = ?', [$pdv_id, $chave]);
            $n++;
            continue;
        }
        exec_sql(
            'INSERT INTO custos_pdv (pdv_id, chave, valor, atualizado_em) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = VALUES(atualizado_em)',
            [$pdv_id, $chave, max(0.0, num_br($bruto)), $agora]
        );
        $n++;
    }
    return $n;
}

/**
 * Os parametros de cada PDV que aparece numa lista, mais o global na posicao 0
 * para venda sem PDV.
 *
 * @param int[] $ids
 */
function custos_params_dos_pdvs(array $ids): array
{
    $mapa = [0 => custos_parametros()];
    foreach (array_unique(array_filter(array_map('intval', $ids))) as $id) {
        $mapa[$id] = custos_parametros_pdv($id);
    }
    return $mapa;
}

/**
 * Os PDVs que pagam custo fixo no periodo: os ativos, ou so o filtrado.
 *
 * Vem da tabela de PDVs e nao das vendas de proposito — container parado o mes
 * inteiro continua pagando energia, sistema e internet.
 */
function custos_fixos_no_escopo(int $pdv_id = 0): array
{
    $ids = $pdv_id > 0
        ? [$pdv_id]
        : array_map('intval', array_column(q('SELECT id FROM loja_pdvs WHERE ativo = 1'), 'id'));

    $mapa = [];
    foreach ($ids as $id) {
        $mapa[$id] = custos_parametros_pdv($id);
    }
    // Nenhum PDV cadastrado ainda: o padrao global conta como um, senao o custo
    // fixo sumiria justamente na primeira semana de uso.
    return $mapa ?: [0 => custos_parametros()];
}

/** O fixo mensal somado dos PDVs que pagam no escopo. */
function custos_fixo_mensal_total(int $pdv_id = 0): float
{
    $total = 0.0;
    foreach (custos_fixos_no_escopo($pdv_id) as $p) {
        $total += custos_fixos_mensais($p);
    }
    return $total;
}

/**
 * O resultado somando ponto de venda a ponto de venda.
 *
 * Cada PDV tem a sua receita, as suas taxas e os seus fixos, entao a conta
 * nao pode ser feita no bolo: um container com energia cara e outro barato
 * dao um numero errado se voce usar a media. Funcao pura — quem chama traz a
 * receita por (pdv, forma) e os parametros de cada PDV.
 *
 * @param array $por_pdv_forma linhas [pdv_id, forma, n, total]
 * @param array $params_por_pdv pdv_id => parametros daquele PDV
 * @param array $fixos_de       pdv_id => parametros dos PDVs que pagam fixo
 *                              no periodo (normalmente os ativos)
 * @param float $imposto_pct    aliquota efetiva do Simples sobre o periodo,
 *                              de custos_imposto_pct() — e por CNPJ, nao por
 *                              PDV, entao entra fora do loop dos parametros.
 */
function custos_resultado_pdvs(array $por_pdv_forma, array $params_por_pdv, array $fixos_de, float $cmv, int $dias, float $imposto_pct = 0.0): array
{
    $receita = 0.0;
    $taxa = 0.0;
    $condominio = 0.0;
    $franquia = 0.0;
    $vendas = 0;

    foreach ($por_pdv_forma as $linha) {
        $pdv = (int) ($linha['pdv_id'] ?? 0);
        $p   = $params_por_pdv[$pdv] ?? custos_padrao();
        $total = (float) ($linha['total'] ?? 0);

        $receita    += $total;
        $vendas     += (int) ($linha['n'] ?? 0);
        $taxa       += $total * custo_taxa_da_forma($linha['forma'] ?? null, $p) / 100;
        $condominio += $total * (float) $p['condominio_pct'] / 100;
        $franquia   += $total * (float) $p['franquia_pct'] / 100;
    }

    // Fixo nao depende de ter vendido: um container parado continua pagando
    // energia. Por isso ele vem da lista de PDVs no escopo, e nao das vendas.
    $fixos = 0.0;
    foreach ($fixos_de as $p) {
        $fixos += custos_fixos_mensais($p);
    }
    $fixos = $fixos * max(1, $dias) / 30;

    $imposto = $receita * $imposto_pct / 100;
    $lucro = $receita - $cmv - $taxa - $condominio - $franquia - $imposto - $fixos;

    return [
        'receita'    => $receita,
        'cmv'        => $cmv,
        'taxa'       => $taxa,
        'condominio' => $condominio,
        'franquia'   => $franquia,
        'imposto'    => $imposto,
        'fixos'      => $fixos,
        'lucro'      => $lucro,
        'vendas'     => $vendas,
        'margem'     => $receita > 0 ? $lucro / $receita * 100 : 0.0,
        'dias'       => $dias,
    ];
}

/**
 * Quanto de cada real de venda vai embora em percentual, com cada PDV usando
 * as suas taxas. E o que o bipe desconta antes de dizer se vale a pena.
 *
 * @param float $imposto_pct aliquota efetiva do Simples — igual para todo
 *                           PDV e toda forma de pagamento (e por CNPJ), entao
 *                           soma direto em vez de entrar no rateio por linha.
 */
function custos_pct_variavel_pdvs(array $por_pdv_forma, array $params_por_pdv, float $imposto_pct = 0.0): float
{
    $receita = 0.0;
    $variavel = 0.0;

    foreach ($por_pdv_forma as $linha) {
        $pdv = (int) ($linha['pdv_id'] ?? 0);
        $p   = $params_por_pdv[$pdv] ?? custos_padrao();
        $total = (float) ($linha['total'] ?? 0);

        $receita  += $total;
        $variavel += $total * (
            custo_taxa_da_forma($linha['forma'] ?? null, $p)
            + (float) $p['condominio_pct']
            + (float) $p['franquia_pct']
        ) / 100;
    }

    if ($receita <= 0) {
        // Sem venda no periodo nao da para ponderar: fica o padrao, que e melhor
        // do que zero (zero diria que nao sai nada de cada venda).
        $p = $params_por_pdv[0] ?? custos_parametros();
        return (float) $p['condominio_pct'] + (float) $p['franquia_pct'] + $imposto_pct;
    }
    return $variavel / $receita * 100 + $imposto_pct;
}
