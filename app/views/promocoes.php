<?php
/**
 * Promocoes: a lista de trabalho do que esta vencendo.
 *
 * Tres perguntas, na ordem em que se resolvem de pe no container: o que tem
 * de sair da prateleira AGORA (vencido), o que pede promocao, e o que ja foi
 * posto em promocao por aqui.
 *
 * @var array $sugeridas
 * @var array $retirar
 * @var array $criadas
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

<h2>Criadas pelo app</h2>
<?php if (!$criadas): ?>
    <p class="vazio">Nenhuma promoção criada por aqui ainda.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($criadas as $p):
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
<p class="ajuda">Promoções feitas direto no painel do TouchPay ainda não aparecem nesta lista.</p>
