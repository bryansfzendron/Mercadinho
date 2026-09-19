<?php /** @var array $res @var array $res_ant @var array $variacao @var float $custos_outros @var array $top @var array $formas_map @var array $formas_cores @var array $barras_dia @var array $r @var string $chave @var string $rotulo @var string $de @var string $ate @var array $periodos @var int $pdv_id @var array $pdvs */ ?>
<?php
/** Percentual com sinal, do jeito que o KPI escreve: +12,3% / −4,0pp. */
$var_txt = static fn (float $v, string $un = '%'): string =>
    ($v >= 0 ? '+' : '−') . number_format(abs($v), 1, ',', '.') . $un;

/** Em qual das abas a pilula do periodo para. */
$indice = array_search($chave, array_keys($periodos), true) ?: 0;
?>
<h1 class="titulo-painel">Dashboard</h1>
<p class="inicio-sub"><?= e($rotulo) ?> · <?= data_fmt($de) ?> a <?= data_fmt($ate) ?></p>

<section class="painel">
    <?php // Mesmo controle segmentado da capa, so que cada aba e uma pagina:
          // sem JS, e a pilula desliza de um periodo ao outro na troca de tela. ?>
    <nav class="segmento segmento-links" aria-label="Período"
         style="--itens:<?= count($periodos) ?>;--indice:<?= (int) $indice ?>">
        <span class="segmento-pilula" aria-hidden="true"></span>
        <?php foreach ($periodos as $k => $p): ?>
            <?php // Rotulo curto aqui: sao quatro colunas na largura de um celular. ?>
            <a class="segmento-aba <?= $chave === $k ? 'ativo' : '' ?>"
               <?= $chave === $k ? 'aria-current="page"' : '' ?>
               title="<?= e($p[0]) ?>"
               href="/dashboard?periodo=<?= e($k) ?><?= $pdv_id ? '&pdv_id=' . (int) $pdv_id : '' ?>"><?= e($p[3] ?? $p[0]) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="painel-corpo">
        <p class="painel-rotulo">Faturamento</p>
        <p class="painel-valor"><?= moeda($res['receita']) ?></p>
        <?php if ($variacao['receita'] !== null): ?>
            <span class="painel-var <?= $variacao['receita'] >= 0 ? 'pos' : 'neg' ?>">
                <?= $var_txt($variacao['receita']) ?> vs. período anterior
            </span>
        <?php endif; ?>

        <div class="painel-divisor">
            <span aria-hidden="true"></span>
            <a href="/vendas?de=<?= e($de) ?>&ate=<?= e($ate) ?>">Ver relatório</a>
        </div>

        <div class="painel-kpis">
            <div>
                <span>Lucro líquido</span>
                <strong class="<?= $res['lucro'] >= 0 ? '' : 'negativo' ?>">
                    <?= $res['lucro'] >= 0 ? '+' : '−' ?><?= moeda(abs($res['lucro'])) ?>
                </strong>
                <?php if ($variacao['lucro'] !== null): ?>
                    <span class="painel-var <?= $variacao['lucro'] >= 0 ? 'pos' : 'neg' ?>">
                        <?= $var_txt($variacao['lucro']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div>
                <span>Margem</span>
                <strong><?= number_format($res['margem'], 1, ',', '.') ?>%</strong>
                <?php if ($variacao['margem'] !== null): ?>
                    <span class="painel-var <?= $variacao['margem'] >= 0 ? 'pos' : 'neg' ?>">
                        <?= $var_txt($variacao['margem'], 'pp') ?>
                    </span>
                <?php endif; ?>
            </div>
            <div>
                <span>Vendas</span>
                <strong><?= (int) $res['vendas'] ?></strong>
                <?php if ($variacao['vendas'] !== null): ?>
                    <span class="painel-var <?= $variacao['vendas'] >= 0 ? 'pos' : 'neg' ?>">
                        <?= $var_txt($variacao['vendas']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<form method="get" action="/dashboard" class="filtro-pdv">
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

<?php // O faturamento e o lucro subiram para o painel; aqui fica o que sai do
      // meio dos dois. Em custo, subir e ruim: a moldura inverte. ?>
<div class="numeros grade2">
    <div class="numero kpi <?= kpi_moldura($variacao['cmv'], true) ?>">
        <span class="kpi-rotulo">Mercadoria</span>
        <strong class="kpi-valor negativo">− <?= moeda($res['cmv']) ?></strong>
        <?php if ($variacao['cmv'] !== null): ?>
            <span class="kpi-var <?= $variacao['cmv'] <= 0 ? 'pos' : 'neg' ?>">
                <?= $var_txt($variacao['cmv']) ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="numero kpi <?= kpi_moldura($variacao['outros'], true) ?>">
        <span class="kpi-rotulo">Outros custos</span>
        <strong class="kpi-valor negativo">− <?= moeda($custos_outros) ?></strong>
        <?php if ($variacao['outros'] !== null): ?>
            <span class="kpi-var <?= $variacao['outros'] <= 0 ? 'pos' : 'neg' ?>">
                <?= $var_txt($variacao['outros']) ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<p class="ajuda centro sem-topo">
    Maquininha, condomínio, franquia, imposto e fixos — o detalhe está no
    resumo do período, no fim da tela.
</p>

<?php if ($barras_dia): ?>
    <div class="cartao">
        <h2 class="sem-topo">Faturamento por dia</h2>
        <?= grafico_html_barras_dia($barras_dia, $de, $ate) ?>
    </div>
<?php endif; ?>

<div class="dashboard-grade">
<div class="cartao">
    <h2 class="sem-topo">Como pagaram no período</h2>
    <?php if (!$r['por_forma']): ?>
        <p class="vazio">Sem venda no período.</p>
    <?php else: ?>
    <?= grafico_html_pagamento($r['por_forma'], $formas_map, $formas_cores) ?>
    <div class="numeros grade2">
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
