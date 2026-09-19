<?php
/**
 * Minhas notas — a tela que absorveu o antigo inicio.
 *
 * As duas eram quase a mesma coisa: o inicio mostrava as 8 ultimas notas com
 * os mesmos botoes em cima, e aqui vinham as 200. Agora a lista mora num
 * lugar so, com os numeros e os resumos que o inicio carregava.
 *
 * @var array $notas
 * @var array $resumo      notas e total gasto
 * @var int   $itens_total
 * @var int   $pendentes
 * @var array $loja        espelho do TouchPay
 * @var array $vendas      resumo das vendas importadas
 */
?>
<h1>Minhas notas</h1>

<div class="acoes-topo">
    <a class="botao botao-grande" href="/escanear">▣ Escanear nota</a>
    <a class="botao botao-grande botao-alt" href="/bipar">||| Bipar produto</a>
    <a class="botao botao-alt" href="/manual">+ Lançar nota manualmente</a>
</div>

<?php if ($pendentes > 0): ?>
    <div class="aviso aviso-info">
        <?= (int) $pendentes ?> nota(s) ainda em processamento.
    </div>
<?php endif; ?>

<div class="numeros">
    <div class="numero">
        <strong><?= (int) $resumo['notas'] ?></strong>
        <span>notas</span>
    </div>
    <div class="numero">
        <strong><?= (int) $itens_total ?></strong>
        <span>itens</span>
    </div>
    <div class="numero">
        <strong><?= moeda($resumo['total']) ?></strong>
        <span>total gasto</span>
    </div>
</div>

<?php if (!$notas): ?>
    <p class="vazio">Nenhuma nota ainda. Escaneie o QR Code de um cupom para começar.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($notas as $n): ?>
            <li>
                <a href="/notas/<?= (int) $n['id'] ?>">
                    <div class="linha-topo">
                        <span class="forte"><?= e($n['loja'] ?? 'Sem loja identificada') ?></span>
                        <span class="valor"><?= $n['valor_total'] !== null ? moeda($n['valor_total']) : '-' ?></span>
                    </div>
                    <div class="linha-baixo">
                        <span><?= data_fmt($n['emissao']) ?> · <?= (int) $n['qtd_itens'] ?> itens</span>
                        <span class="selo selo-<?= e($n['status']) ?>">
                            <?= $n['origem'] === 'manual' ? 'manual' : e($n['status']) ?>
                        </span>
                    </div>
                    <?php if ($n['status'] === 'erro' && $n['erro_msg']): ?>
                        <div class="erro-msg"><?= e($n['erro_msg']) ?></div>
                    <?php endif; ?>
                </a>
                <?php if ($n['status'] !== 'ok'): ?>
                    <form method="post" action="/notas/<?= (int) $n['id'] ?>/excluir" class="acao-linha"
                          onsubmit="return confirm('Remover esta nota?');">
                        <?= csrf_campo() ?>
                        <button type="submit" class="link-perigo">remover</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Loja (TouchPay)</h2>
<?php if (!$loja): ?>
    <p class="vazio">Nenhum ponto de venda sincronizado ainda.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($loja as $p): ?>
            <li><div class="linha-cartao">
                <div class="linha-topo">
                    <span class="forte"><?= e($p['nome']) ?></span>
                    <span class="valor"><?= (int) $p['itens'] ?> itens</span>
                </div>
                <div class="linha-baixo">
                    <span><?= (int) $p['vinculados'] ?> ligados ao seu histórico</span>
                    <span><?= data_fmt($p['atualizado_em'], true) ?></span>
                </div>
            </div></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p class="centro"><a href="/loja">Ver todos os produtos da loja</a></p>

<h2>Vendas (TouchPay)</h2>
<?php if ((int) $vendas['vendas'] === 0): ?>
    <p class="vazio">
        Nenhuma venda importada ainda.
        <a href="/config">Importar os últimos 12 meses</a>
    </p>
<?php else: ?>
    <div class="numeros">
        <div class="numero"><strong><?= (int) $vendas['vendas'] ?></strong><span>vendas</span></div>
        <div class="numero"><strong><?= moeda($vendas['total']) ?></strong><span>faturamento</span></div>
    </div>
    <p class="ajuda">
        De <?= data_fmt($vendas['primeira']) ?> a <?= data_fmt($vendas['ultima'], true) ?>.
        <a href="/vendas">Ver o relatório</a>.
    </p>
<?php endif; ?>

<p class="ajuda centro">
    <a href="/config">Sincronizar preços, estoque e vendas</a>
</p>
