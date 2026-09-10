<?php
declare(strict_types=1);

/*
 * Testa a parte pura dos graficos do dashboard: a serie diaria (zero-fill)
 * e o calculo de altura/pico. A montagem do HTML fica de fora — e mecanica,
 * so formata o que aqui ja foi decidido. Nada aqui toca o MySQL.
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/graficos.php';

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

// ------------------------------------------------------- serie diaria
$serie = grafico_serie_diaria(['2026-09-02' => 150.0, '2026-09-04' => 300.0], '2026-09-01', '2026-09-05');
checar('cobre todos os dias do periodo, inclusive as duas pontas', count($serie), 5);
checar('dia sem venda entra com zero, nao some', $serie[0], ['data' => '2026-09-01', 'valor' => 0.0]);
checar('dia com venda pega o valor certo', $serie[1], ['data' => '2026-09-02', 'valor' => 150.0]);
checar('ultimo dia do periodo entra', $serie[4]['data'], '2026-09-05');

$umDia = grafico_serie_diaria([], '2026-09-01', '2026-09-01');
checar('periodo de um dia so devolve uma linha', count($umDia), 1);

// --------------------------------------------------------- altura e pico
$barras = grafico_alturas_diarias($serie);
checar('mesma quantidade de linhas da serie', count($barras), 5);
checar('dia sem venda fica com altura zero (nao inventa piso)', $barras[0]['altura'], 0.0);
checar('dia sem venda nao e o pico', $barras[0]['pico'], false);
checar('o dia de maior venda e marcado como pico', $barras[3]['pico'], true);
checar('so um dia e pico', array_sum(array_column($barras, 'pico')), 1);
quase('o pico fica em 100% de altura', $barras[3]['altura'], 100.0);
quase('meio do pico (150 de 300) fica em 50%', $barras[1]['altura'], 50.0);

// Todo mundo zerado: nao pode dividir por zero nem inventar um pico.
$zerado = grafico_alturas_diarias(grafico_serie_diaria([], '2026-09-01', '2026-09-03'));
checar('sem venda nenhuma, ninguem e pico', array_sum(array_column($zerado, 'pico')), 0);
checar('sem venda nenhuma, todas as alturas sao zero',
    array_sum(array_column($zerado, 'altura')), 0.0);

// Empate no valor maximo: o primeiro que bater o recorde fica marcado, nao
// os dois — senao o grafico teria "dois picos" sem dizer qual e qual.
$empate = grafico_alturas_diarias(grafico_serie_diaria(
    ['2026-09-01' => 100.0, '2026-09-02' => 100.0], '2026-09-01', '2026-09-02'
));
checar('empate: so o primeiro marca pico', [$empate[0]['pico'], $empate[1]['pico']], [true, false]);

// Piso de 4%: dia com venda pequena continua visivel como barra.
$pequeno = grafico_alturas_diarias(grafico_serie_diaria(
    ['2026-09-01' => 1.0, '2026-09-02' => 10000.0], '2026-09-01', '2026-09-02'
));
checar('venda pequena nao vira barra invisivel (piso de 4%)', $pequeno[0]['altura'] >= 4.0, true);

// ------------------------------------------------------------- montagem HTML
checar('menos de dois dias nao desenha grafico (nada pra comparar)',
    grafico_html_barras_dia([['data' => '2026-09-01', 'valor' => 10.0, 'altura' => 100.0, 'pico' => true]], '2026-09-01', '2026-09-01'),
    '');
checar('com dois dias ou mais, desenha', grafico_html_barras_dia($barras, '2026-09-01', '2026-09-05') !== '', true);
checar('o rotulo direto so aparece uma vez (so o pico)',
    substr_count(grafico_html_barras_dia($barras, '2026-09-01', '2026-09-05'), 'grafico-rotulo'), 1);

// -------------------------------------------------------- barra de pagamento
$porForma = [['forma' => 'Pix', 'total' => 700.0], ['forma' => 'Debit', 'total' => 300.0]];
$rotulos = ['Pix' => 'Pix', 'Debit' => 'Débito'];
$cores = ['Pix' => 'var(--grafico-3)', 'Debit' => 'var(--grafico-1)'];
$htmlPag = grafico_html_pagamento($porForma, $rotulos, $cores);
checar('sem faturamento nenhum, nao desenha nada', grafico_html_pagamento([], $rotulos, $cores), '');
checar('cada forma vira um segmento', substr_count($htmlPag, 'grafico-seg'), 2);
checar('a maior fatia (Pix, 70%) aparece', strpos($htmlPag, '70%') !== false, true);
checar('cor de cada forma e fixa, nao por tamanho', strpos($htmlPag, 'var(--grafico-3)') !== false, true);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
