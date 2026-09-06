<?php /** @var array $itens @var array $conta @var array $min @var string $filtro @var string $busca */ ?>
<h1>Loja</h1>
<?= abas_loja('/margens') ?>

<div class="cartao">
    <h2 class="sem-topo">Os dois pisos</h2>
    <dl class="dados">
        <div>
            <dt>Prejuízo abaixo de</dt>
            <dd class="valor"><?= $min['prejuizo'] === null ? '—' : number_format($min['prejuizo'], 2, ',', '.') . 'x' ?></dd>
        </div>
        <?php if ($min['operacao'] !== null): ?>
            <div>
                <dt>Não paga a operação abaixo de</dt>
                <dd class="valor"><?= number_format($min['operacao'], 2, ',', '.') ?>x</dd>
            </div>
        <?php endif; ?>
    </dl>
    <p class="ajuda">
        Vender por V o que custou C sobra <code>V − C − V×<?= number_format($min['pct_variavel'], 2, ',', '.') ?>%</code>,
        e isso zera no fator <?= $min['prejuizo'] === null ? '—' : number_format($min['prejuizo'], 2, ',', '.') . 'x' ?>.
        <?php if ($min['pct_fixo'] !== null): ?>
            Somando energia e sistema, que hoje pesam
            <?= number_format($min['pct_fixo'], 2, ',', '.') ?>% do faturamento, o piso saudável sobe para
            <?= number_format($min['operacao'], 2, ',', '.') ?>x.
        <?php else: ?>
            Sem venda no mês ainda não dá para saber quanto o custo fixo pesa, então só o
            primeiro piso aparece.
        <?php endif; ?>
    </p>
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
<div class="chips">
    <?php foreach ($chips as $chave => $rotulo): ?>
        <a class="chip <?= $filtro === $chave ? 'ativo' : '' ?>"
           href="/margens?<?= e(http_build_query(['filtro' => $chave, 'q' => $busca])) ?>"><?= e($rotulo) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="/margens" class="linha-form">
    <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
    <input type="text" name="q" value="<?= e($busca) ?>" placeholder="produto, código ou categoria"
           autocomplete="off" autocorrect="off" spellcheck="false">
    <button type="submit" class="botao botao-alt">Buscar</button>
</form>

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
<?php else: ?>
    <ul class="lista">
        <?php foreach ($itens as $i): [$rotulo, $classe, $selo] = custo_veredito_rotulo($i['veredito']); ?>
            <li><div class="linha-cartao">
                <div class="linha-topo">
                    <span class="forte"><?= e($i['descricao']) ?></span>
                    <span class="valor">
                        <?= $i['fator'] === null ? '—' : number_format($i['fator'], 2, ',', '.') . 'x' ?>
                    </span>
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
<?php endif; ?>
