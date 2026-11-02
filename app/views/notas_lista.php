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
 * @var int   $discrepancias itens que parecem caixa lancada como unidade
 * @var array $loja        espelho do TouchPay
 * @var array $vendas      resumo das vendas importadas
 */
?>
<?= titulo_icone('Minhas notas', 'nota') ?>

<?php // Os quatro jeitos de lancar, em blocos com icone como as abas da Loja. ?>
<?= abas([
    '/escanear' => 'Escanear',
    '/bipar'    => 'Bipar',
    '/manual'   => 'Manual',
    '/importar' => 'Planilha',
], '', [], [
    '/escanear' => icone('camera'),
    '/bipar'    => icone('codigo'),
    '/manual'   => icone('nota'),
    '/importar' => icone('lista'),
]) ?>

<?php if ($pendentes > 0): ?>
    <div class="aviso aviso-info">
        <?= (int) $pendentes ?> nota(s) ainda em processamento.
    </div>
<?php endif; ?>

<?php if ($discrepancias > 0): ?>
    <div class="aviso aviso-info">
        <?= (int) $discrepancias ?> item(ns) parece(m) caixa lançada como unidade, e isso distorce o custo das vendas.
        <a href="/notas/discrepancias">Conferir</a>
    </div>
<?php endif; ?>

<div class="cartao">
    <?= cartao_cabeca('carrinho', 'Suas compras', (int) $resumo['notas'] . ' notas · ' . (int) $itens_total . ' itens', '', true) ?>
    <div class="destaque">
        <strong><?= moeda($resumo['total']) ?></strong>
        <span>total gasto</span>
    </div>
</div>

<?php if (!$notas): ?>
    <p class="vazio">Nenhuma nota ainda. Escaneie o QR Code de um cupom para começar.</p>
<?php else: $recolhe = count($notas) > LISTA_A_VISTA; ?>
    <div class="cartao<?= $recolhe ? ' recolhivel' : '' ?>">
    <?= cartao_cabeca('nota', 'Notas', count($notas) . ' lançada(s)') ?>
    <ul class="lista">
        <?php foreach ($notas as $k => $n): ?>
            <li class="<?= $recolhe && $k >= LISTA_A_VISTA ? 'extra' : '' ?>">
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
    <?= $recolhe ? ver_mais_baixo('Ver todas (' . count($notas) . ')') : '' ?>
    </div>
<?php endif; ?>

<div class="cartao">
<?= cartao_cabeca('loja', 'Loja (TouchPay)', $loja ? count($loja) . ' ponto(s) de venda' : null,
    '<a class="ver-mais" href="/loja">Catálogo' . icone('seta-dir') . '</a>') ?>
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
</div>

<div class="cartao">
<?= cartao_cabeca('grafico', 'Vendas (TouchPay)', null,
    '<a class="ver-mais" href="/vendas">Relatório' . icone('seta-dir') . '</a>') ?>
<?php if ((int) $vendas['vendas'] === 0): ?>
    <p class="vazio">
        Nenhuma venda importada ainda.
        <a href="/config">Importar os últimos 12 meses</a>
    </p>
<?php else: ?>
    <div class="destaque">
        <strong><?= moeda($vendas['total']) ?></strong>
        <span><?= (int) $vendas['vendas'] ?> vendas</span>
    </div>
    <p class="ajuda">
        De <?= data_fmt($vendas['primeira']) ?> a <?= data_fmt($vendas['ultima'], true) ?>.
    </p>
<?php endif; ?>
</div>

<p class="ajuda centro">
    <a href="/config">Sincronizar preços, estoque e vendas</a>
</p>
