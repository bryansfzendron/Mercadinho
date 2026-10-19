<?php
/**
 * O miolo de um produto vendido aberto: em quais PDVs saiu e, na tela de
 * vendas, em quais dias. Sem layout — vai parar dentro da linha tocada.
 *
 * Com um PDV so, o nome dele nao se repete em cada dia: a lista de cima ja
 * disse qual e.
 *
 * @var ?array $d        de vendas_produto_onde_dobrar()
 * @var bool   $com_dias
 */
$curtos = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
?>
<?php if (!$d || !$d['pdvs']): ?>
    <p class="ajuda">Nenhuma venda deste produto no período.</p>
<?php else: ?>
    <?php
    $varios = count($d['pdvs']) > 1;
    // Periodo que vira o ano precisa do ano no dia; o resto nao.
    $anos = array_unique(array_map(static fn (array $l): string => substr((string) $l['dia'], 0, 4), $d['dias']));
    $formato = count($anos) > 1 ? 'd/m/Y' : 'd/m';
    ?>
    <p class="vendido-titulo">Por ponto de venda</p>
    <ul class="itens-compra">
        <?php foreach ($d['pdvs'] as $p): ?>
            <li>
                <span class="item-nome"><?= e($p['pdv']) ?></span>
                <span class="item-valor"><?= e(qtd_fmt($p['quantidade'])) ?> un · <?= moeda($p['total']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($com_dias): ?>
        <p class="vendido-titulo">Por dia</p>
        <ul class="itens-compra">
            <?php foreach ($d['dias'] as $l): $ts = strtotime((string) $l['dia']); ?>
                <li>
                    <span class="item-nome">
                        <?= e($curtos[(int) date('w', $ts)] . ', ' . date($formato, $ts)) ?>
                        <?php if ($varios): ?><span class="item-qtd"><?= e($l['pdv']) ?></span><?php endif; ?>
                    </span>
                    <span class="item-valor"><?= e(qtd_fmt($l['quantidade'])) ?> un · <?= moeda($l['total']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($d['produto_id'] > 0): ?>
        <p class="ajuda"><a href="/produtos/<?= (int) $d['produto_id'] ?>">Ver ficha do produto</a></p>
    <?php endif; ?>
<?php endif; ?>
