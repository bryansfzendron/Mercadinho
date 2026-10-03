<?php
/**
 * Promocoes: a lista de trabalho do que esta vencendo.
 *
 * Tres perguntas, na ordem em que se resolvem de pe no container: o que tem
 * de sair da prateleira AGORA (vencido), o que pede promocao, e o que ja
 * esta em promocao no TouchPay — criado por aqui ou direto no painel.
 *
 * @var array $sugeridas
 * @var array $retirar
 * @var bool  $tp_ok      a lista do TouchPay respondeu
 * @var array $valendo    promocoes do TouchPay no ar ou agendadas
 * @var array $encerradas do TouchPay, ultimos 30 dias
 * @var array $locais     recusadas (painel no ar) ou o diario inteiro (fora)
 */
$hoje = date('Y-m-d');
?>
<h1>Promoções</h1>
<?= abas_loja('/promocoes') ?>

<?php if ($retirar): ?>
    <h2 class="faixa-validade vencido">Vencidos na prateleira <span><?= count($retirar) ?></span></h2>
    <p class="ajuda">Produto vencido não pode ser vendido, nem em promoção. Tire da gôndola e zere o estoque.</p>
    <ul class="lista">
        <?php foreach ($retirar as $a): $it = $a['item']; ?>
            <li><a href="/promocao/<?= (int) $it['id'] ?>">
                <div class="linha-topo">
                    <span class="forte"><?= e($it['descricao']) ?></span>
                    <span class="valor zerado"><?= qtd_fmt($it['estoque']) ?> un.</span>
                </div>
                <div class="linha-baixo">
                    <span><?= e($a['validade']['texto']) ?> · <?= e($it['pdv']) ?></span>
                </div>
            </a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Pedem promoção <span class="ajuda"><?= count($sugeridas) ?></span></h2>
<?php if (!$sugeridas): ?>
    <p class="vazio">
        Nada pedindo promoção agora.<br>
        Só entram aqui os itens com validade cadastrada, estoque e até 60 dias pela frente
        que não vão sair a tempo no preço de hoje.
    </p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($sugeridas as $a):
            $it  = $a['item'];
            $s   = $a['sugestao'];
            $pct = $a['percentual'];
        ?>
            <li><a href="/promocao/<?= (int) $it['id'] ?>">
                <div class="linha-topo">
                    <span class="forte"><?= e($it['descricao']) ?></span>
                    <span class="valor">
                        <?php if ($pct !== null && $a['preco'] !== null): ?>
                            −<?= $pct ?>% · <?= moeda(promocao_preco_com($a['preco'], $pct)) ?>
                        <?php else: ?>
                            <?= number_format($s['taxa'], 2, ',', '.') ?>×
                        <?php endif; ?>
                    </span>
                </div>
                <div class="linha-baixo">
                    <span>
                        <span class="selo selo-validade <?= e($a['validade']['classe']) ?>"><?= e($a['validade']['texto']) ?></span>
                        · <?= qtd_fmt($it['estoque']) ?> em estoque · <?= e($it['pdv']) ?>
                    </span>
                    <?php if ($a['preco'] !== null): ?>
                        <span>hoje <?= moeda($a['preco']) ?></span>
                    <?php endif; ?>
                </div>
            </a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php
/** Uma promocao do TouchPay na lista. */
$linha_tp = static function (array $l, string $hoje): string {
    $st   = !$l['valido'] ? 'desligada' : promocao_status($l['inicio'], $l['fim'], $hoje);
    $selo = ['no ar' => 'selo-ok', 'agendada' => 'selo-pendente', 'encerrada' => 'selo-manual',
             'desligada' => 'selo-manual'][$st];
    $abre  = $l['item_id'] ? '<a href="/promocao/' . (int) $l['item_id'] . '">' : '<div class="linha-cartao">';
    $fecha = $l['item_id'] ? '</a>' : '</div>';
    $apagada = in_array($st, ['encerrada', 'desligada'], true);
    return '<li>' . $abre
        . '<div class="linha-topo"><span class="forte">' . e($l['produto'] ?: $l['descricao']) . '</span>'
        . '<span class="valor' . ($apagada ? ' negativo' : '') . '">' . e($l['desconto'])
        . ($l['preco_com'] !== null ? ' · ' . moeda($l['preco_com']) : '') . '</span></div>'
        . '<div class="linha-baixo"><span><span class="selo ' . $selo . '">' . e($st) . '</span> '
        . e(pg_data_br($l['inicio'])) . ' a ' . e(pg_data_br($l['fim'])) . ' · ' . e($l['pdv'])
        . ($l['do_app'] ? ' · pelo app' : '') . '</span>'
        . ($l['preco'] !== null ? '<span>de ' . moeda($l['preco']) . '</span>' : '') . '</div>'
        . $fecha . '</li>';
};
?>

<?php if ($tp_ok): ?>
    <h2>No TouchPay <span class="ajuda"><?= count($valendo) ?></span></h2>
    <?php if (!$valendo): ?>
        <p class="vazio">Nenhuma promoção no ar ou agendada.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($valendo as $l) { echo $linha_tp($l, $hoje); } ?>
        </ul>
    <?php endif; ?>

    <?php if ($encerradas): ?>
        <h2>Encerradas nos últimos 30 dias</h2>
        <ul class="lista">
            <?php foreach ($encerradas as $l) { echo $linha_tp($l, $hoje); } ?>
        </ul>
    <?php endif; ?>
<?php else: ?>
    <div class="aviso aviso-erro">O TouchPay não respondeu agora: abaixo, só o que foi criado pelo app.</div>
<?php endif; ?>

<?php if ($locais): ?>
    <h2><?= $tp_ok ? 'Recusadas pelo TouchPay' : 'Criadas pelo app' ?></h2>
    <ul class="lista">
        <?php foreach ($locais as $p):
            $st = $p['ok'] ? promocao_status($p['inicio'], $p['fim'], $hoje) : 'recusada';
            $selo = ['no ar' => 'selo-ok', 'agendada' => 'selo-pendente', 'encerrada' => 'selo-manual',
                     'recusada' => 'selo-erro'][$st];
        ?>
            <li><div class="linha-cartao">
                <div class="linha-topo">
                    <span class="forte"><?= e($p['descricao'] ?: ('produto ' . $p['produto_externo_id'])) ?></span>
                    <span class="valor<?= $st === 'encerrada' || $st === 'recusada' ? ' negativo' : '' ?>">
                        −<?= (int) $p['percentual'] ?>% · <?= moeda($p['preco_promo']) ?>
                    </span>
                </div>
                <div class="linha-baixo">
                    <span>
                        <span class="selo <?= $selo ?>"><?= e($st) ?></span>
                        <?= e(pg_data_br($p['inicio'])) ?> a <?= e(pg_data_br($p['fim'])) ?>
                        · <?= e($p['pdv'] ?? '—') ?>
                    </span>
                    <span>de <?= moeda($p['preco_base']) ?></span>
                </div>
                <?php if (!$p['ok'] && $p['erro']): ?>
                    <p class="ajuda"><?= e($p['erro']) ?></p>
                <?php endif; ?>
            </div></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
