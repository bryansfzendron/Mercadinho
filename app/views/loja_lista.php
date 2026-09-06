<?php
/**
 * @var array  $itens
 * @var array  $totais
 * @var array  $pdvs
 * @var string $busca
 * @var int    $pdv_id
 * @var string $ordem
 * @var bool   $so_com
 */
$ordens = [
    'nome'         => 'nome',
    'preco'        => 'menor preço',
    'preco_desc'   => 'maior preço',
    'estoque'      => 'menor estoque',
    'estoque_desc' => 'maior estoque',
    'categoria'    => 'categoria',
];
?>
<h1>Loja</h1>

<div class="acoes-topo">
    <a class="botao botao-grande" href="/vendas">R$ Relatório de vendas</a>
</div>

<p class="ajuda">Tudo que está cadastrado nos pontos de venda, com o preço de venda e o estoque da última sincronização.</p>

<?php if (!$pdvs): ?>
    <p class="vazio">
        Nenhum ponto de venda sincronizado ainda.
        <a href="/">Atualizar preços e estoque</a>
    </p>
<?php else: ?>

<form method="get" action="/loja">
    <div class="linha-form">
        <input type="text" name="q" value="<?= e($busca) ?>"
               placeholder="buscar por nome, código ou categoria"
               autocomplete="off" autocorrect="off" spellcheck="false">
        <button type="submit" class="botao botao-alt">Buscar</button>
    </div>

    <div class="filtros">
        <label>
            Ponto de venda
            <select name="pdv" onchange="this.form.submit()">
                <option value="0">todos</option>
                <?php foreach ($pdvs as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $pdv_id === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= e($p['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Ordenar por
            <select name="ordem" onchange="this.form.submit()">
                <?php foreach ($ordens as $chave => $rotulo): ?>
                    <option value="<?= e($chave) ?>" <?= $ordem === $chave ? 'selected' : '' ?>>
                        <?= e($rotulo) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <label class="caixa-marcar">
        <input type="checkbox" name="estoque" value="1" <?= $so_com ? 'checked' : '' ?>
               onchange="this.form.submit()">
        só com estoque
    </label>
</form>

<div class="numeros">
    <div class="numero">
        <strong><?= (int) $totais['itens'] ?></strong>
        <span>itens</span>
    </div>
    <div class="numero">
        <strong><?= (int) $totais['com_estoque'] ?></strong>
        <span>com estoque</span>
    </div>
    <div class="numero">
        <strong><?= moeda($totais['valor_venda']) ?></strong>
        <span>na prateleira</span>
    </div>
</div>

<?php if (!$itens): ?>
    <p class="vazio">
        <?= $busca !== '' ? 'Nada encontrado para essa busca.' : 'Nenhum item neste filtro.' ?>
    </p>
<?php else: ?>
    <?php if (count($itens) >= 400): ?>
        <p class="ajuda">Mostrando os primeiros 400. Use a busca para afinar.</p>
    <?php endif; ?>
    <ul class="lista">
        <?php foreach ($itens as $l): ?>
            <li>
                <?php
                // Produto ja conhecido leva para o historico; o resto e so leitura.
                $destino = $l['produto_id'] ? '/produtos/' . (int) $l['produto_id'] : null;
                ?>
                <?php if ($destino): ?><a href="<?= $destino ?>"><?php else: ?><div style="padding:.7rem .85rem"><?php endif; ?>
                    <div class="linha-topo">
                        <span class="forte"><?= e($l['descricao']) ?></span>
                        <span class="valor">
                            <?= $l['preco_venda'] === null ? '—' : moeda($l['preco_venda']) ?>
                        </span>
                    </div>
                    <div class="linha-baixo">
                        <span>
                            <?php if ((float) $l['estoque'] > 0): ?>
                                <?= qtd_fmt($l['estoque']) ?> em estoque
                            <?php else: ?>
                                <span class="zerado">sem estoque</span>
                            <?php endif; ?>
                            · <?= e($l['pdv']) ?>
                        </span>
                        <span class="mono"><?= $l['ean'] ? e($l['ean']) : e($l['codigo'] ?: 'sem código') ?></span>
                    </div>
                    <?php if ($l['categoria'] || $destino): ?>
                        <div class="linha-baixo">
                            <span class="ajuda"><?= e($l['categoria'] ?: '') ?></span>
                            <?php if ($destino): ?>
                                <span class="selo selo-ok">no seu histórico</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php if ($destino): ?></a><?php else: ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php endif; ?>
