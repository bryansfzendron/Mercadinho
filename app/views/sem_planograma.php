<?php
/**
 * Produtos das notas fiscais que nao estao no planograma, um cartao por mercado.
 *
 * @var array  $grupos sem_planograma_listar()
 * @var string $busca
 */
?>
<?= titulo_loja() ?>
<?= abas_loja('/sem-planograma') ?>

<div class="cartao filtros-cartao">
    <form method="get" action="/sem-planograma">
        <?= filtro_busca('q', $busca, 'Produto ou código', 'Buscar') ?>
    </form>
    <p class="ajuda">
        Produtos que já vieram em nota fiscal e não têm preço no planograma do mercado.
        Toque num produto para colocá-lo no planograma.
    </p>
</div>

<?php if (!$grupos): ?>
    <p class="vazio">Nenhum ponto de venda com planograma ativo.</p>
<?php endif; ?>

<?php foreach ($grupos as $g):
    $pdv = $g['pdv'];
    $teto = 300;
    $itens = array_slice($g['itens'], 0, $teto);
    $recolhe = count($itens) > LISTA_A_VISTA;
?>
    <div class="cartao<?= $recolhe ? ' recolhivel' : '' ?>">
        <?= cartao_cabeca('caixa', $pdv['nome'],
            !$g['sincronizado'] ? 'planograma ainda não lido' : number_format($g['total'], 0, ',', '.') . ' produto(s) fora do planograma') ?>
        <?php if (!$g['sincronizado']): ?>
            <p class="vazio">O planograma deste mercado ainda não foi sincronizado. Arraste a tela para baixo para atualizar.</p>
        <?php elseif (!$itens): ?>
            <p class="vazio">Tudo o que veio em nota está no planograma. Bom sinal.</p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($itens as $n => $p): ?>
                    <li class="<?= $recolhe && $n >= LISTA_A_VISTA ? 'extra' : '' ?>">
                        <a href="/planograma?<?= e(http_build_query(['pdv' => $pdv['id'], 'codigo' => $p['ean'] ?? ''])) ?>">
                            <div class="linha-topo">
                                <span class="forte"><?= e($p['descricao']) ?></span>
                                <span class="valor"><?= $p['custo'] !== null ? moeda((float) $p['custo']) : '—' ?></span>
                            </div>
                            <div class="linha-baixo">
                                <span><?= (int) $p['vezes'] ?>× em nota · última <?= e(data_fmt($p['ultima'])) ?></span>
                                <span class="mono"><?= e($p['ean'] ?? 'sem GTIN') ?></span>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($g['total'] > $teto): ?>
                <p class="ajuda">Mostrando os <?= $teto ?> primeiros de <?= number_format($g['total'], 0, ',', '.') ?>. Use a busca para afinar.</p>
            <?php endif; ?>
            <?= $recolhe ? ver_mais_baixo('Ver todos (' . count($itens) . ')') : '' ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
