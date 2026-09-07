<?php
/**
 * @var array  $fat @var array $luc @var array $p @var string $mes
 * @var string $mes_ref @var array $meses_disponiveis
 * @var array  $breakdown_fat @var array $breakdown_luc
 * @var int    $pdv_id @var array $pdvs @var string $de @var string $ate
 */
?>
<h1>Loja</h1>
<?= abas_loja('/metas') ?>

<form method="get" action="/metas">
    <div class="filtros">
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
    </div>
</form>

<p class="meta"><?= e($mes) ?> · dia <?= (int) $fat['dia'] ?> de <?= (int) $fat['no_mes'] ?></p>

<?php
// Os dois cartoes sao o mesmo bloco com dados diferentes. Era uma funcao
// declarada aqui dentro, o que faz a view explodir se ela for incluida duas
// vezes na mesma requisicao; um foreach resolve sem esse risco.
foreach ([['Faturamento do mês', $fat], ['Lucro do mês', $luc]] as [$titulo_cartao, $m]):
    $no_ritmo = $m['tem_meta'] && ($m['batida'] || $m['projecao'] >= $m['meta']);
?>
    <div class="cartao">
        <div class="meta-topo">
            <span class="forte"><?= e($titulo_cartao) ?></span>
            <?php if ($m['tem_meta']): ?>
                <span class="selo <?= $no_ritmo ? 'selo-ok' : 'selo-pendente' ?>">
                    <?= $m['batida'] ? 'batida' : ($no_ritmo ? 'no ritmo' : 'abaixo do ritmo') ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="meta-topo">
            <span class="meta-valor"><?= moeda($m['realizado']) ?></span>
            <?php if ($m['tem_meta']): ?>
                <span class="meta-alvo">
                    de <?= moeda($m['meta']) ?> · <?= number_format($m['pct'], 0, ',', '.') ?>%
                </span>
            <?php endif; ?>
        </div>

        <?php if ($m['tem_meta']): ?>
            <div class="meta-barra <?= $m['batida'] ? 'batida' : '' ?>">
                <span style="width: <?= min(100, max(0, (int) round($m['pct']))) ?>%"></span>
            </div>
            <p class="ajuda">
                <?php if ($m['batida']): ?>
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
<?php endforeach; ?>

<?php
// A tabela era o mesmo bloco copiado duas vezes; copiado erra num lado so.
$b = $breakdown_fat; $titulo = 'Faturamento'; $alvo = (float) $p['meta_faturamento'];
include __DIR__ . '/_breakdown.php';

$b = $breakdown_luc; $titulo = 'Lucro'; $alvo = (float) $p['meta_lucro'];
include __DIR__ . '/_breakdown.php';
?>

<p class="ajuda centro">
    <a href="/config/metas">Ajustar as metas</a> ·
    <a href="/config/taxas">custos e taxas</a>
</p>
