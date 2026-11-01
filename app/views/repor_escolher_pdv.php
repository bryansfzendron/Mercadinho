<?php
/**
 * Primeiro passo do Repor: para qual ponto de venda vai a mercadoria.
 *
 * O PDV vinha pre-escolhido com o da ultima vez, e trocar dependia de lembrar
 * do filtro no topo — esquecer era repor (e mudar preco) no container errado.
 * Agora a tela pergunta antes, toda vez, e so a escolha abre os itens.
 *
 * @var array   $pdvs    so os que tem planograma ativo
 * @var int     $ultimo  PDV da ultima vez, so para marcar na lista
 * @var string  $link    prefixo do link; o id do PDV vai no fim
 * @var array   $voltar  [href, rotulo]
 * @var string  $titulo_tela
 * @var string  $contexto
 * @var ?string $erro_tp
 */
?>
<a class="voltar" href="<?= e($voltar[0]) ?>">‹ <?= e($voltar[1]) ?></a>
<h1><?= e($titulo_tela) ?></h1>
<p class="ajuda"><?= e($contexto) ?></p>

<?php if ($erro_tp): ?>
    <div class="aviso aviso-erro"><strong>TouchPay:</strong> <?= e($erro_tp) ?></div>
<?php endif; ?>

<h2>Qual ponto de venda você vai repor?</h2>
<ul class="lista escolher-pdv">
    <?php foreach ($pdvs as $p): ?>
        <li><a href="<?= e($link . (int) $p['id']) ?>">
            <div class="linha-topo">
                <span class="forte"><?= e($p['nome']) ?></span>
                <span class="valor">›</span>
            </div>
            <?php if ((int) $p['id'] === $ultimo): ?>
                <div class="linha-baixo"><span>da última vez</span></div>
            <?php endif; ?>
        </a></li>
    <?php endforeach; ?>
</ul>
