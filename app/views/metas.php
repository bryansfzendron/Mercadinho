<?php /** @var array $fat @var array $luc @var array $p @var string $mes @var array $pdvs */ ?>
<h1>Loja</h1>
<?= abas_loja('/metas') ?>

<p class="meta"><?= e($mes) ?> · dia <?= (int) $fat['dia'] ?> de <?= (int) $fat['no_mes'] ?></p>

<?php
/** Um cartão de meta: quanto entrou, quanto falta e se o ritmo chega lá. */
function cartao_meta(array $m, string $titulo, string $nome_campo): void
{ ?>
    <div class="cartao">
        <div class="meta-topo">
            <span class="forte"><?= e($titulo) ?></span>
            <?php if ($m['tem_meta']): ?>
                <span class="selo <?= $m['batida'] ? 'selo-ok' : ($m['projecao'] >= $m['meta'] ? 'selo-ok' : 'selo-pendente') ?>">
                    <?= $m['batida'] ? 'batida' : ($m['projecao'] >= $m['meta'] ? 'no ritmo' : 'abaixo do ritmo') ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="meta-topo">
            <span class="meta-valor"><?= moeda($m['realizado']) ?></span>
            <?php if ($m['tem_meta']): ?>
                <span class="meta-alvo">de <?= moeda($m['meta']) ?> · <?= number_format($m['pct'], 0, ',', '.') ?>%</span>
            <?php endif; ?>
        </div>

        <?php if ($m['tem_meta']): ?>
            <div class="meta-barra <?= $m['batida'] ? 'batida' : '' ?>">
                <span style="width: <?= min(100, max(0, round($m['pct']))) ?>%"></span>
            </div>
            <p class="ajuda">
                <?php if ($m['batida']): ?>
                    Meta batida. Passou <?= moeda($m['realizado'] - $m['meta']) ?> do alvo.
                <?php elseif ($m['restam'] > 0): ?>
                    Faltam <?= moeda($m['falta']) ?> em <?= (int) $m['restam'] ?> dia(s) —
                    <strong><?= moeda($m['por_dia']) ?> por dia</strong>.
                    No ritmo atual o mês fecha em <?= moeda($m['projecao']) ?>.
                <?php else: ?>
                    Último dia do mês: faltam <?= moeda($m['falta']) ?>.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="ajuda">
                Sem meta definida. No ritmo atual (<?= moeda($m['ritmo']) ?> por dia)
                o mês fecha em <?= moeda($m['projecao']) ?>.
            </p>
        <?php endif; ?>
    </div>
<?php }

cartao_meta($fat, 'Faturamento do mês', 'meta_faturamento');
cartao_meta($luc, 'Lucro do mês', 'meta_lucro');
?>

<div class="cartao">
    <h2 class="sem-topo">Definir as metas</h2>
    <form method="post" action="/metas">
        <?= csrf_campo() ?>
        <div class="filtros">
            <label>Faturamento (R$/mês)
                <input type="text" inputmode="decimal" name="meta_faturamento"
                       value="<?= e(number_format($p['meta_faturamento'], 2, ',', '')) ?>">
            </label>
            <label>Lucro (R$/mês)
                <input type="text" inputmode="decimal" name="meta_lucro"
                       value="<?= e(number_format($p['meta_lucro'], 2, ',', '')) ?>">
            </label>
        </div>
        <p class="ajuda">
            Zero desliga a meta e deixa só a projeção. O lucro sai do mesmo cálculo da aba
            Vendas: já com mercadoria, maquininha, condomínio, franquia e os fixos do mês.
        </p>

        <?php if (count($pdvs) > 1): ?>
            <h2>Pontos de venda</h2>
            <p class="ajuda">
                Desmarcar tira o ponto de venda do app inteiro — catálogo, bipe, vendas e
                metas. Serve para o PDV que está na mesma conta do TouchPay mas não é seu:
                o sync continua trazendo, as telas é que ignoram.
            </p>
            <input type="hidden" name="pdvs_enviados" value="1">
            <?php foreach ($pdvs as $pdv): ?>
                <label class="caixa-marcar">
                    <input type="checkbox" name="pdvs[]" value="<?= (int) $pdv['id'] ?>"
                           <?= (int) $pdv['ativo'] === 1 ? 'checked' : '' ?>>
                    <?= e($pdv['nome']) ?>
                    <span class="ajuda"><?= (int) $pdv['itens'] ?> itens</span>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
        <button type="submit" class="botao">Salvar metas</button>
    </form>
</div>
