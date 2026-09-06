<?php /** @var array $res @var array $res_ant @var array $variacao @var array $top @var array $formas_map @var array $r @var string $chave @var string $rotulo @var string $de @var string $ate @var array $periodos */ ?>
<div class="acoes-topo">
    <h1 style="margin:0">Dashboard</h1>
    <div class="chips" style="margin-top:.5rem">
        <?php foreach ($periodos as $k => [$lbl, $d, $a]): ?>
            <a class="chip <?= $chave === $k ? 'ativo' : '' ?>" href="/dashboard?periodo=<?= e($k) ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="numeros" style="gap:.5rem">
    <div class="numero kpi <?= ($variacao['receita'] ?? 0) >= 0 ? 'kpi-up' : 'kpi-down' ?>">
        <span class="kpi-rotulo">Faturamento</span>
        <strong class="kpi-valor"><?= moeda($res['receita']) ?></strong>
        <?php if ($variacao['receita'] !== null): ?>
            <span class="kpi-var <?= $variacao['receita'] >= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['receita'] >= 0 ? '+' : '' ?><?= number_format($variacao['receita'], 1, ',', '.') ?>%
            </span>
        <?php endif; ?>
    </div>

    <div class="numero kpi <?= ($variacao['cmv'] ?? 0) <= 0 ? 'kpi-up' : 'kpi-down' ?>">
        <span class="kpi-rotulo">CMV (Mercadoria)</span>
        <strong class="kpi-valor negativo">− <?= moeda($res['cmv']) ?></strong>
        <?php if ($variacao['cmv'] !== null): ?>
            <span class="kpi-var <?= $variacao['cmv'] <= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['cmv'] >= 0 ? '+' : '' ?><?= number_format($variacao['cmv'], 1, ',', '.') ?>%
            </span>
        <?php endif; ?>
    </div>

    <div class="numero kpi <?= ($variacao['taxa'] ?? 0) <= 0 ? 'kpi-up' : 'kpi-down' ?>">
        <span class="kpi-rotulo">Maquininha</span>
        <strong class="kpi-valor negativo">− <?= moeda($res['taxa']) ?></strong>
        <?php if ($variacao['taxa'] !== null): ?>
            <span class="kpi-var <?= $variacao['taxa'] <= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['taxa'] >= 0 ? '+' : '' ?><?= number_format($variacao['taxa'], 1, ',', '.') ?>%
            </span>
        <?php endif; ?>
    </div>

    <div class="numero kpi <?= ($variacao['fixos'] ?? 0) <= 0 ? 'kpi-up' : 'kpi-down' ?>">
        <span class="kpi-rotulo">Energia + Sistema</span>
        <strong class="kpi-valor negativo">− <?= moeda($res['fixos']) ?></strong>
        <?php if ($variacao['fixos'] !== null): ?>
            <span class="kpi-var <?= $variacao['fixos'] <= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['fixos'] >= 0 ? '+' : '' ?><?= number_format($variacao['fixos'], 1, ',', '.') ?>%
            </span>
        <?php endif; ?>
    </div>

    <div class="numero kpi <?= ($variacao['lucro'] ?? 0) >= 0 ? 'kpi-up' : 'kpi-down' ?>">
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

    <div class="numero kpi <?= ($variacao['margem'] ?? 0) >= 0 ? 'kpi-up' : 'kpi-down' ?>">
        <span class="kpi-rotulo">Margem</span>
        <strong class="kpi-valor"><?= number_format($res['margem'], 1, ',', '.') ?>%</strong>
        <?php if ($variacao['margem'] !== null): ?>
            <span class="kpi-var <?= $variacao['margem'] >= 0 ? 'pos' : 'neg' ?>">
                <?= $variacao['margem'] >= 0 ? '+' : '' ?><?= number_format($variacao['margem'], 1, ',', '.') ?>pp
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="cartao">
    <h2 class="sem-topo">Como pagaram no período</h2>
    <div class="numeros" style="gap:.5rem; margin-top:.5rem">
        <?php foreach ($r['por_forma'] ?? [] as $pf): ?>
            <div class="numero" style="min-width:110px">
                <span class="kpi-rotulo"><?= e($formas_map[$pf['forma']] ?? $pf['forma']) ?></span>
                <strong class="kpi-valor"><?= moeda($pf['total']) ?></strong>
                <span class="ajuda"><?= (int) $pf['n'] ?> vendas · <?= number_format(custo_taxa_da_forma($pf['forma'], $r['parametros']), 2, ',', '.') ?>% taxa</span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="cartao">
    <h2 class="sem-topo">Top 5 produtos por contribuição</h2>
    <?php if (!$top): ?>
        <p class="vazio">Sem vendas no período.</p>
    <?php else: ?>
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
        <div><dt>Energia + Sistema</dt><dd class="valor negativo">− <?= moeda($res['fixos']) ?></dd></div>
        <div><dt>Lucro líquido</dt><dd class="valor <?= $res['lucro'] >= 0 ? '' : 'negativo' ?>">
            <?= $res['lucro'] >= 0 ? '+' : '−' ?><?= moeda(abs($res['lucro'])) ?>
        </dd></div>
        <div><dt>Margem</dt><dd class="valor"><?= number_format($res['margem'], 1, ',', '.') ?>%</dd></div>
    </dl>
</div>

<p class="ajuda centro" style="margin-top:1rem">
    <a href="/vendas">Ver relatório detalhado com filtros</a> · <a href="/metas">Acompanhar metas do mês</a>
</p>

<style>
.kpi { display:flex; flex-direction:column; align-items:center; text-align:center; padding:.85rem .5rem; position:relative; }
.kpi-rotulo { font-size:.65rem; color:var(--suave); text-transform:uppercase; letter-spacing:.04em; margin-bottom:.25rem; }
.kpi-valor { font-size:1.15rem; font-weight:600; line-height:1.2; font-variant-numeric:tabular-nums; }
.kpi-valor.negativo { color:var(--vermelho); }
.kpi-var { font-size:.65rem; font-weight:600; margin-top:.2rem; padding:.1rem .35rem; border-radius:99px; }
.kpi-var.pos { background:var(--tinta-positiva); color:var(--positivo); }
.kpi-var.neg { background:var(--tinta-vermelha); color:var(--vermelho); }
.kpi-up { border-color:var(--positivo); }
.kpi-down { border-color:var(--vermelho); }

@media (max-width: 480px) {
    .kpi-valor { font-size:1rem; }
}
</style>