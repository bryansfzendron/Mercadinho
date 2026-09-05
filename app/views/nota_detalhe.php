<?php /** @var array $nota */ ?>
<a class="voltar" href="/notas">‹ Notas</a>

<div class="cartao">
    <h1><?= e($nota['loja'] ?? 'Sem loja identificada') ?></h1>
    <p class="meta">
        <?= cnpj_fmt($nota['cnpj']) ?><br>
        <?= e(trim(($nota['municipio'] ?? '') . ' ' . ($nota['uf'] ?? ''))) ?>
    </p>
    <dl class="dados">
        <div><dt>Emissão</dt><dd><?= data_fmt($nota['emissao'], true) ?></dd></div>
        <div><dt>Número</dt><dd><?= e($nota['numero'] ?: '-') ?> / série <?= e($nota['serie'] ?: '-') ?></dd></div>
        <div><dt>Produtos</dt><dd><?= moeda($nota['valor_produtos']) ?></dd></div>
        <div><dt>Desconto</dt><dd><?= moeda($nota['desconto_total']) ?></dd></div>
        <div><dt>Total</dt><dd class="valor"><?= moeda($nota['valor_total']) ?></dd></div>
        <div><dt>Origem</dt><dd><?= e($nota['origem']) ?></dd></div>
    </dl>
    <?php if ($nota['chave']): ?>
        <p class="chave"><?= e($nota['chave']) ?></p>
    <?php endif; ?>
    <?php if ($nota['status'] === 'erro' && $nota['erro_msg']): ?>
        <div class="aviso aviso-erro"><?= e($nota['erro_msg']) ?></div>
    <?php endif; ?>
</div>

<h2><?= count($nota['itens']) ?> itens</h2>
<ul class="lista">
    <?php foreach ($nota['itens'] as $i): ?>
        <li>
            <a href="<?= $i['produto_id'] ? '/produtos/' . (int) $i['produto_id'] : '#' ?>">
                <div class="linha-topo">
                    <span class="forte"><?= e($i['descricao_original']) ?></span>
                    <span class="valor"><?= moeda($i['valor_total']) ?></span>
                </div>
                <div class="linha-baixo">
                    <span>
                        <?= qtd_fmt($i['quantidade']) ?> <?= e($i['unidade'] ?: 'un') ?>
                        × <?= moeda($i['valor_unitario']) ?>
                        <?php if ((float) $i['desconto'] > 0): ?>
                            · desc. <?= moeda($i['desconto']) ?>
                        <?php endif; ?>
                    </span>
                    <span class="mono">
                        <?= $i['produto_ean'] ? e($i['produto_ean']) : 'sem GTIN' ?>
                    </span>
                </div>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
