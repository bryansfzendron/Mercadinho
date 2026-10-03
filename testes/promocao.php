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

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
