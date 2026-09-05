<?php /** @var array $produto @var array $historico @var array $stats */ ?>
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

<h2><?= count($historico) ?> compra(s)</h2>
<ul class="lista">
    <?php foreach ($historico as $h): ?>
        <li>
            <a href="/notas/<?= (int) $h['nota_id'] ?>">
                <div class="linha-topo">
                    <span class="forte"><?= e($h['loja'] ?? 'Sem loja') ?></span>
                    <span class="valor"><?= moeda($h['valor_unitario']) ?></span>
                </div>
                <div class="linha-baixo">
                    <span>
                        <?= data_fmt($h['emissao']) ?> ·
                        <?= qtd_fmt($h['quantidade']) ?> <?= e($h['unidade'] ?: 'un') ?>
                        = <?= moeda($h['valor_total']) ?>
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
