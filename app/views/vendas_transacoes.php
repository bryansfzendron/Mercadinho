<?php
/**
 * Cada compra do período, com os produtos que saíram nela.
 *
 * @var array $linhas uma transação por linha, da mais recente para a mais antiga
 * @var array $itens  venda_id => itens daquela compra
 * @var array $pag    a paginação já calculada
 * @var array $f      os filtros
 * @var array $pdvs
 * @var array $formas
 * @var array $categorias
 */

/** Os filtros de novo na URL, para paginar e trocar de aba sem perdê-los. */
$query = static fn (array $extra = []): string => http_build_query($extra + [
    'de' => $f['de'], 'ate' => $f['ate'], 'pdv_id' => $f['pdv_id'] ?: '',
    'forma' => $f['forma'], 'categoria' => $f['categoria'], 'q' => $f['busca'],
]);
?>
<h1>Loja</h1>
<?= abas_loja('/vendas') ?>
<?= abas(['/vendas' => 'Resumo', '/vendas/transacoes' => 'Transações'], '/vendas/transacoes') ?>

<div class="chips">
    <?php foreach (vendas_periodos() as [$rotulo, $de, $ate]): ?>
        <a class="chip <?= $f['de'] === $de && $f['ate'] === $ate ? 'ativo' : '' ?>"
           href="/vendas/transacoes?<?= e($query(['de' => $de, 'ate' => $ate])) ?>"><?= e($rotulo) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="/vendas/transacoes">
    <div class="filtros">
        <label>De <input type="date" name="de" value="<?= e($f['de']) ?>"></label>
        <label>Até <input type="date" name="ate" value="<?= e($f['ate']) ?>"></label>
        <label>Ponto de venda
            <select name="pdv_id">
                <option value="">todos</option>
                <?php foreach ($pdvs as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) $f['pdv_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= e($p['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Pagamento
            <select name="forma">
                <option value="">todas</option>
                <?php foreach ($formas as $nome => $rotulo): ?>
                    <option value="<?= e($nome) ?>" <?= $f['forma'] === $nome ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Categoria
            <select name="categoria">
                <option value="">todas</option>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $f['categoria'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="linha-form">
        <input type="text" name="q" value="<?= e($f['busca']) ?>" placeholder="produto ou código da compra"
               autocomplete="off" autocorrect="off" spellcheck="false">
        <button type="submit" class="botao">Filtrar</button>
    </div>
    <p class="ajuda">
        A busca procura dentro da compra: digitar um produto traz as compras que o levaram,
        e com ele o que mais foi junto. A categoria funciona igual: filtra a compra inteira,
        não só o item daquela categoria.
    </p>
</form>

<?php if (!$linhas): ?>
    <p class="vazio">Nenhuma compra no período.</p>
<?php else: ?>
    <p class="ajuda">
        <?= (int) $pag['de'] ?>–<?= (int) $pag['ate'] ?> de <?= (int) $pag['total'] ?> compra(s).
        Toque numa para ver os produtos.
    </p>

    <ul class="lista">
        <?php foreach ($linhas as $v): $lista = $itens[(int) $v['id']] ?? []; ?>
            <li>
                <details class="compra">
                    <summary>
                        <div class="linha-topo">
                            <span class="forte"><?= data_fmt($v['data_hora'], true) ?></span>
                            <span class="valor"><?= moeda($v['valor_pago']) ?></span>
                        </div>
                        <div class="linha-baixo">
                            <span>
                                <?= e($v['pdv']) ?> ·
                                <?= e($formas[$v['forma_pagamento']] ?? $v['forma_pagamento'] ?? 'sem forma') ?>
                                <?php if (!empty($v['bandeira'])): ?> <?= e($v['bandeira']) ?><?php endif; ?>
                            </span>
                            <span><?= count($lista) ?> item(ns)</span>
                        </div>
                    </summary>

                    <?php if (!$lista): ?>
                        <p class="ajuda">Compra sem itens gravados.</p>
                    <?php else: ?>
                        <ul class="itens-compra">
                            <?php foreach ($lista as $i): ?>
                                <li>
                                    <span class="item-nome">
                                        <?php if ((int) $i['produto_id'] > 0): ?>
                                            <a href="/produtos/<?= (int) $i['produto_id'] ?>"><?= e($i['descricao']) ?></a>
                                        <?php else: ?>
                                            <?= e($i['descricao']) ?>
                                        <?php endif; ?>
                                        <?php if (num_br($i['quantidade']) != 1.0): ?>
                                            <span class="item-qtd"><?= qtd_fmt($i['quantidade']) ?>×</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="item-valor"><?= moeda($i['valor_total']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (!empty($v['codigo'])): ?>
                            <p class="ajuda">Código no TouchPay: <?= e($v['codigo']) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </details>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ((int) $pag['paginas'] > 1): ?>
        <div class="paginacao">
            <?php if ((int) $pag['pagina'] > 1): ?>
                <a class="botao botao-alt" href="/vendas/transacoes?<?= e($query(['p' => $pag['pagina'] - 1])) ?>">Anteriores</a>
            <?php endif; ?>
            <span class="ajuda">página <?= (int) $pag['pagina'] ?> de <?= (int) $pag['paginas'] ?></span>
            <?php if ((int) $pag['pagina'] < (int) $pag['paginas']): ?>
                <a class="botao botao-alt" href="/vendas/transacoes?<?= e($query(['p' => $pag['pagina'] + 1])) ?>">Seguintes</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
