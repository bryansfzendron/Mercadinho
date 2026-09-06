<?php
declare(strict_types=1);

/*
 * Testa as contas do relatorio de vendas: taxa por forma de pagamento,
 * resultado do periodo e o agrupamento com custo de nota fiscal.
 * Nada aqui toca o MySQL.
 *
 * Os numeros conferidos abaixo saem da conta real (1000 transacoes de
 * 2026-08-07 a 2026-09-06), entao o teste falha se a conta mudar de ideia.
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/custos.php';
require APP . '/vendas.php';
require APP . '/metas.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}
function quase(string $nome, float $obtido, float $esperado, float $tol = 0.01): void
{
    global $ok, $falhou;
    if (abs($obtido - $esperado) <= $tol) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: ~%.4f\n   obtido:   %.4f\n", $nome, $esperado, $obtido);
}

$p = custos_padrao();

// ------------------------------------------------------------------ taxas
checar('taxa do debito',  custo_taxa_da_forma('Debit', $p), 1.23);
checar('taxa do credito', custo_taxa_da_forma('Credit', $p), 2.92);
checar('taxa do pix',     custo_taxa_da_forma('Pix', $p), 0.35);
checar('taxa do voucher', custo_taxa_da_forma('Voucher', $p), 3.50);
checar('forma minuscula tambem casa', custo_taxa_da_forma('debit', $p), 1.23);
// Forma nova no TouchPay nao pode virar taxa inventada.
checar('forma desconhecida nao tem taxa', custo_taxa_da_forma('Bitcoin', $p), 0.0);
checar('forma nula nao tem taxa', custo_taxa_da_forma(null, $p), 0.0);

// ------------------------------------------------- resultado do periodo
// O mix real medido na conta dele.
$por_forma = [
    ['forma' => 'Debit',   'total' => 5975.27],
    ['forma' => 'Credit',  'total' => 3498.51],
    ['forma' => 'Pix',     'total' => 2104.68],
    ['forma' => 'Voucher', 'total' => 656.44],
];
$receita = 12234.90;

$res = custos_resultado($por_forma, $receita * 0.55, 30, $p);
quase('receita somada', $res['receita'], $receita);
// 5975,27x1,23% + 3498,51x2,92% + 2104,68x0,35% + 656,44x3,50%
quase('taxa da maquininha', $res['taxa'], 205.99);
quase('taxa media fica perto de 1,68%', $res['taxa'] / $res['receita'] * 100, 1.68);
quase('condominio 5%', $res['condominio'], 611.745);
quase('franquia 5%', $res['franquia'], 611.745);
quase('fixos do mes cheio', $res['fixos'], 449.0);
quase('lucro', $res['lucro'], $receita - $receita * 0.55 - 205.99 - 611.745 - 611.745 - 449.0);
quase('margem em %', $res['margem'], $res['lucro'] / $receita * 100);

// Meio mes rateia metade do fixo.
$quinzena = custos_resultado($por_forma, 0.0, 15, $p);
quase('fixo rateado por dia', $quinzena['fixos'], 449.0 * 15 / 30);
// Periodo sem data valida cai no mes comercial, nao em zero.
quase('periodo de um dia ainda paga um dia de fixo', custos_resultado([], 0.0, 1, $p)['fixos'], 449.0 / 30);

// Sem venda nenhuma nao pode virar divisao por zero.
$vazio = custos_resultado([], 0.0, 30, $p);
quase('sem venda a margem e zero', $vazio['margem'], 0.0);
quase('sem venda o prejuizo e o fixo', $vazio['lucro'], -449.0);

// ------------------------------------------------ percentual variavel
$pct = custos_pct_variavel($por_forma, $p);
quase('percentual variavel = taxa media + 5 + 5', $pct, 1.6836 + 10, 0.01);
quase('sem venda o variavel e so condominio + franquia', custos_pct_variavel([], $p), 10.0);

// ---------------------------------------------------------- agrupamento
$linhas = [
    // Produto com nota: custo unitario 1,00 e vendeu 10 a 1,94.
    ['grupo' => 'CANELA', 'produto_id' => 7, 'descricao' => 'CANELA FADINHA', 'ean' => '789', 'quantidade' => 10, 'receita' => 19.40, 'vendas' => 8],
    // Produto sem nota: cai no padrao de 55%.
    ['grupo' => 'BOLO',   'produto_id' => 0, 'descricao' => 'BOLO DE POTE',   'ean' => null,  'quantidade' => 2,  'receita' => 29.98, 'vendas' => 2],
];
$a = vendas_agrupar($linhas, [7 => 1.00], 55.0, 11.68);

checar('dois grupos', count($a['linhas']), 2);
// Ordenado por receita: o bolo fatura mais.
checar('ordenado pela receita', $a['linhas'][0]['grupo'], 'BOLO');

$canela = $a['linhas'][1];
quase('custo do que tem nota e unitario x quantidade', $canela['custo'], 10.0);
quase('lucro bruto', $canela['bruto'], 9.40);
quase('contribuicao tira tambem os percentuais', $canela['contribuicao'], 9.40 - 19.40 * 0.1168);
quase('fator', (float) $canela['fator'], 1.94);
checar('marcado como custo de nota', $canela['com_nota'], true);

$bolo = $a['linhas'][0];
quase('sem nota usa o padrao', $bolo['custo'], 29.98 * 0.55);
checar('marcado como estimado', $bolo['com_nota'], false);

quase('CMV total soma os dois', $a['cmv'], 10.0 + 29.98 * 0.55);
// Cobertura: quanto do faturamento tem custo de verdade.
quase('cobertura', $a['cobertura'], 19.40 / (19.40 + 29.98) * 100);

// O mesmo produto em duas linhas do grupo (dia diferente, por exemplo) soma.
$dobrado = vendas_agrupar([
    ['grupo' => 'X', 'produto_id' => 7, 'descricao' => 'A', 'ean' => null, 'quantidade' => 3, 'receita' => 6.0, 'vendas' => 3],
    ['grupo' => 'X', 'produto_id' => 9, 'descricao' => 'B', 'ean' => null, 'quantidade' => 1, 'receita' => 4.0, 'vendas' => 1],
], [7 => 1.00, 9 => 2.00], 55.0, 0.0);
checar('produtos diferentes viram um grupo so', count($dobrado['linhas']), 1);
quase('receita do grupo', $dobrado['linhas'][0]['receita'], 10.0);
quase('custo do grupo soma os produtos', $dobrado['linhas'][0]['custo'], 5.0);
checar('conta os produtos do grupo', $dobrado['linhas'][0]['produtos'], 2);

// Sem linha nenhuma o relatorio nao quebra.
$nada = vendas_agrupar([], [], 55.0, 10.0);
checar('sem linhas nao ha grupos', $nada['linhas'], []);
quase('sem linhas o cmv e zero', $nada['cmv'], 0.0);
quase('sem linhas a cobertura e zero', $nada['cobertura'], 0.0);

// Produto que so aparece na venda (nunca comprado) nao pode zerar o fator.
$semCusto = vendas_agrupar(
    [['grupo' => 'Z', 'produto_id' => 0, 'descricao' => 'Z', 'ean' => null, 'quantidade' => 1, 'receita' => 0.0, 'vendas' => 1]],
    [], 55.0, 10.0
);
checar('receita zero nao inventa fator', $semCusto['linhas'][0]['fator'], null);

// ---------------------------------------------------------- dias do periodo
checar('dias inclui as duas pontas', vendas_dias_do_periodo(['de' => '2026-09-01', 'ate' => '2026-09-30']), 30);
checar('um dia so', vendas_dias_do_periodo(['de' => '2026-09-06', 'ate' => '2026-09-06']), 1);
checar('sem data usa o mes comercial', vendas_dias_do_periodo([]), 30);
checar('data invertida usa o mes comercial', vendas_dias_do_periodo(['de' => '2026-09-30', 'ate' => '2026-09-01']), 30);

// ------------------------------------------------------------------ metas
// Dia 10 de um mes de 30, faturou 5000 e a meta e 15000.
$m = meta_progresso(5000.0, 15000.0, 10, 30);
quase('progresso em %', $m['pct'], 33.3333, 0.001);
quase('ritmo por dia', $m['ritmo'], 500.0);
quase('projecao do mes', $m['projecao'], 15000.0);
quase('falta', $m['falta'], 10000.0);
checar('dias restantes', $m['restam'], 20);
quase('precisa por dia', $m['por_dia'], 500.0);
checar('ainda nao bateu', $m['batida'], false);

// Meta batida nao pode virar "falta" negativo.
$b = meta_progresso(16000.0, 15000.0, 20, 30);
checar('meta batida', $b['batida'], true);
quase('nao falta nada', $b['falta'], 0.0);
quase('por dia zera depois de bater', $b['por_dia'], 0.0);

// Ultimo dia do mes: o que falta e para hoje, sem divisao por zero.
$u = meta_progresso(9000.0, 10000.0, 30, 30);
checar('sem dia restante', $u['restam'], 0);
quase('o que falta e para hoje', $u['por_dia'], 1000.0);

// Sem meta definida ainda projeta o mes.
$sem = meta_progresso(3000.0, 0.0, 6, 30);
checar('sem meta', $sem['tem_meta'], false);
quase('sem meta a projecao vale', $sem['projecao'], 15000.0);
quase('sem meta o percentual e zero', $sem['pct'], 0.0);

// Primeiro dia do mes nao pode dividir por zero nem projetar de menos.
$d1 = meta_progresso(400.0, 12000.0, 1, 30);
quase('ritmo no primeiro dia', $d1['ritmo'], 400.0);
quase('projecao no primeiro dia', $d1['projecao'], 12000.0);

// Dia zero (defensivo) conta como um dia.
quase('dia zero conta como um', meta_progresso(100.0, 0.0, 0, 30)['ritmo'], 100.0);
// Mes menor que os dias corridos nao inverte a conta.
checar('mes nunca menor que os dias corridos', meta_progresso(100.0, 0.0, 31, 28)['restam'], 0);

checar('dias do mes de fevereiro', metas_dias('2026-02-10'), [10, 28]);
checar('dias do mes de setembro', metas_dias('2026-09-06'), [6, 30]);

// A contagem de vendas entra no resultado, para comparar com o TouchPay.
$comN = custos_resultado([['forma' => 'Pix', 'total' => 100.0, 'n' => 7]], 0.0, 30, $p);
checar('conta as vendas do periodo', $comN['vendas'], 7);
checar('sem contagem nao inventa', custos_resultado([['forma' => 'Pix', 'total' => 100.0]], 0.0, 30, $p)['vendas'], 0);

// O PDV padrao e parametro tambem: PDV de outro dono na mesma conta do
// TouchPay nao pode inflar o faturamento das telas sem filtro.
checar('pdv padrao comeca em todos', custos_padrao()['pdv_padrao'], 0.0);
checar('pdv padrao nao mexe no resultado',
    custos_resultado([['forma' => 'Pix', 'total' => 100.0]], 0.0, 30, custos_padrao())['receita'], 100.0);

// ------------------------------------------------------------- parametros
checar('agrupamentos tem produto e categoria',
    array_key_exists('produto', vendas_agrupamentos()) && array_key_exists('categoria', vendas_agrupamentos()), true);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
