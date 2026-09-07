<?php
/** @var array $produto @var array $historico @var array $stats @var array $loja */

// Maior preco de venda entre os pontos de venda que tem o produto no
// planograma. E o que a margem compara com o ultimo valor pago.
$precos = [];
foreach ($loja as $l) {
    if ($l['preco_venda'] !== null) {
        $precos[] = (float) $l['preco_venda'];
    }
}
$venda  = $precos ? max($precos) : null;
$pago   = (float) $stats['ultimo'];
$margem = $venda !== null && $pago > 0 ? $venda - $pago : null;
?>
<a class="voltar" href="/produtos">‹ Produtos</a>

<div class="cartao">
    <h1><?= e($produto['descricao']) ?></h1>
    <p class="mono"><?= $produto['ean'] ? e($produto['ean']) : 'sem GTIN' ?></p>

    <div class="numeros">
        <div class="numero"><strong><?= moeda($stats['ultimo']) ?></strong><span>último</span></div>
        <div class="numero"><strong><?= moeda($stats['min']) ?></strong><span>menor</span></div>
        <div class="numero"><strong><?= moeda($stats['max']) ?></strong><span>maior</span></div>
        <div class="numero"><strong><?= moeda($stats['media']) ?></strong><span>média</span></div>
    </div>
    <p class="ajuda">Valores por <?= e($produto['unidade'] ?: 'unidade') ?>, já com os descontos da nota abatidos.</p>

    <?php if ($margem !== null): $sinal = $margem >= 0 ? '+' : '−';
        // O fator sozinho engana: 1,13x parece lucro e nao e, depois do que
        // sai de toda venda. O veredito vem dos cortes calculados.
        $min = margens_minimos();
        $diag = custo_diagnostico($venda, $pago, $min);
        [$rot_v, $cls_v] = custo_veredito_rotulo($diag['veredito']);
    ?>
        <div class="margem <?= $margem >= 0 ? 'margem-boa' : 'margem-ruim' ?>">
            <strong class="margem-fator"><?= fator_fmt($venda, $pago) ?><span>x</span></strong>
            <div class="margem-conta">
                <span class="margem-lucro"><?= $sinal ?><?= moeda(abs($margem)) ?>
                    <span class="margem-perc"><?= $sinal ?><?= number_format(abs($margem / $pago) * 100, 0, ',', '.') ?>%</span>
                </span>
                <span class="margem-linha">vende <?= moeda($venda) ?> · pagou <?= moeda($pago) ?></span>
            </div>
        </div>

        <?php if ($diag['veredito'] === 'prejuizo' || $diag['veredito'] === 'aperto'): ?>
            <p class="aviso aviso-<?= $diag['veredito'] === 'prejuizo' ? 'erro' : 'info' ?>">
                <strong><?= e($rot_v) ?>.</strong>
                <?php if ($diag['veredito'] === 'prejuizo'): ?>
                    Abaixo de <?= number_format($min['prejuizo'], 2, ',', '.') ?>x cada venda
                    tira dinheiro do bolso: sobram <?= moeda($diag['sobra']) ?> por unidade
                    depois da maquininha, do condomínio e da franquia.
                <?php else: ?>
                    Cobre o que sai de cada venda, mas não ajuda a pagar os fixos do mês —
                    para isso o fator precisa passar de <?= number_format($min['operacao'], 2, ',', '.') ?>x.
                <?php endif; ?>
                <a href="/margens">Ver todos assim</a>.
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$produto['ean']): ?>
        <form method="post" action="/produtos/<?= (int) $produto['id'] ?>/ean" class="linha-form">
            <?= csrf_campo() ?>
            <input type="text" name="ean" inputmode="numeric" placeholder="vincular código de barras">
            <button type="submit" class="botao botao-alt">Vincular</button>
        </form>
        <p class="ajuda">
            Este produto veio <strong>sem GTIN</strong> na nota. Vincule o código de barras
            para conseguir bipá-lo na prateleira.
        </p>
    <?php endif; ?>
</div>

<?php if ($loja): ?>
    <h2>Na loja agora</h2>
    <ul class="lista">
        <?php foreach ($loja as $l): ?>
            <li><div style="padding:.7rem .85rem">
                <div class="linha-topo">
                    <span class="forte"><?= e($l['pdv']) ?></span>
                    <span class="valor">
                        <?= $l['preco_venda'] === null ? '—' : moeda($l['preco_venda']) ?>
                    </span>
                </div>
                <div class="linha-baixo">
                    <span>
                        <?php if ((float) $l['estoque'] > 0): ?>
                            <?= qtd_fmt($l['estoque']) ?> em estoque
                        <?php else: ?>
                            <span class="zerado">sem estoque</span>
                        <?php endif; ?>
                        <?php if ((float) $l['reservado'] > 0): ?>
                            · <?= qtd_fmt($l['reservado']) ?> reservado
                        <?php endif; ?>
                    </span>
                    <span><?= data_fmt($l['atualizado_em'], true) ?></span>
                </div>
                <?php if ($l['descricao'] !== $produto['descricao']): ?>
                    <div class="linha-baixo"><span class="ajuda"><?= e($l['descricao']) ?></span></div>
                <?php endif; ?>
            </div></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2><?= count($historico) ?> compra(s)</h2>
<ul class="lista">
    <?php foreach ($historico as $h): ?>
        <li>
            <a href="/notas/<?= (int) $h['nota_id'] ?>">
                <div class="linha-topo">
                    <span class="forte"><?= e($h['loja'] ?? 'Sem loja') ?></span>
                    <span class="valor"><?= moeda($h['valor_unitario_liquido']) ?></span>
                </div>
                <div class="linha-baixo">
                    <span>
                        <?= data_fmt($h['emissao']) ?> ·
                        <?= qtd_fmt($h['quantidade']) ?> <?= e($h['unidade'] ?: 'un') ?>
                        = <?= moeda($h['valor_total_liquido']) ?>
                        <?php if ((float) $h['desconto'] > 0): ?>
                            <span class="riscado">(de <?= moeda($h['valor_total']) ?>)</span>
                        <?php endif; ?>
                    </span>
                    <?php if ($h['origem'] === 'manual'): ?>
                        <span class="selo selo-manual">manual</span>
                    <?php endif; ?>
                </div>
                <?php if ($h['descricao_original'] !== $produto['descricao']): ?>
                    <div class="linha-baixo"><span class="ajuda"><?= e($h['descricao_original']) ?></span></div>
                <?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
