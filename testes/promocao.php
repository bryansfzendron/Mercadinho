<?php
declare(strict_types=1);

/*
 * A regra da promocao do que esta perto de vencer: a escada pela data, o
 * ritmo de venda mexendo nela, o piso de prejuizo e o link para o Repor.
 * Nada aqui toca o banco.
 *
 *   php testes/promocao.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/mercado.php';
require APP . '/touchpay.php';
require APP . '/planograma.php';
require APP . '/promocao.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

/** Um caso com valores de todo dia, trocando so o que interessa. */
function sug(array $extra = []): array
{
    return promocao_sugerir($extra + [
        'dias' => 10, 'estoque' => 10, 'venda_dia' => null,
        'custo' => 2.0, 'preco' => 3.8, 'prejuizo' => 1.132,
    ]);
}

// --------------------------------------------------------- a escada
checar('ate 3 dias, 1,4', promocao_taxa_por_dias(3), 1.4);
checar('hoje tambem e 1,4', promocao_taxa_por_dias(0), 1.4);
checar('4 a 7 dias, 1,5', [promocao_taxa_por_dias(4), promocao_taxa_por_dias(7)], [1.5, 1.5]);
checar('8 a 15 dias, 1,6', [promocao_taxa_por_dias(8), promocao_taxa_por_dias(15)], [1.6, 1.6]);
checar('16 a 30 dias, 1,7', [promocao_taxa_por_dias(16), promocao_taxa_por_dias(30)], [1.7, 1.7]);
checar('31 a 60 dias, 1,8', [promocao_taxa_por_dias(31), promocao_taxa_por_dias(60)], [1.8, 1.8]);
checar('mais de 60, nada', promocao_taxa_por_dias(61), null);

// ------------------------------------------------------------ o piso
// 1,132 vira 1,15: arredondar para baixo poria a promocao abaixo do piso.
checar('piso sobe para o proximo 0,05', promocao_piso(1.132), 1.15);
checar('piso redondo fica', promocao_piso(1.15), 1.15);
checar('sem piso, sem piso', promocao_piso(null), null);

// --------------------------------------------- so pela data (sem venda)
$s = sug();
checar('10 dias sem dado de venda: 1,6', [$s['acao'], $s['taxa']], ['promocao', 1.6]);
checar('o preco e custo x taxa', $s['preco'], 3.2);
checar('e diz que foi so pela data', str_contains($s['motivos'][0], 'só pela data'), true);

// ---------------------------------------------------- o ritmo de venda
// 10 unidades a 2 por dia acabam em 5 dias; faltam 10: sai com folga.
$s = sug(['venda_dia' => 2.0]);
checar('sai com folga: nao precisa', [$s['acao'], $s['taxa']], ['manter', null]);
checar('e diz quando acaba', $s['dias_para_vender'], 5.0);
// 10 unidades a 1,1 por dia: ~9 dias, de 10. Sai, mas no aperto: degrau mais leve.
$s = sug(['venda_dia' => 1.1]);
checar('no aperto: um degrau mais leve', [$s['acao'], $s['taxa']], ['promocao', 1.7]);
// 10 unidades a 0,7 por dia: ~14 dias, de 10. Nao sai tudo: o degrau da data.
checar('nao sai tudo: o degrau da data', sug(['venda_dia' => 0.7])['taxa'], 1.6);
// 10 unidades a 0,2 por dia: 50 dias, de 10. Nem metade: um degrau mais fundo.
checar('nem metade: um degrau mais fundo', sug(['venda_dia' => 0.2])['taxa'], 1.5);
// Nada vendido no mes: no preco de hoje nao sai nunca.
checar('parado: um degrau mais fundo', sug(['venda_dia' => 0.0])['taxa'], 1.5);
// Parado com 80 dias pela frente: a data nao pediria nada, o ritmo pede.
$s = sug(['venda_dia' => 0.0, 'dias' => 80]);
checar('parado com 80 dias ja pede 1,7', [$s['acao'], $s['taxa']], ['promocao', 1.7]);
// Com mais de 60 dias e vendendo devagar, nao sai a tempo: entra no 1,8.
checar('longe mas lento: 1,8', sug(['dias' => 90, 'venda_dia' => 0.1, 'estoque' => 10])['taxa'], 1.8);
checar('longe e vendendo: nada', sug(['dias' => 90, 'venda_dia' => 1.0])['acao'], 'manter');

// ------------------------------------------------- o piso segura a escada
// Piso de 1,45 com a escada pedindo 1,4: para no piso.
$s = sug(['dias' => 2, 'prejuizo' => 1.45]);
checar('nao desce do piso', $s['taxa'], 1.45);
checar('e explica o piso', str_contains(implode(' ', $s['motivos']), 'piso'), true);

// ------------------------------------------ o preco de hoje ja e promocao
// Custo 2, vendendo a 3,00 (1,5x): a escada pede 1,6x = 3,20, que e AUMENTO.
$s = sug(['preco' => 3.0]);
checar('sugestao que aumenta nao e promocao', [$s['acao'], $s['taxa']], ['manter', null]);
checar('e diz que ja esta na faixa', str_contains(implode(' ', $s['motivos']), 'já está'), true);

// ------------------------------------------------------- casos de parada
checar('vencido: retirar', sug(['dias' => -1])['acao'], 'retirar');
checar('vencido nao sugere preco', sug(['dias' => -1])['taxa'], null);
checar('sem estoque: nada a fazer', sug(['estoque' => 0])['acao'], 'sem_estoque');
checar('sem validade', sug(['dias' => null])['acao'], 'sem_validade');
checar('5027 e suspeita', sug(['dias' => 1000000])['acao'], 'suspeita');
checar('mais de 60 dias: manter', sug(['dias' => 75])['acao'], 'manter');

// ----------------------------------------------------- sem custo de nota
$s = sug(['custo' => null]);
checar('sem custo ainda sugere a taxa', [$s['acao'], $s['taxa']], ['promocao', 1.6]);
checar('mas sem preco', $s['preco'], null);
checar('e manda digitar o custo', str_contains(implode(' ', $s['motivos']), 'digite o custo'), true);

// ------------------------------------------------------------ as opcoes
$op = promocao_opcoes(2.0, 3.1, 1.45);
checar('cinco taxas', array_column($op, 'taxa'), [1.4, 1.5, 1.6, 1.7, 1.8]);
checar('cada uma com o seu preco', $op[2]['preco'], 3.2);
checar('1,4 abaixo do piso de 1,45', [$op[0]['abaixo_piso'], $op[1]['abaixo_piso']], [true, false]);
checar('3,20 nao baixa de 3,10', [$op[1]['nao_baixa'], $op[2]['nao_baixa']], [false, true]);

// ------------------------------------------------------------ o texto
checar('dias', promocao_dias_texto(4.2), '5 dias');
checar('um dia', promocao_dias_texto(0.4), '1 dia');
checar('semanas', promocao_dias_texto(21), '3 semanas');
checar('meses', promocao_dias_texto(95), '3 meses');

// --------------------------------------------------- o link para o Repor
$l = promocao_link_repor(3, '7891234567895', 2.25, 1.6);
checar('o link leva tudo', $l,
    '/planograma?pdv=3&codigo=7891234567895&custo=2%2C25&taxa=1%2C60&promo=1');
checar('sem taxa nao e promocao', promocao_link_repor(3, '789', null, null), '/planograma?pdv=3&codigo=789');

// ------------------------------------------- taxa sobre custo -> percentual
// R$ 4,29 hoje, alvo R$ 3,15 (2,25 x 1,4): 26,57% -> 26%. Para BAIXO: o
// preco final fica um pouco acima do alvo, que ja respeita o piso.
checar('percentual arredonda para baixo', promocao_percentual(4.29, 3.15), 26);
checar('e o preco fica acima do alvo', promocao_preco_com(4.29, 26) >= 3.15, true);
checar('alvo igual ao preco nao e promocao', promocao_percentual(4.29, 4.29), null);
checar('alvo acima nao e promocao', promocao_percentual(4.29, 5.0), null);
checar('menos de 1% nao e promocao', promocao_percentual(10.0, 9.95), null);
checar('sem preco, sem percentual', promocao_percentual(null, 3.0), null);
checar('sem alvo, sem percentual', promocao_percentual(4.29, null), null);
checar('teto de 90%', promocao_percentual(10.0, 0.10), 90);
// 1 - 8/10 = 0,19999... em float: sem a folga viraria 19%.
checar('20% exato nao vira 19', promocao_percentual(10.0, 8.0), 20);

checar('preco com desconto', promocao_preco_com(4.29, 27), 3.13);

// ------------------------------------------------------------ o status
checar('comecou e nao acabou: no ar', promocao_status('2026-10-01', '2026-10-08', '2026-10-03'), 'no ar');
checar('o ultimo dia ainda e no ar', promocao_status('2026-10-01', '2026-10-03', '2026-10-03'), 'no ar');
checar('ainda vai comecar', promocao_status('2026-10-05', '2026-10-08', '2026-10-03'), 'agendada');
checar('ja acabou', promocao_status('2026-09-20', '2026-10-02', '2026-10-03'), 'encerrada');

// ----------------------------------------------------------- a validacao
$h = '2026-10-03';
checar('pedido certo passa', promocao_validar(25.0, '2026-10-03', '2026-10-08', $h), null);
checar('0% recusa', promocao_validar(0.0, '2026-10-03', '2026-10-08', $h) !== null, true);
checar('sem desconto recusa', promocao_validar(null, '2026-10-03', '2026-10-08', $h) !== null, true);
checar('95% recusa', promocao_validar(95.0, '2026-10-03', '2026-10-08', $h) !== null, true);
checar('decimal recusa', promocao_validar(12.5, '2026-10-03', '2026-10-08', $h) !== null, true);
checar('comecando ontem recusa', promocao_validar(25.0, '2026-10-02', '2026-10-08', $h) !== null, true);
checar('fim antes do comeco recusa', promocao_validar(25.0, '2026-10-05', '2026-10-04', $h) !== null, true);
checar('um dia so passa', promocao_validar(25.0, '2026-10-03', '2026-10-03', $h), null);
checar('mais de 120 dias recusa', promocao_validar(25.0, '2026-10-03', '2027-03-01', $h) !== null, true);
checar('sem data recusa', promocao_validar(25.0, null, '2026-10-08', $h) !== null, true);

// --------------------------------------------------------------- o nome
// O TouchPay devolve 500 "Erro interno" para nome comprido (03/10/2026: 49 e
// 36 caracteres falharam, 28 passou). O teto e o que se viu passar.
checar('nome curto passa inteiro', promocao_nome('IOGURTE  NESTLE 170G'), 'IOGURTE NESTLE 170G');
$longo = promocao_nome('Pudim Gourmet de Café Barbara Brito 170g');
checar('nome longo e cortado em 28', mb_strlen($longo) <= 28, true);
checar('e em 30 bytes', strlen($longo) <= 30, true);
checar('o corte nao deixa espaco no fim', $longo === rtrim($longo), true);
$acentos = promocao_nome('Pão de Açúcar Maçã Caramelização Ótima');
checar('acento conta dois bytes', strlen($acentos) <= 30, true);
checar('sem quebrar letra no meio', mb_check_encoding($acentos, 'UTF-8'), true);
// Os nomes que o TouchPay aceitou continuam passando como estao.
checar('nome visto passando fica igual', promocao_nome('Promoção Fofura Presunto 60g'), 'Promoção Fofura Presunto 60g');
checar('nome vazio tem nome', promocao_nome('  '), 'Promoção');

// ------------------------------------------------------------ o endereco
// PDV + produto, e nao o id da linha do espelho, que muda a cada sync.
checar('endereco por PDV e produto', promocao_url(1, 59534), '/promocao/1/59534');
checar('sem produto, sem endereco', promocao_url(1, 0), null);
checar('sem PDV, sem endereco', promocao_url(0, 59534), null);

// -------------------------------------------------------------- o corpo
// Campo a campo o que o painel deles mandou na captura de 03/10/2026.
$corpo = promocao_corpo(7467, 892, 10, '2026-10-03', '2026-10-05', 'Fofura promo');
checar('o corpo e o do painel', json_encode($corpo, JSON_UNESCAPED_UNICODE),
    '{"type":"Percentage","startsOn":"2026-10-03","expiresOn":"2026-10-05","description":"Fofura promo",'
    . '"discountProductRules":[{"id":0,"productId":"7467","quantity":1,"amount":10}],'
    . '"discountPointOfSaleRules":[{"id":0,"pointOfSaleId":892,"discountBaseId":0}],'
    . '"usage":0,"category":"Product"}');

// ------------------------------------------------- a lista do TouchPay
// A resposta de GET /api/discountproducts/paginated de 03/10/2026, com os
// nomes de lugar trocados.
$resposta = json_decode('{"items":[{"id":728,"type":"Percentage","category":"Product",'
    . '"startsOn":"2026-10-03T00:00:00","expiresOn":"2026-10-05T00:00:00",'
    . '"dateCreated":"2026-10-03T16:05:58.789183","isValid":true,'
    . '"description":"Promoção Fofura Presunto 60g","usage":0,'
    . '"discountPointOfSaleRules":[{"id":167,"pointOfSaleId":892,"customerName":"CLIENTE",'
    . '"localName":"CONDOMINIO","specificLocation":"Sala"}],'
    . '"discountProductRules":[{"id":596,"productId":"7467","productCode":"7892840823207",'
    . '"productDescription":"Fofura Presunto 60g","productCategory":"SALGADINHOS",'
    . '"productDefaultPrice":3.95,"quantity":1,"amount":29.0,"paymentMethod":null,'
    . '"minimumValue":null,"maxDiscountValue":null}]}],'
    . '"pageIndex":1,"totalPages":1,"totalItems":1,"pageSize":10,'
    . '"hasPreviousPage":false,"hasNextPage":false}', true);

checar('a pagina tem os itens em items', count(pg_itens($resposta)), 1);
checar('e diz o total', tp_total($resposta), 1);

$l = promocoes_tp_normalizar(pg_itens($resposta));
checar('uma linha por produto x PDV', count($l), 1);
checar('as datas viram dia', [$l[0]['inicio'], $l[0]['fim']], ['2026-10-03', '2026-10-05']);
checar('o produto vem como numero', $l[0]['produto_externo'], 7467);
checar('o PDV tambem', $l[0]['pdv_externo'], 892);
checar('o desconto escrito', $l[0]['desconto'], '−29%');
checar('valida', $l[0]['valido'], true);
checar('o preco padrao vem junto', $l[0]['preco_padrao'], 3.95);

// Uma promocao com dois produtos em dois PDVs vira quatro linhas.
$dupla = $resposta['items'][0];
$dupla['discountProductRules'][] = ['productId' => '8000', 'amount' => 10];
$dupla['discountPointOfSaleRules'][] = ['pointOfSaleId' => 900];
checar('dois produtos x dois PDVs = quatro', count(promocoes_tp_normalizar([$dupla])), 4);
checar('sem data, fora', promocoes_tp_normalizar([['id' => 1, 'discountProductRules' => [[]],
    'discountPointOfSaleRules' => [[]]]]), []);

// Sem regra de PDV a promocao vale em todos ("teste" e "goiabada teste", do
// painel, em 03/10/2026). Vira PDV 0, e nao some.
$todos = $resposta['items'][0];
$todos['discountPointOfSaleRules'] = [];
$lt = promocoes_tp_normalizar([$todos]);
checar('sem PDV vira uma linha', count($lt), 1);
checar('com PDV 0, "todos"', [$lt[0]['pdv_externo'], $lt[0]['pdv_nome']], [0, 'todos os pontos de venda']);
checar('promocao de todos cruza com qualquer PDV',
    count(promocoes_que_cruzam($lt, 892, 7467, '2026-10-04', '2026-10-04')), 1);
checar('e com outro PDV tambem', count(promocoes_que_cruzam($lt, 859, 7467, '2026-10-04', '2026-10-04')), 1);

// -------------------------------------------------- valendo e cruzando
$h = '2026-10-04';
checar('no meio do periodo, valendo', count(promocoes_valendo($l, $h)), 1);
checar('depois do fim, nao', count(promocoes_valendo($l, '2026-10-06')), 0);
$desligada = $l; $desligada[0]['valido'] = false;
checar('desligada no painel, nao', count(promocoes_valendo($desligada, $h)), 0);

checar('mesmo produto e PDV, datas cruzam', count(promocoes_que_cruzam($l, 892, 7467, '2026-10-05', '2026-10-09')), 1);
checar('comecando no dia seguinte ao fim, nao cruza', count(promocoes_que_cruzam($l, 892, 7467, '2026-10-06', '2026-10-09')), 0);
checar('outro PDV, nao cruza', count(promocoes_que_cruzam($l, 900, 7467, '2026-10-03', '2026-10-05')), 0);
checar('outro produto, nao cruza', count(promocoes_que_cruzam($l, 892, 1, '2026-10-03', '2026-10-05')), 0);
checar('desligada nao briga', count(promocoes_que_cruzam($desligada, 892, 7467, '2026-10-03', '2026-10-05')), 0);

// ------------------------------------------------ desconto em dinheiro
checar('percentual com casa', promocao_desconto_texto('Percentage', 12.5), '−12,5%');
checar('em dinheiro', promocao_desconto_texto('Value', 1.5), '−R$ 1,50');
checar('tipo desconhecido fica cru', promocao_desconto_texto('Combo', 3), 'Combo 3');
checar('preco final percentual', promocao_preco_final('Percentage', 3.95, 29), 2.8);
checar('preco final em dinheiro', promocao_preco_final('Value', 3.95, 1.0), 2.95);
checar('tipo desconhecido: nao sei', promocao_preco_final('Combo', 3.95, 1.0), null);
checar('sem preco: nao sei', promocao_preco_final('Percentage', null, 29), null);

// ----------------------------------------------------------- a URL
// A URL do painel, letra por letra: foi a unica conferida contra a conta de
// verdade (200 OK). Pedir 200 por pagina e das mais novas primeiro deu 500
// "erro interno" e barrou a criacao de promocao.
checar('a lista e pedida igual ao painel', tp_promocoes_url(1),
    '/api/discountproducts/paginated?page=1&pageSize=10&sortOrder=dateCreated&descending=false'
    . '&search=&startDate=&endDate=&discountType=&timezoneOffset=180');
checar('so a pagina muda', str_contains(tp_promocoes_url(3), 'page=3&pageSize=10&'), true);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
