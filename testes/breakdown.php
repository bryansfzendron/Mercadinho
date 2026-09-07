<?php
declare(strict_types=1);

/*
 * O plano diario da meta.
 *
 * A versao anterior somava o deficit em cima da meta base e realimentava o
 * resultado no dia seguinte: com R$ 3.000 de meta e nada vendido, a meta
 * acumulada terminava em R$ 46.500 e o dia 30 pedia R$ 23.300. Estes testes
 * travam as duas propriedades que impedem isso de voltar:
 *
 *  1. a meta acumulada e a linha reta e SEMPRE fecha na meta do mes;
 *  2. a meta do dia e o que falta dividido pelos dias que restam — sobe
 *     quando se fica para tras, cai quando se adianta, e nunca compoe.
 *
 * Nada aqui toca o MySQL: vendas_relatorio() e substituida por um stub.
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';

date_default_timezone_set('America/Sao_Paulo');

/** Stub do relatorio: devolve a receita por dia que cada caso montar. */
function vendas_relatorio(array $f): array
{
    $linhas = [];
    foreach ($GLOBALS['por_dia'] ?? [] as $dia => $valor) {
        $linhas[] = ['grupo' => $dia, 'receita' => $valor];
    }
    return ['linhas' => $linhas];
}

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
    printf("FALHOU  %s\n   esperado: ~%.2f\n   obtido:   %.2f\n", $nome, $esperado, $obtido);
}

/** Receita igual em todos os dias de setembro. */
function vendeu(float $por_dia, int $ate_dia = 30): void
{
    $GLOBALS['por_dia'] = [];
    for ($d = 1; $d <= $ate_dia; $d++) {
        $GLOBALS['por_dia'][sprintf('2026-09-%02d', $d)] = $por_dia;
    }
}

// -------------------------------------------------- mes inteiro sem vender
$GLOBALS['por_dia'] = [];
$b = metas_breakdown_diario(3000.0, 0.0, '2026-09-01', '2026-09-30');
checar('a tabela cobre o mes inteiro', count($b['diario']), 30);
quase('meta diaria base', $b['resumo']['meta_diaria_base'], 100.0);
quase('a linha reta fecha na meta do mes', end($b['diario'])['acum_meta'], 3000.0);
quase('meta do dia 1', $b['diario'][0]['meta_dia'], 100.0);
// Sem vender nada, o ultimo dia pede tudo o que falta — e nao mais do que isso.
quase('o ultimo dia pede o que falta, nao mais', $b['diario'][29]['meta_dia'], 3000.0);
checar('nenhuma meta do dia passa da meta do mes',
    count(array_filter($b['diario'], static fn($l) => $l['meta_dia'] > 3000.01)), 0);

// ------------------------------------------------ vendeu a meta todo dia
vendeu(100.0);
$b = metas_breakdown_diario(3000.0, 3000.0, '2026-09-01', '2026-09-30');
quase('acumulado bate com a meta', end($b['diario'])['acum_real'], 3000.0);
quase('sem diferenca no fim', end($b['diario'])['diff_acum'], 0.0);
checar('a meta do dia nao muda quando se esta em dia',
    count(array_filter($b['diario'], static fn($l) => abs($l['meta_dia'] - 100.0) > 0.01)), 0);

// ------------------------------------------------------------- superavit
// Adiantando, a meta dos dias seguintes tem que CAIR — a versao anterior so
// sabia subir, embora o texto da tela prometesse os dois.
vendeu(200.0);
$b = metas_breakdown_diario(3000.0, 6000.0, '2026-09-01', '2026-09-30');
$menor = $b['diario'][10]['meta_dia'] < $b['diario'][0]['meta_dia'];
checar('adiantado, a meta do dia cai', $menor, true);
quase('batida a meta, o dia nao pede mais nada', $b['diario'][29]['meta_dia'], 0.0);
quase('a linha reta continua fechando na meta', end($b['diario'])['acum_meta'], 3000.0);

// --------------------------------------------------------------- deficit
// Metade da meta nos 5 primeiros dias: a meta do dia sobe, mas suave.
$GLOBALS['por_dia'] = [];
for ($d = 1; $d <= 5; $d++) {
    $GLOBALS['por_dia'][sprintf('2026-09-%02d', $d)] = 50.0;
}
$b = metas_breakdown_diario(3000.0, 250.0, '2026-09-01', '2026-09-10');
quase('base nao muda com a janela', $b['resumo']['meta_diaria_base'], 100.0);
quase('linha reta no dia 10', $b['diario'][9]['acum_meta'], 1000.0);
quase('realizado acumulado no dia 10', $b['diario'][9]['acum_real'], 250.0);
quase('atras da linha em 750', $b['diario'][9]['diff_acum'], -750.0);
// (3000 - 250) / 21 dias restantes = 130,95
quase('meta do dia 10 sobe pouco', $b['diario'][9]['meta_dia'], 130.95);

// ------------------------------------------------------- dias e rotulos
$b = metas_breakdown_diario(3000.0, 250.0, '2026-09-01', '2026-09-10');
checar('fevereiro tem 28 dias',
    count(metas_breakdown_diario(100.0, 0.0, '2026-02-01', '2026-02-28')['diario']), 28);
checar('o dia traz a data cheia', $b['diario'][0]['data'], '2026-09-01');
checar('dia passado nao e futuro', [$b['diario'][0]['passado'], $b['diario'][0]['futuro']], [true, false]);

// ------------------------------------------------------------- sem meta
$GLOBALS['por_dia'] = [];
$b = metas_breakdown_diario(0.0, 0.0, '2026-09-01', '2026-09-30');
quase('sem meta, a base e zero', $b['resumo']['meta_diaria_base'], 0.0);
quase('sem meta, nada falta', $b['resumo']['falta'], 0.0);
checar('sem meta, nenhum dia pede nada',
    count(array_filter($b['diario'], static fn($l) => $l['meta_dia'] > 0.0)), 0);

// Ja vendeu mais do que a meta: nao pode faltar valor negativo.
vendeu(500.0);
$b = metas_breakdown_diario(3000.0, 15000.0, '2026-09-01', '2026-09-30');
quase('meta estourada nao vira falta negativa', $b['resumo']['falta'], 0.0);
quase('nem meta do dia negativa', $b['diario'][29]['meta_dia'], 0.0);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
