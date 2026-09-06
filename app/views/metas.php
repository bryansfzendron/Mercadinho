<?php /** @var array $fat @var array $luc @var array $p @var string $mes @var string $mes_ref @var array $meses_disponiveis @var array $breakdown_fat @var array $breakdown_luc @var int $pdv_id @var array $pdvs @var string $de @var string $ate */ ?>
<h1>Loja</h1>
<?= abas_loja('/metas') ?>

<form method="get" action="/metas" class="filtros" style="grid-template-columns: 1fr 1fr; margin-bottom:1rem">
    <label>Mês
        <select name="mes" onchange="this.form.submit()">
            <?php foreach ($meses_disponiveis as $val => $lbl): ?>
                <option value="<?= e($val) ?>" <?= $mes_ref === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
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
</form>

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

<h2>Breakdown diário — Faturamento</h2>
<?php if ($p['meta_faturamento'] > 0): ?>
    <div class="cartao" style="overflow-x:auto">
        <table style="width:100%; border-collapse:collapse; font-size:.85rem">
            <thead>
                <tr style="background:var(--fundo); position:sticky; top:0; z-index:1">
                    <th style="padding:.5rem; text-align:left; border-bottom:1px solid var(--borda)">Dia</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Meta do dia</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Realizado</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Diff dia</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Meta acum.</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Real acum.</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Diff acum.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($breakdown_fat['diario'] as $d): ?>
                    <tr style="<?= $d['hoje'] ? 'background:var(--tinta-marca)' : '' ?>">
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); font-weight:<?= $d['hoje'] ? '600' : '400' ?>">
                            <?= (int) $d['dia'] ?><?= $d['hoje'] ? ' (hoje)' : '' ?>
                        </td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['meta_dia']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['realizado_dia']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right; color:<?= $d['diff_dia'] >= 0 ? 'var(--positivo)' : 'var(--vermelho') ?>">
                            <?= $d['diff_dia'] >= 0 ? '+' : '' ?><?= moeda($d['diff_dia']) ?>
                        </td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['acum_meta']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['acum_real']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right; font-weight:600; color:<?= $d['diff_acum'] >= 0 ? 'var(--positivo)' : 'var(--vermelho') ?>">
                            <?= $d['diff_acum'] >= 0 ? '+' : '' ?><?= moeda($d['diff_acum']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="ajuda" style="margin-top:.5rem">
        Meta diária base: <?= moeda($breakdown_fat['resumo']['meta_diaria_base']) ?> · 
        Déficit/superávit acumulado é rateado nos dias restantes.
    </p>
<?php else: ?>
    <p class="ajuda">Defina a meta de faturamento em <a href="/config/metas">Configurações → Metas</a> para ver o breakdown.</p>
<?php endif; ?>

<h2>Breakdown diário — Lucro</h2>
<?php if ($p['meta_lucro'] > 0): ?>
    <div class="cartao" style="overflow-x:auto">
        <table style="width:100%; border-collapse:collapse; font-size:.85rem">
            <thead>
                <tr style="background:var(--fundo); position:sticky; top:0; z-index:1">
                    <th style="padding:.5rem; text-align:left; border-bottom:1px solid var(--borda)">Dia</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Meta do dia</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Realizado</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Diff dia</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Meta acum.</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Real acum.</th>
                    <th style="padding:.5rem; text-align:right; border-bottom:1px solid var(--borda)">Diff acum.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($breakdown_luc['diario'] as $d): ?>
                    <tr style="<?= $d['hoje'] ? 'background:var(--tinta-marca)' : '' ?>">
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); font-weight:<?= $d['hoje'] ? '600' : '400' ?>">
                            <?= (int) $d['dia'] ?><?= $d['hoje'] ? ' (hoje)' : '' ?>
                        </td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['meta_dia']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['realizado_dia']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right; color:<?= $d['diff_dia'] >= 0 ? 'var(--positivo)' : 'var(--vermelho') ?>">
                            <?= $d['diff_dia'] >= 0 ? '+' : '' ?><?= moeda($d['diff_dia']) ?>
                        </td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['acum_meta']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['acum_real']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right; font-weight:600; color:<?= $d['diff_acum'] >= 0 ? 'var(--positivo)' : 'var(--vermelho') ?>">
                            <?= $d['diff_acum'] >= 0 ? '+' : '' ?><?= moeda($d['diff_acum']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="ajuda" style="margin-top:.5rem">
        Meta diária base: <?= moeda($breakdown_luc['resumo']['meta_diaria_base']) ?> · 
        Déficit/superávit acumulado é rateado nos dias restantes.
    </p>
<?php else: ?>
    <p class="ajuda">Defina a meta de lucro em <a href="/config/metas">Configurações → Metas</a> para ver o breakdown.</p>
<?php endif; ?>

<p class="ajuda centro">
    <a href="/config/metas">Ajustar as metas</a> ·
    <a href="/config/taxas">custos e taxas</a>
</p>