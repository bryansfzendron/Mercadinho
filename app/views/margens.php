<?php /** @var array $itens @var array $conta @var array $min @var string $filtro @var string $busca */ ?>
<?= titulo_loja() ?>
<?= abas_loja('/margens') ?>

<?php $fx = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2, ',', '.') . 'x'; ?>
<div class="cartao">
    <?= cartao_cabeca('percentual', 'Os pisos de margem', 'O fator é preço de venda ÷ custo', '', true) ?>
    <div class="destaque">
        <strong><?= $fx($min['prejuizo']) ?></strong>
        <span>abaixo disso cada venda dá prejuízo</span>
    </div>
    <dl class="dados dados-grandes">
        <?php if ($min['operacao'] !== null): ?>
            <div><dt>Não paga a operação abaixo de</dt><dd class="valor"><?= $fx($min['operacao']) ?></dd></div>
        <?php endif; ?>
        <div><dt>Sai de toda venda</dt><dd class="valor"><?= number_format($min['pct_variavel'], 2, ',', '.') ?>%</dd></div>
    </dl>
    <?= mais_inicio('Como é calculado', 'Esconder') ?>
        <p class="ajuda">
            Vender por V o que custou C sobra <code>V − C − V×<?= number_format($min['pct_variavel'], 2, ',', '.') ?>%</code>,
            e isso zera no fator <?= $fx($min['prejuizo']) ?>.
            <?php if ($min['pct_fixo'] !== null): ?>
                Somando os fixos do mês (energia, sistema, internet), que hoje pesam
                <?= number_format($min['pct_fixo'], 2, ',', '.') ?>% do faturamento, o piso saudável sobe para
                <?= $fx($min['operacao']) ?>.
            <?php else: ?>
                Sem venda no mês ainda não dá para saber quanto o custo fixo pesa, então só o
                primeiro piso aparece.
            <?php endif; ?>
        </p>
    <?= mais_fim() ?>
</div>

<?php
$chips = [
    ''          => 'Todos',
    'prejuizo'  => 'Prejuízo (' . (int) $conta['prejuizo'] . ')',
    'aperto'    => 'No aperto (' . (int) $conta['aperto'] . ')',
    'ok'        => 'Saudáveis (' . (int) $conta['ok'] . ')',
    'sem_custo' => 'Sem custo (' . (int) $conta['sem_custo'] . ')',
];
?>
<div class="cartao filtros-cartao">
    <div class="chips vf-periodos vf-rola">
        <?php foreach ($chips as $chave => $rotulo): ?>
            <a class="chip <?= $filtro === $chave ? 'ativo' : '' ?>"
               href="/margens?<?= e(http_build_query(['filtro' => $chave, 'q' => $busca])) ?>"><?= e($rotulo) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" action="/margens">
        <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
        <?= filtro_busca('q', $busca, 'Produto, código ou categoria') ?>
    </form>
</div>

<?php if (!$itens): ?>
    <p class="vazio">
        <?php if ($filtro === 'prejuizo'): ?>
            Nenhum produto vendendo abaixo do custo. Bom sinal.
        <?php elseif ($filtro === 'aperto'): ?>
            Nenhum produto no aperto.
        <?php else: ?>
            Nada aqui. Sincronize preços e estoque, ou afine a busca.
        <?php endif; ?>
    </p>
<?php else: $recolhe = count($itens) > 30; ?>
    <div class="cartao<?= $recolhe ? ' recolhivel' : '' ?>">
        <?= cartao_cabeca('barras', 'Produtos', number_format(count($itens), 0, ',', '.') . ' neste filtro',
            $recolhe ? ver_mais_botao('Ver todos', 'Ver menos') : '') ?>
        <ul class="lista">
            <?php foreach ($itens as $n => $i): [$rotulo, $classe, $selo] = custo_veredito_rotulo($i['veredito']); ?>
                <li class="<?= $recolhe && $n >= 30 ? 'extra' : '' ?>"><div class="linha-cartao">
                    <div class="linha-topo">
                        <span class="forte"><?= e($i['descricao']) ?></span>
                        <span class="valor"><?= $i['fator'] === null ? '—' : number_format($i['fator'], 2, ',', '.') . 'x' ?></span>
                    </div>
                    <div class="linha-baixo">
                        <span>
                            vende <?= moeda($i['venda']) ?>
                            <?php if ($i['custo'] !== null): ?>
                                · custou <?= moeda($i['custo']) ?>
                            <?php endif; ?>
                        </span>
                        <span class="selo <?= e($selo) ?>"><?= e($rotulo) ?></span>
                    </div>
                    <?php if ($i['veredito'] === 'sem_custo'): ?>
                        <div class="linha-baixo">
                            <span>Escaneie uma nota deste produto para poder julgar.</span>
                        </div>
                    <?php else: ?>
                        <div class="linha-baixo">
                            <span>
                                <?php // Prejuizo se diz "perde", nao "sobra R$ -0,11". ?>
                                <?= $i['sobra'] >= 0 ? 'sobra' : 'perde' ?>
                                <?= moeda(abs($i['sobra'])) ?> por unidade<?php
                                    if ($i['sobra_apos_fixo'] !== null): ?>,
                                    <?= $i['sobra_apos_fixo'] >= 0 ? 'sobram' : 'perde' ?>
                                    <?= moeda(abs($i['sobra_apos_fixo'])) ?> depois do fixo<?php
                                    endif; ?>
                            </span>
                            <span><?= qtd_fmt($i['estoque']) ?> em estoque</span>
                        </div>
                    <?php endif; ?>
                </div></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
