<?php
declare(strict_types=1);

/*
 * Testa a busca de codigo de barras pelo nome (nota manual): como as duas
 * fontes viram uma lista so. Nada aqui toca o MySQL nem a rede.
 *
 *   php testes/produtos_sugerir.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/produtos.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

checar('palavras: tira as de uma letra', produtos_palavras('arroz e feijao'), ['arroz', 'feijao']);
checar('palavras: espacos sobrando', produtos_palavras('  leite   moca '), ['leite', 'moca']);
checar('palavras: vazio', produtos_palavras('   '), []);
checar('palavras: no maximo seis', count(produtos_palavras('a1 b2 c3 d4 e5 f6 g7 h8')), 6);

$notas = [
    ['nome' => 'LEITE COND MOCA 395G', 'ean' => '7891000100103', 'ultimo' => '6.4900'],
    // mesma nota mais velha do mesmo codigo: o preco que vale e o primeiro
    ['nome' => 'LEITE COND MOCA 395G', 'ean' => '7891000100103', 'ultimo' => '5.9900'],
    ['nome' => 'CREME DE LEITE NESTLE', 'ean' => '7891000053508', 'ultimo' => '3.2000'],
];
$loja = [
    ['nome' => 'Leite Moça 395g', 'ean' => '7891000100103'],
    ['nome' => 'Leite Ninho 400g', 'ean' => '7891000061190'],
];
$r = produtos_sugestoes_juntar($notas, $loja, 'leite');
checar('um item por codigo', count($r), 3);
checar('o que voce comprou vem antes', array_column($r, 'fonte'), ['nota', 'nota', 'loja']);
checar('nome da nota ganha do da loja', $r[0]['nome'] === 'LEITE COND MOCA 395G' || $r[1]['nome'] === 'LEITE COND MOCA 395G', true);
$moca = array_values(array_filter($r, static fn ($x) => $x['ean'] === '7891000100103'))[0];
checar('preco da nota mais nova', $moca['ultimo'], 6.49);
checar('comeca com o digitado sobe', $r[0]['nome'], 'LEITE COND MOCA 395G');
checar('so da loja: sem preco', $r[2]['ultimo'], null);
checar('limite', count(produtos_sugestoes_juntar($notas, $loja, 'leite', 1)), 1);
checar('codigo vazio fica fora', produtos_sugestoes_juntar([['nome' => 'X', 'ean' => '', 'ultimo' => null]], [], 'x'), []);

echo "\n{$ok} passaram, {$falhou} falharam\n";
exit($falhou > 0 ? 1 : 0);
