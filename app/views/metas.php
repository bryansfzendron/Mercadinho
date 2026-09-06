<?php /** @var array $fat @var array $luc @var array $p @var string $mes @var array $pdvs */ ?>
<h1>Loja</h1>
<?= abas_loja('/metas') ?>

<p class="meta"><?= e($mes) ?> · dia <?= (int) $fat['dia'] ?> de <?= (int) $fat['no_mes'] ?></p>

<?php
/** Um cartão de meta: quanto entrou, quanto falta e se o ritmo chega lá. */
function cartao_meta(array $m, string $titulo, string $nome_campo): void
{ ?>
    <div class="cartao">
        <div class="meta-topo">
            <span class="forte"><?= e($titulo) ?></span>
            <?php if ($m['tem_meta']): ?>
                <span class="selo <?= $m['batida'] ? 'selo-ok' : ($m['projecao'] >= $m['meta'] ? 'selo-ok' : 'selo-pendente') ?>">
                    <?= $m['batida'] ? 'batida' : ($m['projecao'] >= $m['meta'] ? 'no ritmo' : 'abaixo do ritmo') ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="meta-topo">
            <span class="meta-valor"><?= moeda($m['realizado']) ?></span>
            <?php if ($m['tem_meta']): ?>
                <span class="meta-alvo">de <?= moeda($m['meta']) ?> · <?= number_format($m['pct'], 0, ',', '.') ?>%</span>
            <?php endif; ?>
        </div>

        <?php if ($m['tem_meta']): ?>
            <div class="meta-barra <?= $m['batida'] ? 'batida' : '' ?>">
                <span style="width: <?= min(100, max(0, round($m['pct']))) ?>%"></span>
            </div>
            <p class="ajuda">
                <?php if ($m['batida']): ?>
                    Meta batida. Passou <?= moeda($m['realizado'] - $m['meta']) ?> do alvo.
                <?php elseif ($m['restam'] > 0): ?>
                    Faltam <?= moeda($m['falta']) ?> em <?= (int) $m['restam'] ?> dia(s) —
                    <strong><?= moeda($m['por_dia']) ?> por dia</strong>.
                    No ritmo atual o mês fecha em <?= moeda($m['projecao']) ?>.
                <?php else: ?>
                    Último dia do mês: faltam <?= moeda($m['falta']) ?>.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="ajuda">
                Sem meta definida. No ritmo atual (<?= moeda($m['ritmo']) ?> por dia)
                o mês fecha em <?= moeda($m['projecao']) ?>.
            </p>
        <?php endif; ?>
    </div>
<?php }

cartao_meta($fat, 'Faturamento do mês', 'meta_faturamento');
cartao_meta($luc, 'Lucro do mês', 'meta_lucro');
?>

<p class="ajuda centro">
    <a href="/config/metas">Ajustar as metas</a> ·
    <a href="/config/taxas">custos e taxas</a>
</p>
