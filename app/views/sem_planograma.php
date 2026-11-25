<?php
/**
 * Produtos das notas fiscais que nao estao no planograma, um bloco recolhido por mercado.
 * Cada produto ja vem com fator 2, o preco e a quantidade da ultima nota: so conferir e repor.
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
        Abra um mercado, confira o preço e a quantidade e toque em Repor.
    </p>
</div>

<?php if (!$grupos): ?>
    <p class="vazio">Nenhum ponto de venda com planograma ativo.</p>
<?php endif; ?>

<?php foreach ($grupos as $g):
    $pdv = $g['pdv'];
    $teto = 300;
    $itens = array_slice($g['itens'], 0, $teto);
?>
    <details class="cartao sp-mercado"<?= $busca !== '' && $itens ? ' open' : '' ?>>
        <summary>
            <?= cartao_cabeca('caixa', $pdv['nome'],
                !$g['sincronizado'] ? 'planograma ainda não lido' : number_format($g['total'], 0, ',', '.') . ' produto(s) fora do planograma') ?>
        </summary>
        <?php if (!$g['sincronizado']): ?>
            <p class="vazio">O planograma deste mercado ainda não foi sincronizado. Arraste a tela para baixo para atualizar.</p>
        <?php elseif (!$itens): ?>
            <p class="vazio">Tudo o que veio em nota está no planograma. Bom sinal.</p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($itens as $p):
                    $custo = $p['custo'] !== null ? (float) $p['custo'] : 0.0;
                    $qtd = (int) round((float) $p['qtd']);
                ?>
                    <li><div class="linha-cartao">
                        <div class="linha-topo">
                            <span class="forte"><?= e($p['descricao']) ?></span>
                            <span class="valor"><?= $custo > 0 ? moeda($custo) : '—' ?></span>
                        </div>
                        <div class="linha-baixo">
                            <span><?= (int) $p['vezes'] ?>× em nota · última <?= e(data_fmt($p['ultima'])) ?> (<?= $qtd ?> un.)</span>
                            <span class="mono"><?= e($p['ean'] ?? 'sem GTIN') ?></span>
                        </div>
                        <?php if ($p['ean'] === null || $p['ean'] === ''): ?>
                            <p class="ajuda">Sem código de barras: não dá para repor por aqui.</p>
                        <?php else: ?>
                            <div class="mg-troca sp-troca" data-pdv="<?= (int) $pdv['id'] ?>"
                                 data-codigo="<?= e($p['ean']) ?>" data-custo="<?= e(number_format($custo, 4, '.', '')) ?>">
                                <label>Fator (x)
                                    <input type="text" class="mg-fator" inputmode="decimal" placeholder="2">
                                </label>
                                <label>Preço
                                    <input type="text" class="mg-preco" inputmode="decimal">
                                </label>
                                <label>Quantidade
                                    <input type="text" class="sp-qtd" inputmode="numeric" value="<?= $qtd ?>">
                                </label>
                                <button type="button" class="botao mg-salvar">Repor</button>
                                <span class="ajuda mg-msg" aria-live="polite"></span>
                            </div>
                        <?php endif; ?>
                    </div></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($g['total'] > $teto): ?>
                <p class="ajuda">Mostrando os <?= $teto ?> primeiros de <?= number_format($g['total'], 0, ',', '.') ?>. Use a busca para afinar.</p>
            <?php endif; ?>
        <?php endif; ?>
    </details>
<?php endforeach; ?>
<script src="/assets/sem-planograma.js?v=1" defer></script>
