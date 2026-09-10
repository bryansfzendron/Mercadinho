<?php /** @var array $res @var array $res_ant @var array $variacao @var array $top @var array $formas_map @var array $formas_cores @var array $barras_dia @var array $r @var string $chave @var string $rotulo @var string $de @var string $ate @var array $periodos @var int $pdv_id @var array $pdvs */ ?>
<div class="acoes-topo">
    <h1 style="margin:0">Dashboard</h1>
    <div class="chips" style="margin-top:.5rem">
        <?php foreach ($periodos as $k => [$lbl, $d, $a]): ?>
            <a class="chip <?= $chave === $k ? 'ativo' : '' ?>" href="/dashboard?periodo=<?= e($k) ?><?= $pdv_id ? '&pdv_id=' . (int) $pdv_id : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<form method="get" action="/dashboard" style="margin-bottom:.75rem">
    <label>Ponto de venda
        <select name="pdv_id" onchange="this.form.submit()">
            <option value="">todos</option>
            <?php foreach ($pdvs as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= $pdv_id === (int) $p['id'] ? 'selected' : '' ?>>
                    <?= e($p['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <input type="hidden" name="periodo" value="<?= e($chave) ?>">
</form>

<div class="numeros" style="gap:.5rem">
    <div class="numero kpi <?= kpi_moldura($variacao['receita']) ?>">
        <span class="kpi-rotulo">Faturamento</span>
        <strong class="kpi-valor"><?= moeda($res['receita']) ?></strong>
        <?php if ($variacao['receita'] !== null): ?>
            <span class="kpi-var <?= $variacao['receita'] >= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['receita'] >= 0 ? '+' : '' ?><?= number_format($variacao['receita'], 1, ',', '.') ?>%
            </span>
        <?php endif; ?>
    </div>

    <?php // No CMV subir e ruim: a moldura inverte. ?>
    <div class="numero kpi <?= kpi_moldura($variacao['cmv'], true) ?>">
        <span class="kpi-rotulo">Mercadoria</span>
        <strong class="kpi-valor negativo">− <?= moeda($res['cmv']) ?></strong>
        <?php if ($variacao['cmv'] !== null): ?>
            <span class="kpi-var <?= $variacao['cmv'] <= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['cmv'] >= 0 ? '+' : '' ?><?= number_format($variacao['cmv'], 1, ',', '.') ?>%
            </span>
        <?php endif; ?>
    </div>

    <div class="numero kpi <?= kpi_moldura($variacao['lucro']) ?>">
        <span class="kpi-rotulo">Lucro líquido</span>
        <strong class="kpi-valor <?= $res['lucro'] >= 0 ? '' : 'negativo' ?>">
            <?= $res['lucro'] >= 0 ? '+' : '−' ?><?= moeda(abs($res['lucro'])) ?>
        </strong>
        <?php if ($variacao['lucro'] !== null): ?>
            <span class="kpi-var <?= $variacao['lucro'] >= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['lucro'] >= 0 ? '+' : '' ?><?= number_format($variacao['lucro'], 1, ',', '.') ?>%
            </span>
        <?php endif; ?>
    </div>

    <div class="numero kpi <?= kpi_moldura($variacao['margem']) ?>">
        <span class="kpi-rotulo">Margem</span>
        <strong class="kpi-valor"><?= number_format($res['margem'], 1, ',', '.') ?>%</strong>
        <?php if ($variacao['margem'] !== null): ?>
            <span class="kpi-var <?= $variacao['margem'] >= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['margem'] >= 0 ? '+' : '' ?><?= number_format($variacao['margem'], 1, ',', '.') ?>pp
            </span>
        <?php endif; ?>
    </div>
</div>

<?php
// O que sai alem da mercadoria. Sem esta linha o leitor faz
// "faturamento - mercadoria" e nao chega no lucro que esta ao lado.
$outros = (float) $res['taxa'] + (float) $res['condominio']
        + (float) $res['franquia'] + (float) $res['imposto'] + (float) $res['fixos'];
?>
<p class="ajuda centro">
    Além da mercadoria saem <strong><?= moeda($outros) ?></strong> de maquininha,
    condomínio, franquia, imposto e fixos — o detalhe está no resumo do período, no fim da tela.
</p>

<?php if ($barras_dia): ?>
    <div class="cartao">
        <h2 class="sem-topo">Faturamento por dia</h2>
        <?= grafico_html_barras_dia($barras_dia, $de, $ate) ?>
    </div>
<?php endif; ?>

<div class="cartao">
    <h2 class="sem-topo">Como pagaram no período</h2>
    <?php if (!$r['por_forma']): ?>
        <p class="vazio">Sem venda no período.</p>
    <?php else: ?>
    <?= grafico_html_pagamento($r['por_forma'], $formas_map, $formas_cores) ?>
    <div class="numeros" style="gap:.5rem; margin-top:.8rem">
        <?php foreach ($r['por_forma'] ?? [] as $pf): ?>
            <div class="numero" style="min-width:110px">
                <span class="kpi-rotulo"><?= e($formas_map[$pf['forma']] ?? $pf['forma']) ?></span>
                <strong class="kpi-valor"><?= moeda($pf['total']) ?></strong>
                <span class="ajuda"><?= (int) $pf['n'] ?> vendas · <?= number_format(custo_taxa_da_forma($pf['forma'], $r['parametros']), 2, ',', '.') ?>% taxa</span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="cartao">
    <h2 class="sem-topo">Top 5 produtos por contribuição</h2>
    <?php if (!$top): ?>
        <p class="vazio">Sem vendas no período.</p>
    <?php else: ?>
        <?php
        // Barra proporcional ao lado do numero: compara os 5 dum relance,
        // sem precisar ler e comparar texto. Escala pelo maior |valor| dos
        // 5, nao pelo grafico de vendas inteiro — e so pra comparar esses.
        $maiorContrib = 0.0;
        foreach ($top as $l) {
            $maiorContrib = max($maiorContrib, abs((float) $l['contribuicao']));
        }
        ?>
        <ul class="lista">
            <?php foreach ($top as $l): ?>
                <li><div class="linha-cartao">
                    <div class="linha-topo">
                        <span class="forte"><?= e($l['descricao'] !== '' ? $l['descricao'] : $l['grupo']) ?></span>
                        <span class="valor"><?= moeda($l['receita']) ?></span>
                    </div>
                    <div class="linha-baixo">
                        <span>
                            <?= qtd_fmt($l['quantidade']) ?> un ·
                            custo <?= moeda($l['custo']) ?>
                            <?php if (!$l['com_nota']): ?><span class="estimado">estimado</span><?php endif; ?>
                        </span>
                        <span class="<?= $l['contribuicao'] >= 0 ? 'lucro-bom' : 'lucro-ruim' ?>">
                            <?= $l['contribuicao'] >= 0 ? '+' : '−' ?><?= moeda(abs($l['contribuicao'])) ?>
                            <?php if ($l['fator'] !== null): ?> · <?= number_format($l['fator'], 2, ',', '.') ?>x<?php endif; ?>
                        </span>
                    </div>
                    <?php $largura = $maiorContrib > 0 ? abs((float) $l['contribuicao']) / $maiorContrib * 100 : 0; ?>
                    <div class="grafico-contrib <?= $l['contribuicao'] >= 0 ? 'bom' : 'ruim' ?>">
                        <span style="width: <?= number_format($largura, 1, '.', '') ?>%"></span>
                    </div>
                </div></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="cartao">
    <h2 class="sem-topo">Resumo do período</h2>
    <dl class="dados">
        <div><dt>Período</dt><dd><?= e($rotulo) ?> (<?= data_fmt($de) ?> a <?= data_fmt($ate) ?>)</dd></div>
        <div><dt>Dias</dt><dd><?= (int) $res['dias'] ?></dd></div>
        <div><dt>Vendas</dt><dd><?= (int) $res['vendas'] ?></dd></div>
        <div><dt>Faturamento</dt><dd class="valor"><?= moeda($res['receita']) ?></dd></div>
        <div><dt>CMV</dt><dd class="valor negativo">− <?= moeda($res['cmv']) ?></dd></div>
        <div><dt>Maquininha</dt><dd class="valor negativo">− <?= moeda($res['taxa']) ?></dd></div>
        <div><dt>Condomínio</dt><dd class="valor negativo">− <?= moeda($res['condominio']) ?></dd></div>
        <div><dt>Franquia</dt><dd class="valor negativo">− <?= moeda($res['franquia']) ?></dd></div>
        <div><dt>Imposto (Simples Nacional)</dt><dd class="valor negativo">− <?= moeda($res['imposto']) ?></dd></div>
        <div><dt>Energia, sistema e internet</dt><dd class="valor negativo">− <?= moeda($res['fixos']) ?></dd></div>
        <div><dt>Lucro líquido</dt><dd class="valor <?= $res['lucro'] >= 0 ? '' : 'negativo' ?>">
            <?= $res['lucro'] >= 0 ? '+' : '−' ?><?= moeda(abs($res['lucro'])) ?>
        </dd></div>
        <div><dt>Margem</dt><dd class="valor"><?= number_format($res['margem'], 1, ',', '.') ?>%</dd></div>
    </dl>
</div>

<p class="ajuda centro" style="margin-top:1rem">
    <a href="/vendas">Ver relatório detalhado com filtros</a> · <a href="/metas">Acompanhar metas do mês</a>
</p>
