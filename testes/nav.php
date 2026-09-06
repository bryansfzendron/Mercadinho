<?php
declare(strict_types=1);

/*
 * O menu de baixo tem que acender UM item por rota — e as telas de dentro de
 * Notas (escanear e lancamento manual) acendem "Notas", ja que "Nota" deixou
 * de ser item proprio.
 *
 * Renderiza o layout de verdade, sem banco: as duas funcoes que dependem de
 * sessao/MySQL sao substituidas por versoes de teste.
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';

/** Fora do servidor nao ha sessao para iniciar. */
function iniciar_sessao(): void {}

/** usuario_atual() mora no auth.php, que precisa de banco. */
function usuario_atual(): ?array
{
    return ['id' => 1, 'nome' => 'Teste'];
}

$_SESSION = [];

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

/** @return string[] hrefs dos itens marcados como ativos */
function ativos(string $rota): array
{
    $_SERVER['REQUEST_URI'] = $rota;
    $conteudo = '';
    $titulo = 'teste';

    ob_start();
    require APP . '/views/layout.php';
    $html = ob_get_clean();

    preg_match_all('#<a href="([^"]+)"\s+class="([^"]*)"#', $html, $m, PREG_SET_ORDER);
    $lista = [];
    foreach ($m as $a) {
        if (trim($a[2]) === 'ativo') {
            $lista[] = $a[1];
        }
    }
    return $lista;
}

$rotas = [
    '/'            => '/',
    '/escanear'    => '/notas',
    '/manual'      => '/notas',
    '/notas'       => '/notas',
    '/notas/12'    => '/notas',
    '/bipar'       => '/bipar',
    '/produtos'    => '/produtos',
    '/produtos/3'  => '/produtos',
    '/loja'        => '/loja',
];

foreach ($rotas as $rota => $esperado) {
    checar("menu em $rota", ativos($rota), [$esperado]);
}

// O item "Nota" separado nao existe mais.
$_SERVER['REQUEST_URI'] = '/notas';
$conteudo = ''; $titulo = 'teste';
ob_start(); require APP . '/views/layout.php'; $html = ob_get_clean();
preg_match_all('#<nav class="barra">(.*?)</nav>#s', $html, $m);
$menu = $m[1][0] ?? '';
checar('menu tem 5 itens', substr_count($menu, '<a href='), 5);
checar('nao ha item so de escanear', strpos($menu, 'href="/escanear"'), false);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
