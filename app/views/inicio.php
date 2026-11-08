<?php
/**
 * A capa do app: quanto vendeu hoje e como foi a semana.
 *
 * Um cartao so, com tres caras. "Atual" responde de relance (hoje em numero
 * grande, o mes logo abaixo); "Semana" abre o grafico de sete colunas e deixa
 * tocar num dia para ver aquele dia — sem dia escolhido, o numero e a semana
 * inteira; "Mes" mostra o mes ate hoje, dia a dia.
 *
 * O mes tambem e tocavel, do mesmo jeito: tocar num dia mostra aquele dia,
 * tocar de novo volta para o mes. Trinta e uma barras finas sao alvo ruim de
 * dedo, entao cada dia ocupa a faixa inteira do grafico (sem vao) e da para
 * arrastar o dedo de lado para correr os dias.
 *
 * Embaixo do cartao, o que saiu hoje. Escanear e bipar nao moram mais aqui:
 * os dois botoes ja estao na tela de notas, e repetidos na capa so empurravam
 * a lista do dia para baixo da dobra.
 *
 * A lista segue o grafico: tocar num dia troca os produtos junto com o numero.
 * Os blocos (cada dia da semana e do mes, mais a semana e o mes inteiros) ja
 * vem prontos do servidor e o JS so troca qual esta visivel — assim o preco
 * continua sendo formatado num lugar so, e trocar de dia nao espera rede.
 *
 * @var array $painel   de vendas_painel()
 * Cada produto mostra o que sobrou depois da mercadoria e dos percentuais
 * que acompanham o faturamento (maquininha, condominio, franquia, imposto),
 * e quanto custou — a mesma conta e os mesmos numeros da aba de vendas.
 *
 * @var array $vendidos 'Y-m-d' => produtos daquele dia, mais 'semana' e 'mes'
 * @var array $pdvs     os PDVs com venda, para o filtro
 * @var int   $pdv_id   o PDV mostrado; 0 e todos
 */
$h = $painel['hoje'];
$m = $painel['mes'];
$s = $painel['semana'];

/** "no Domingo" e "na Segunda": o artigo segue o genero do dia. */
$artigos = ['no', 'na', 'na', 'na', 'na', 'na', 'no'];

/**
 * Uma lista de produtos vendidos, ou o aviso de que nao houve nenhum.
 *
 * Cada produto abre e mostra em quais mercadinhos saiu naquele dia (ou na
 * semana, ou no mes) — o miolo vem de /vendas/produto ao abrir, com o mesmo
 * periodo e o mesmo PDV da lista, e a ficha do produto fica la dentro.
 */
$lista_vendidos = static function (array $produtos, string $vazio, string $de, string $ate) use ($pdv_id): string {
    if (!$produtos) {
        return '<p class="vazio">' . e($vazio) . '</p>';
    }

    $html = '<ul class="lista">';
    foreach (array_values($produtos) as $n_prod => $p) {
        $qtd = num_br($p['quantidade']);
        // Unitario e conta, nao coluna: o item grava o total da linha.
        $unitario = $qtd > 0 ? (float) $p['total'] / $qtd : null;

        // Custo e contribuicao vem prontos da rota, com a mesma conta da
        // aba de vendas. Produto sem custo conhecido volta null, e a segunda
        // linha simplesmente nao aparece: melhor faltar do que inventar.
        $sobra = $p['contribuicao'] ?? null;

        $linha = '<div class="linha-topo">'
               . '<span class="forte">' . e($p['descricao']) . '</span>'
               . '<span class="valor">' . moeda($p['total']) . '</span>'
               . '</div><div class="linha-baixo">'
               . '<span>' . e(qtd_fmt($p['quantidade'])) . ' un'
               . ($unitario !== null ? ' · ' . moeda($unitario) . ' cada' : '') . '</span>'
               . ($sobra !== null
                    ? '<span class="' . ($sobra >= 0 ? 'lucro-bom' : 'lucro-ruim') . '">'
                      . ($sobra >= 0 ? '+' : '−') . moeda(abs($sobra))
                      . (isset($p['fator']) && $p['fator'] !== null
                            ? ' · ' . number_format((float) $p['fator'], 2, ',', '.') . 'x'
                            : '')
                      . '</span>'
                    : '')
               . '</div>';

        if (($p['custo'] ?? null) !== null) {
            $linha .= '<div class="linha-baixo">'
                    . '<span class="ajuda">custo ' . moeda($p['custo'])
                    . (empty($p['com_nota']) ? ' <span class="estimado">estimado</span>' : '')
                    . '</span>'
                    . '</div>';
        }

        $detalhe = '/vendas/produto?' . http_build_query([
            'grupo' => (string) $p['grupo'], 'de' => $de, 'ate' => $ate, 'pdv_id' => $pdv_id ?: null,
        ]);
        $html .= '<li' . ($n_prod >= LISTA_A_VISTA ? ' class="extra"' : '') . '><details class="compra" data-detalhe="' . e($detalhe) . '">'
               . '<summary>' . $linha . '</summary>'
               . '<div data-detalhe-corpo><p class="ajuda">Carregando…</p></div>'
               . '</details></li>';
    }
    return $html . '</ul>';
};
?>
<h1 class="titulo-painel titulo-icone"><?= icone('inicio') ?>Início</h1>
<p class="inicio-sub">Gestão do seu negócio</p>

<?php // O mesmo aviso do topo da Loja: sem conta TouchPay, a capa fica zerada. ?>
<?= conta_aviso_loja() ?>

<?php // Com um PDV so, o filtro escolheria entre ele e ele mesmo. ?>
<?php if (count($pdvs) > 1 || $pdv_id > 0): ?>
<?php
$opcoes_pdv = ['0' => 'Todos os pontos de venda'];
foreach ($pdvs as $p) {
    $opcoes_pdv[(string) (int) $p['id']] = (string) $p['nome'];
}
?>
<div class="cartao filtros-cartao">
    <form method="get" action="/">
        <div class="vf-linha">
            <?= campo_escolha('pdv', 'pdv_id', $opcoes_pdv, (string) $pdv_id, 'Ponto de venda') ?>
        </div>
    </form>
</div>
<?php endif; ?>

<section class="painel" data-painel>
    <div class="segmento" role="tablist" aria-label="Período" style="--itens:3">
        <span class="segmento-pilula" aria-hidden="true"></span>
        <button type="button" class="segmento-aba ativo" role="tab" id="aba-atual"
                aria-selected="true" aria-controls="painel-atual" data-aba="atual">Atual</button>
        <button type="button" class="segmento-aba" role="tab" id="aba-semana"
                aria-selected="false" aria-controls="painel-semana" data-aba="semana">Semana</button>
        <button type="button" class="segmento-aba" role="tab" id="aba-mes"
                aria-selected="false" aria-controls="painel-mes" data-aba="mes">Mês</button>
    </div>

    <div class="painel-corpo" id="painel-atual" role="tabpanel" aria-labelledby="aba-atual" data-corpo="atual">
        <p class="painel-rotulo">Hoje</p>
        <p class="painel-valor"><?= moeda($h['total']) ?></p>

        <div class="painel-divisor">
            <span aria-hidden="true"></span>
            <a href="/transacoes">Ver detalhes</a>
        </div>

        <div class="painel-kpis">
            <div>
                <span>Total do mês</span>
                <strong><?= moeda($m['total']) ?></strong>
            </div>
            <div>
                <span>Ticket médio</span>
                <strong><?= moeda($m['ticket']) ?></strong>
            </div>
            <div>
                <span>Transações</span>
                <strong><?= (int) $m['vendas'] ?></strong>
            </div>
        </div>
    </div>

    <div class="painel-corpo" id="painel-semana" role="tabpanel" aria-labelledby="aba-semana"
         data-corpo="semana" hidden>
        <p class="painel-rotulo" data-rotulo
           data-padrao="Vendas desta semana">Vendas desta semana</p>
        <p class="painel-valor" data-valor
           data-padrao="<?= e(moeda($s['total'])) ?>"><?= moeda($s['total']) ?></p>

        <div class="painel-grafico">
            <div class="painel-barras" data-barras>
                <?php foreach ($s['barras'] as $i => $b): ?>
                    <?php
                    $classe = 'painel-coluna'
                            . ($b['hoje'] ? ' hoje' : '')
                            . ($b['futuro'] ? ' futuro' : '');
                    $rotulo = dia_semana_longo($b['data']) . ', ' . date('d/m', strtotime($b['data']));
                    ?>
                    <button type="button" class="<?= $classe ?>" aria-pressed="false"
                            data-data="<?= e($b['data']) ?>"
                            data-dia="<?= e($rotulo) ?>" data-moeda="<?= e(moeda($b['valor'])) ?>"
                            title="<?= e($rotulo . ': ' . ($b['valor'] > 0 ? moeda($b['valor']) : 'sem venda')) ?>">
                        <span class="painel-barra"
                              style="height:<?= number_format($b['altura'], 1, '.', '') ?>%"></span>
                        <span class="painel-dia"><?= e($b['dia']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="painel-corpo" id="painel-mes" role="tabpanel" aria-labelledby="aba-mes"
         data-corpo="mes" hidden>
        <p class="painel-rotulo" data-rotulo
           data-padrao="Vendas deste mês">Vendas deste mês</p>
        <p class="painel-valor" data-valor
           data-padrao="<?= e(moeda($m['total'])) ?>"><?= moeda($m['total']) ?></p>

        <?php
        // Mesmo grafico do dashboard, vestido de painel. Vazio com menos de
        // dois dias: no dia 1 do mes uma barra sozinha nao e um grafico.
        $grafico_mes = grafico_html_barras_dia($m['barras'], $m['de'], $m['ate'], true);
        ?>
        <?php if ($grafico_mes !== ''): ?>
            <div class="painel-grafico painel-mes"><?= $grafico_mes ?></div>
        <?php else: ?>
            <p class="painel-rodape"><?= (int) $m['vendas'] ?> transações neste mês.</p>
        <?php endif; ?>
    </div>
</section>

<?php // Um bloco por dia que o grafico deixa escolher (a semana pode comecar no
      // mes passado, e o mes tem dias fora desta semana), mais a semana e o mes
      // inteiros. Um visivel por vez: quem troca e o inicio.js, junto com a aba
      // e com a barra escolhida no grafico.
$dias = array_unique(array_merge(array_column($s['barras'], 'data'), array_column($m['barras'], 'data')));
sort($dias);
?>
<div data-vendidos data-hoje="<?= e($h['data']) ?>">
    <?php foreach ($dias as $dia): ?>
        <?php
        $e_hoje = $dia === $h['data'];
        $titulo = $e_hoje
            ? 'Vendidos hoje'
            : 'Vendidos ' . $artigos[(int) date('w', strtotime($dia))] . ' ' . dia_semana_longo($dia)
              . ', ' . date('d/m', strtotime($dia));
        $vazio = $e_hoje ? 'Nenhum produto vendido hoje ainda.' : 'Nenhum produto vendido nesse dia.';
        ?>
        <?php $lista = $vendidos[$dia] ?? []; ?>
        <section class="cartao recolhivel" data-lista="<?= e($dia) ?>" <?= $e_hoje ? '' : 'hidden' ?>>
            <?= cartao_cabeca('carrinho', $titulo, count($lista) . ' produto(s)', '', true) ?>
            <?= $lista_vendidos($lista, $vazio, $dia, $dia) ?>
            <?= count($lista) > LISTA_A_VISTA ? ver_mais_baixo('Ver todos (' . count($lista) . ')') : '' ?>
        </section>
    <?php endforeach; ?>

    <?php $lista = $vendidos['semana'] ?? []; ?>
    <section class="cartao recolhivel" data-lista="semana" hidden>
        <?= cartao_cabeca('carrinho', 'Vendidos na semana', count($lista) . ' produto(s)', '', true) ?>
        <?= $lista_vendidos($lista, 'Nenhum produto vendido nesta semana.', $s['de'], $s['ate']) ?>
        <?= count($lista) > LISTA_A_VISTA ? ver_mais_baixo('Ver todos (' . count($lista) . ')') : '' ?>
    </section>

    <?php $lista = $vendidos['mes'] ?? []; ?>
    <section class="cartao recolhivel" data-lista="mes" hidden>
        <?= cartao_cabeca('carrinho', 'Vendidos no mês', count($lista) . ' produto(s)', '', true) ?>
        <?= $lista_vendidos($lista, 'Nenhum produto vendido neste mês.', $m['de'], $m['ate']) ?>
        <?= count($lista) > LISTA_A_VISTA ? ver_mais_baixo('Ver todos (' . count($lista) . ')') : '' ?>
    </section>
</div>
