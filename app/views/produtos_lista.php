<?php /** @var array $produtos @var string $busca @var array $loja */ ?>
<?= titulo_icone('Produtos', 'produto') ?>

<div class="cartao filtros-cartao">
    <a class="botao botao-alt botao-com-icone" href="/bipar"><?= icone('camera') ?>Bipar produto</a>
    <form method="get" action="/produtos">
        <?= filtro_busca('q', $busca, 'Nome ou código', 'Buscar') ?>
    </form>
</div>

<?php if (!$produtos): ?>
    <p class="vazio">
        <?= $busca !== '' ? 'Nada encontrado para essa busca.' : 'Nenhum produto ainda.' ?>
    </p>
<?php else: $recolhe = count($produtos) > 30; ?>
    <div class="cartao<?= $recolhe ? ' recolhivel' : '' ?>">
    <?= cartao_cabeca('produto', 'Comprados', count($produtos) . ' produto(s)' . ($busca !== '' ? ' nesta busca' : ''),
        $recolhe ? ver_mais_botao('Ver todos', 'Ver menos') : '') ?>
    <ul class="lista">
        <?php foreach ($produtos as $k => $p): ?>
            <li class="<?= $recolhe && $k >= 30 ? 'extra' : '' ?>">
                <a href="/produtos/<?= (int) $p['id'] ?>">
                    <div class="linha-topo">
                        <span class="forte"><?= e($p['descricao']) ?></span>
                        <span class="valor"><?= moeda($p['menor']) ?></span>
                    </div>
                    <div class="linha-baixo">
                        <span>
                            <?= (int) $p['compras'] ?>× · última <?= data_fmt($p['ultima_compra']) ?>
                        </span>
                        <span class="mono"><?= $p['ean'] ? e($p['ean']) : 'sem GTIN' ?></span>
                    </div>
                    <?php $na_loja = $loja[(int) $p['id']] ?? null; ?>
                    <?php if ($na_loja): ?>
                        <div class="linha-baixo">
                            <span>
                                na loja
                                <?= $na_loja['preco'] === null ? '—' : moeda($na_loja['preco']) ?>
                                ·
                                <?php if ($na_loja['estoque'] > 0): ?>
                                    <?= qtd_fmt($na_loja['estoque']) ?> em estoque
                                <?php else: ?>
                                    <span class="zerado">sem estoque</span>
                                <?php endif; ?>
                            </span>
                            <?php if ($na_loja['pdvs'] > 1): ?>
                                <span class="ajuda"><?= (int) $na_loja['pdvs'] ?> PDVs</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    </div>
<?php endif; ?>
