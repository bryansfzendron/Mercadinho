<?php
/** @var array $fat @var array $luc @var array $p @var string $mes @var string $mes_ref @var array $meses_disponiveis @var array $breakdown_fat @var array $breakdown_luc @var int $pdv_id @var array $pdvs @var string $de @var string $ate */

$p_fat_meta = (float) $p['meta_faturamento'];
$p_luc_meta = (float) $p['meta_lucro'];
?>
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
            <?php foreach ($pdvs as $pdv): ?>
                <option value="<?= (int) $pdv['id'] ?>" <?= $pdv_id === (int) $pdv['id'] ? 'selected' : '' ?>>
                    <?= e($pdv['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<p class="meta"><?= e($mes) ?> · dia <?= (int) $fat['dia'] ?> de <?= (int) $fat['no_mes'] ?></p>

<?php
function cartao_meta(array $m, string $titulo): void
{
    $tem_meta = (bool) $m['tem_meta'];
    $batida   = (bool) $m['batida'];
    $selo_class = '';
    $selo_text  = '';
    if ($tem_meta) {
        $selo_class = $batida || $m['projecao'] >= $m['meta'] ? 'selo-ok' : 'selo-pendente';
        $selo_text  = $batida ? 'batida' : ($m['projecao'] >= $m['meta'] ? 'no ritmo' : 'abaixo do ritmo');
    }
    $pct = (int) round($m['pct']);
    $bar_w = min(100, max(0, $pct));
    ?>
    <div class="cartao">
        <div class="meta-topo">
            <span class="forte"><?= e($titulo) ?></span>
            <?php if ($tem_meta): ?>
                <span class="selo <?= $selo_class ?>"><?= $selo_text ?></span>
            <?php endif; ?>
        </div>
        <div class="meta-topo">
            <span class="meta-valor"><?= moeda($m['realizado']) ?></span>
            <?php if ($tem_meta): ?>
                <span class="meta-alvo">de <?= moeda($m['meta']) ?> · <?= number_format($m['pct'], 0, ',', '.') ?>%</span>
            <?php endif; ?>
        </div>
        <?php if ($tem_meta): ?>
            <div class="meta-barra <?= $batida ? 'batida' : '' ?>">
                <span style="width: <?= $bar_w ?>%"></span>
            </div>
            <p class="ajuda">
                <?php if ($batida): ?>
                    Meta batida. Passou <?= moeda($m['realizado'] - $m['meta']) ?> do alvo.
                <?php elseif ((int) $m['restam'] > 0): ?>
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

cartao_meta($fat, 'Faturamento do mês');
cartao_meta($luc, 'Lucro do mês');
?>

<h2>Breakdown diário — Faturamento</h2>
<?php if ($p_fat_meta > 0): ?>
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
                    <?php
                    $is_hoje = (bool) $d['hoje'];
                    $diff_dia = (float) $d['diff_dia'];
                    $diff_acum = (float) $d['diff_acum'];
                    $cor_dia = $diff_dia >= 0 ? 'var(--positivo)' : 'var(--vermelho)';
                    $cor_acum = $diff_acum >= 0 ? 'var(--positivo)' : 'var(--vermelho)';
                    $sinal_dia = $diff_dia >= 0 ? '+' : '';
                    $sinal_acum = $diff_acum >= 0 ? '+' : '';
                    $bg_hoje = $is_hoje ? 'background:var(--tinta-marca)' : '';
                    $fw_hoje = $is_hoje ? '600' : '400';
                    $label_dia = (int) $d['dia'] . ($is_hoje ? ' (hoje)' : '');
                    ?>
                    <tr style="<?= $bg_hoje ?>">
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); font-weight:<?= $fw_hoje ?>"><?= $label_dia ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['meta_dia']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['realizado_dia']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right; color:<?= $cor_dia ?>"><?= $sinal_dia ?><?= moeda($diff_dia) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['acum_meta']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['acum_real']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right; font-weight:600; color:<?= $cor_acum ?>"><?= $sinal_acum ?><?= moeda($diff_acum) ?></td>
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
<?php if ($p_luc_meta > 0): ?>
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
                    <?php
                    $is_hoje = (bool) $d['hoje'];
                    $diff_dia = (float) $d['diff_dia'];
                    $diff_acum = (float) $d['diff_acum'];
                    $cor_dia = $diff_dia >= 0 ? 'var(--positivo)' : 'var(--vermelho)';
                    $cor_acum = $diff_acum >= 0 ? 'var(--positivo)' : 'var(--vermelho)';
                    $sinal_dia = $diff_dia >= 0 ? '+' : '';
                    $sinal_acum = $diff_acum >= 0 ? '+' : '';
                    $bg_hoje = $is_hoje ? 'background:var(--tinta-marca)' : '';
                    $fw_hoje = $is_hoje ? '600' : '400';
                    $label_dia = (int) $d['dia'] . ($is_hoje ? ' (hoje)' : '');
                    ?>
                    <tr style="<?= $bg_hoje ?>">
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); font-weight:<?= $fw_hoje ?>"><?= $label_dia ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['meta_dia']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['realizado_dia']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right; color:<?= $cor_dia ?>"><?= $sinal_dia ?><?= moeda($diff_dia) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['acum_meta']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right"><?= moeda($d['acum_real']) ?></td>
                        <td style="padding:.5rem; border-bottom:1px solid var(--borda); text-align:right; font-weight:600; color:<?= $cor_acum ?>"><?= $sinal_acum ?><?= moeda($diff_acum) ?></td>
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