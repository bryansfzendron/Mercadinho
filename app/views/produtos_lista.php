<?php /** @var array $produtos @var string $busca */ ?>
<h1>Produtos</h1>

<form method="get" action="/produtos" class="linha-form">
    <input type="text" name="q" value="<?= e($busca) ?>" placeholder="buscar por nome ou código">
    <button type="submit" class="botao botao-alt">Buscar</button>
</form>

<?php if (!$produtos): ?>
    <p class="vazio">
        <?= $busca !== '' ? 'Nada encontrado para essa busca.' : 'Nenhum produto ainda.' ?>
    </p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($produtos as $p): ?>
            <li>
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
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
