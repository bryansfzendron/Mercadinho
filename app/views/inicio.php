<?php
/**
 * A capa do app: quanto vendeu hoje e como foi a semana.
 *
 * Um cartao so, com tres caras. "Atual" responde de relance (hoje em numero
 * grande, o mes logo abaixo); "Semana" abre o grafico de sete colunas e deixa
 * tocar num dia para ver aquele dia — sem dia escolhido, o numero e a semana
 * inteira; "Mes" mostra o mes ate hoje, dia a dia.
 *
 * So a semana e tocavel: sete colunas gordas sao alvo de dedo, trinta e uma
 * barras finas nao — o mes e panorama, e quem quer um dia dele tem o filtro
 * de periodo em Loja > Vendas.
 *
 * Embaixo do cartao, o que saiu hoje. Escanear e bipar nao moram mais aqui:
 * os dois botoes ja estao na tela de notas, e repetidos na capa so empurravam
 * a lista do dia para baixo da dobra.
 *
 * A lista segue o grafico: tocar num dia troca os produtos junto com o numero.
 * Os oito blocos (sete dias + a semana) ja vem prontos do servidor e o JS so
 * troca qual esta visivel — assim o preco continua sendo formatado num lugar
 * so, e trocar de dia nao espera rede.
 *
 * @var array $painel   de vendas_painel()
 * Cada produto mostra o que sobrou depois da mercadoria e dos percentuais
 * que acompanham o faturamento (maquininha, condominio, franquia, imposto),
 * e quanto custou — a mesma conta e os mesmos numeros da aba de vendas.
 *
 * @var array $vendidos 'Y-m-d' => produtos daquele dia, mais 'semana'
 */
$h = $painel['hoje'];
$m = $painel['mes'];
$s = $painel['semana'];

/** Nome cheio do dia, para o rotulo que aparece ao tocar numa barra. */
$longos  = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
$artigos = ['no', 'na', 'na', 'na', 'na', 'na', 'no'];

/** Uma lista de produtos vendidos, ou o aviso de que nao houve nenhum. */
$lista_vendidos = static function (array $produtos, string $vazio): string {
    if (!$produtos) {
        return '<p class="vazio">' . e($vazio) . '</p>';
    }

    $html = '<ul class="lista">';
    foreach ($produtos as $p) {
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

        $html .= '<li>' . ((int) $p['produto_id'] > 0
            ? '<a href="/produtos/' . (int) $p['produto_id'] . '">' . $linha . '</a>'
            : '<div class="linha-cartao">' . $linha . '</div>') . '</li>';
    }
    return $html . '</ul>';
};
?>
<p class="inicio-sub">Gestão do seu negócio</p>

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
        <p class="painel-rotulo" data-semana-rotulo
           data-padrao="Vendas desta semana">Vendas desta semana</p>
        <p class="painel-valor" data-semana-valor
           data-padrao="<?= e(moeda($s['total'])) ?>"><?= moeda($s['total']) ?></p>

        <div class="painel-grafico">
            <div class="painel-barras">
                <?php foreach ($s['barras'] as $i => $b): ?>
                    <?php
                    $classe = 'painel-coluna'
                            . ($b['hoje'] ? ' hoje' : '')
                            . ($b['futuro'] ? ' futuro' : '');
                    $rotulo = $longos[$i] . ', ' . date('d/m', strtotime($b['data']));
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
        <p class="painel-rotulo">Vendas deste mês</p>
        <p class="painel-valor"><?= moeda($m['total']) ?></p>

        <?php
        // Mesmo grafico do dashboard, vestido de painel. Vazio com menos de
        // dois dias: no dia 1 do mes uma barra sozinha nao e um grafico.
        $grafico_mes = grafico_html_barras_dia($m['barras'], $m['de'], $m['ate']);
        ?>
        <?php if ($grafico_mes !== ''): ?>
            <div class="painel-grafico painel-mes"><?= $grafico_mes ?></div>
        <?php else: ?>
            <p class="painel-rodape"><?= (int) $m['vendas'] ?> transações neste mês.</p>
        <?php endif; ?>
    </div>
</section>

<?php // Oito blocos, um visivel por vez: os sete dias e a semana inteira. Quem
      // troca e o inicio.js, junto com a aba e com a barra escolhida no grafico. ?>
<div data-vendidos data-hoje="<?= e($h['data']) ?>">
    <?php foreach ($s['barras'] as $i => $b): ?>
        <?php
        // "no Domingo" e "na Segunda": o artigo segue o genero do dia.
        $titulo = $b['hoje']
            ? 'Vendidos hoje'
            : 'Vendidos ' . $artigos[$i] . ' ' . $longos[$i]
              . ', ' . date('d/m', strtotime($b['data']));
        $vazio = $b['hoje'] ? 'Nenhum produto vendido hoje ainda.' : 'Nenhum produto vendido nesse dia.';
        ?>
        <section data-lista="<?= e($b['data']) ?>" <?= $b['hoje'] ? '' : 'hidden' ?>>
            <h2><?= e($titulo) ?></h2>
            <?= $lista_vendidos($vendidos[$b['data']] ?? [], $vazio) ?>
        </section>
    <?php endforeach; ?>

    <section data-lista="semana" hidden>
        <h2>Vendidos na semana</h2>
        <?= $lista_vendidos($vendidos['semana'] ?? [], 'Nenhum produto vendido nesta semana.') ?>
    </section>

    <section data-lista="mes" hidden>
        <h2>Vendidos no mês</h2>
        <?= $lista_vendidos($vendidos['mes'] ?? [], 'Nenhum produto vendido neste mês.') ?>
    </section>
</div>
