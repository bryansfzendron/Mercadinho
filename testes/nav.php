<?php
declare(strict_types=1);

/*
 * O menu de baixo tem que acender UM item por rota. As telas de dentro
 * acendem o item a que pertencem: escanear e lancamento manual acendem
 * "Notas", bipar acende "Produtos" e o relatorio de vendas acende "Loja" —
 * nenhuma delas tem item proprio.
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
    '/dashboard'   => '/dashboard',
    '/escanear'    => '/notas',
    '/manual'      => '/notas',
    '/notas'       => '/notas',
    '/notas/12'    => '/notas',
    '/bipar'       => '/produtos',
    '/produtos'    => '/produtos',
    '/produtos/3'  => '/produtos',
    '/loja'        => '/loja',
    '/margens'     => '/loja',
    '/vendas'      => '/loja',
    // A lista de transacoes e uma tela de dentro de Vendas: acende Loja igual.
    '/vendas/transacoes' => '/loja',
    '/metas'       => '/loja',
];

foreach ($rotas as $rota => $esperado) {
    checar("menu em $rota", ativos($rota), [$esperado]);
}

// Configuracoes mora na engrenagem do topo, nao na barra de baixo: nenhum
// item pode acender, senao o usuario acha que esta dentro daquela secao.
foreach (['/config', '/config/pdvs', '/config/metas', '/config/taxas'] as $rota) {
    checar("menu em $rota nao acende nada", ativos($rota), []);
}

// Os itens "Nota" e "Bipar" separados nao existem mais.
$_SERVER['REQUEST_URI'] = '/notas';
$conteudo = ''; $titulo = 'teste';
ob_start(); require APP . '/views/layout.php'; $html = ob_get_clean();
preg_match_all('#<nav class="barra">(.*?)</nav>#s', $html, $m);
$menu = $m[1][0] ?? '';
checar('menu tem 5 itens', substr_count($menu, '<a href='), 5);
checar('nao ha item so de margens', strpos($menu, 'href="/margens"'), false);
checar('nao ha item so de escanear', strpos($menu, 'href="/escanear"'), false);
checar('nao ha item so de bipar', strpos($menu, 'href="/bipar"'), false);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
