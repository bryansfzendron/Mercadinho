<?php
/**
 * Promocao para o que esta perto de vencer.
 *
 * Esta tela nao grava nada. Ela responde "por quanto ponho isto para sair a
 * tempo?" e entrega o Repor ja preenchido — e e la, com o de/para e o
 * segundo toque, que o preco vai para o TouchPay.
 *
 * @var array  $item
 * @var array  $validade
 * @var array  $custo
 * @var ?float $venda_dia
 * @var ?float $preco
 * @var array  $minimos
 * @var array  $sugestao
 * @var string $codigo
 */
$pdv_id = (int) $item['pdv_id'];
$c      = $custo['custo'];
$sug    = $sugestao;
$taxa_br = static fn (float $t): string => number_format($t, 2, ',', '.');

// O que sobra por unidade no preco sugerido, depois do que sai de toda venda.
$sobra = null;
if ($sug['preco'] !== null && $c !== null) {
    $sobra = $sug['preco'] - $c - $sug['preco'] * (float) $minimos['pct_variavel'] / 100;
}
$desconto = $sug['preco'] !== null && $preco ? (1 - $sug['preco'] / $preco) * 100 : null;
?>
<a class="voltar" href="/loja?<?= e(http_build_query(['ordem' => 'validade', 'pdv' => $pdv_id])) ?>">‹ Vencimentos</a>

<div class="cartao">
    <h1 class="sem-topo"><?= e($item['descricao']) ?></h1>
    <p class="mono"><?= e($codigo ?: 'sem código') ?> · <?= e($item['pdv']) ?></p>

    <?php if ($validade['texto']): ?>
        <p><span class="selo selo-validade <?= e($validade['classe']) ?>"><?= e($validade['texto']) ?></span>
            <span class="ajuda"><?= e(data_fmt($item['validade'])) ?></span></p>
    <?php endif; ?>

    <div class="numeros">
        <div class="numero">
            <strong><?= $validade['dias'] === null ? '—' : max(0, (int) $validade['dias']) ?></strong>
            <span><?= $validade['dias'] !== null && $validade['dias'] < 0 ? 'vencido' : 'dias' ?></span>
        </div>
        <div class="numero">
            <strong><?= qtd_fmt($item['estoque']) ?></strong>
            <span>em estoque</span>
        </div>
        <div class="numero">
            <strong><?= $venda_dia === null ? '—' : qtd_fmt(round($venda_dia, 1)) ?></strong>
            <span>vende/dia</span>
        </div>
    </div>

    <div class="dados">
        <div><dt>Preço hoje</dt><dd><?= $preco === null ? '—' : moeda($preco) ?>
            <?php if ($preco && $c): ?><span class="ajuda">(<?= fator_fmt($preco, $c) ?>×)</span><?php endif; ?></dd></div>
        <div><dt>Custo</dt><dd>
            <?php if ($c === null): ?>
                sem nota
            <?php else: ?>
                <?= moeda($c) ?>
                <span class="ajuda"><?= $custo['fonte'] === 'estoque' ? 'do que está na prateleira' : 'da última nota' ?></span>
            <?php endif; ?>
        </dd></div>
    </div>
</div>

<?php if ($sug['acao'] === 'retirar'): ?>
    <div class="aviso aviso-erro">
        <strong>Retire da prateleira.</strong> <?= e($sug['motivos'][0]) ?>
    </div>
    <a class="botao" href="<?= e(promocao_link_repor($pdv_id, $codigo, null, null)) ?>">Abrir no Repor</a>

<?php elseif (in_array($sug['acao'], ['suspeita', 'sem_estoque', 'sem_validade'], true)): ?>
    <div class="aviso aviso-info"><?= e($sug['motivos'][0]) ?></div>
    <?php if ($sug['acao'] === 'suspeita'): ?>
        <a class="botao" href="<?= e(promocao_link_repor($pdv_id, $codigo, null, null)) ?>">Corrigir no Repor</a>
    <?php endif; ?>

<?php elseif ($sug['acao'] === 'promocao'): ?>
    <div class="cartao promo-sugestao">
        <p class="promo-rotulo">Promoção sugerida</p>
        <div class="promo-linha">
            <strong class="promo-taxa"><?= $taxa_br($sug['taxa']) ?><span>×</span></strong>
            <?php if ($sug['preco'] !== null): ?>
                <div class="promo-preco">
                    <strong><?= moeda($sug['preco']) ?></strong>
                    <?php if ($preco): ?>
                        <span>de <s><?= moeda($preco) ?></s><?= $desconto !== null && $desconto > 0 ? ' · −' . number_format($desconto, 0, ',', '.') . '%' : '' ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($sobra !== null): ?>
            <p class="ajuda">
                <?= $sobra >= 0 ? 'Sobram' : 'Perde' ?> <strong><?= moeda(abs($sobra)) ?></strong> por unidade
                depois da maquininha, do condomínio e da franquia.
            </p>
        <?php endif; ?>
        <ul class="promo-motivos">
            <?php foreach ($sug['motivos'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?>
        </ul>
        <a class="botao" href="<?= e(promocao_link_repor($pdv_id, $codigo, $c, $sug['taxa'])) ?>">
            Aplicar no Repor
        </a>
        <p class="ajuda">Nada é gravado daqui. O Repor abre com custo e taxa preenchidos, e o preço
            só vai para o TouchPay depois do de→para e do segundo toque.</p>
    </div>

<?php else: /* manter */ ?>
    <div class="cartao">
        <p class="promo-rotulo">Não precisa de promoção</p>
        <ul class="promo-motivos">
            <?php foreach ($sug['motivos'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (in_array($sug['acao'], ['promocao', 'manter'], true) && $sug['opcoes']): ?>
    <h2>Outra taxa</h2>
    <ul class="lista">
        <?php foreach ($sug['opcoes'] as $o): ?>
            <?php $escolhida = $sug['taxa'] !== null && abs($o['taxa'] - $sug['taxa']) < 0.001; ?>
            <li><a href="<?= e(promocao_link_repor($pdv_id, $codigo, $c, $o['taxa'])) ?>"
                   class="<?= $escolhida ? 'promo-escolhida' : '' ?>">
                <div class="linha-topo">
                    <span class="forte"><?= $taxa_br($o['taxa']) ?>×<?= $escolhida ? ' · sugerida' : '' ?></span>
                    <span class="valor"><?= $o['preco'] === null ? '—' : moeda($o['preco']) ?></span>
                </div>
                <?php if ($o['abaixo_piso'] || $o['nao_baixa']): ?>
                    <div class="linha-baixo"><span>
                        <?php if ($o['abaixo_piso']): ?>
                            <span class="zerado">abaixo do piso: dá prejuízo</span>
                        <?php else: ?>
                            não baixa do preço de hoje
                        <?php endif; ?>
                    </span></div>
                <?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($c === null): ?>
        <p class="ajuda">Sem custo de nota, o preço só aparece no Repor, depois de digitar o custo.</p>
    <?php endif; ?>
<?php endif; ?>
