<?php
/**
 * As compras recentes, direto do "Ver detalhes" do inicio.
 *
 * Sem filtro na tela: a pergunta aqui e "o que saiu agora ha pouco". Quando a
 * pergunta vira "quanto o PDV X vendeu no Pix em agosto", o botao flutuante
 * leva para /vendas/transacoes, que e a tela de analise e continua intacta.
 *
 * Cada compra abre em sanfona (<details>), do mesmo jeito que la — o
 * movimento.js anima a altura.
 *
 * @var array $linhas uma transação por linha, da mais recente para a mais antiga
 * @var array $itens  venda_id => itens daquela compra
 * @var array $pag    a paginação já calculada
 * @var array $f      o período mostrado
 * @var array $formas forma bruta => nome de gente
 */
?>
<h1 class="titulo-icone">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M6.5 3h7l4 4v13a1 1 0 0 1-1 1h-10a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/>
        <path d="M13.5 3v4h4"/>
        <path d="M8.5 12.5h7M8.5 16h5"/>
    </svg>
    Transações recentes
</h1>
<p class="meta">Últimas compras dos seus pontos de venda.</p>

<p class="faixa-periodo"><span>Últimos 30 dias</span></p>

<?php if (!$linhas): ?>
    <p class="vazio">Nenhuma compra nos últimos 30 dias.</p>
<?php else: ?>
    <ul class="lista solta">
        <?php foreach ($linhas as $v): $lista = $itens[(int) $v['id']] ?? []; ?>
            <li>
                <details class="compra">
                    <summary>
                        <div class="linha-topo">
                            <span class="forte corta"><?= e($v['pdv']) ?></span>
                            <span class="valor"><?= moeda($v['valor_pago']) ?></span>
                        </div>
                        <div class="linha-baixo">
                            <span><?= data_fmt($v['data_hora'], true) ?></span>
                            <span class="corta">
                                <?= e($formas[$v['forma_pagamento']] ?? $v['forma_pagamento'] ?? 'sem forma') ?>
                                <?php if (!empty($v['bandeira'])): ?> · <?= e($v['bandeira']) ?><?php endif; ?>
                                · <?= count($lista) ?> <?= count($lista) === 1 ? 'item' : 'itens' ?>
                            </span>
                        </div>
                    </summary>

                    <div class="compra-detalhe">
                        <dl class="dados">
                            <div>
                                <dt>Data</dt>
                                <dd><?= data_fmt($v['data_hora'], true) ?></dd>
                            </div>
                            <div>
                                <dt>Pagamento</dt>
                                <dd>
                                    <?= e($formas[$v['forma_pagamento']] ?? $v['forma_pagamento'] ?? 'sem forma') ?>
                                    <?php if (!empty($v['bandeira'])): ?> · <?= e($v['bandeira']) ?><?php endif; ?>
                                </dd>
                            </div>
                            <?php if (!empty($v['codigo'])): ?>
                                <div>
                                    <dt>Código</dt>
                                    <dd class="chave"><?= e($v['codigo']) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>

                        <?php if (!$lista): ?>
                            <p class="ajuda">Compra sem itens gravados.</p>
                        <?php else: ?>
                            <h2 class="sem-topo">Produtos</h2>
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
                        <?php endif; ?>
                    </div>
                </details>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ((int) $pag['paginas'] > 1): ?>
        <div class="paginacao">
            <?php if ((int) $pag['pagina'] > 1): ?>
                <a class="botao botao-alt" href="/transacoes?p=<?= (int) $pag['pagina'] - 1 ?>">Anteriores</a>
            <?php endif; ?>
            <span class="ajuda">página <?= (int) $pag['pagina'] ?> de <?= (int) $pag['paginas'] ?></span>
            <?php if ((int) $pag['pagina'] < (int) $pag['paginas']): ?>
                <a class="botao botao-alt" href="/transacoes?p=<?= (int) $pag['pagina'] + 1 ?>">Seguintes</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php // O filtro nao mora aqui: quem precisa dele vai para a tela de analise. ?>
<a class="flutuante" href="/vendas/transacoes?de=<?= e($f['de']) ?>&ate=<?= e($f['ate']) ?>"
   aria-label="Filtrar transações" title="Filtrar transações">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M4 5h16l-6.2 7.3V19l-3.6-2v-4.7Z"/>
    </svg>
</a>
