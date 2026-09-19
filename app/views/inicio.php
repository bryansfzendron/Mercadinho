<?php
/**
 * A capa do app: quanto vendeu hoje e como foi a semana.
 *
 * Um cartao so, com duas caras. "Atual" responde de relance (hoje em numero
 * grande, o mes logo abaixo); "Semana" abre o grafico e deixa tocar num dia
 * para ver aquele dia — sem dia escolhido, o numero e a semana inteira.
 *
 * Embaixo do cartao, o que saiu hoje. Escanear e bipar nao moram mais aqui:
 * os dois botoes ja estao na tela de notas, e repetidos na capa so empurravam
 * a lista do dia para baixo da dobra.
 *
 * @var array $painel   de vendas_painel()
 * @var array $vendidos uma linha por produto vendido hoje, do maior para o menor
 */
$h = $painel['hoje'];
$m = $painel['mes'];
$s = $painel['semana'];

/** Nome cheio do dia, para o rotulo que aparece ao tocar numa barra. */
$longos = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
?>
<p class="inicio-sub">Gestão do seu negócio</p>

<section class="painel" data-painel>
    <div class="segmento" role="tablist" aria-label="Período">
        <span class="segmento-pilula" aria-hidden="true"></span>
        <button type="button" class="segmento-aba ativo" role="tab" id="aba-atual"
                aria-selected="true" aria-controls="painel-atual" data-aba="atual">Atual</button>
        <button type="button" class="segmento-aba" role="tab" id="aba-semana"
                aria-selected="false" aria-controls="painel-semana" data-aba="semana">Semana</button>
    </div>

    <div class="painel-corpo" id="painel-atual" role="tabpanel" aria-labelledby="aba-atual" data-corpo="atual">
        <p class="painel-rotulo">Hoje</p>
        <p class="painel-valor"><?= moeda($h['total']) ?></p>

        <div class="painel-divisor">
            <span aria-hidden="true"></span>
            <a href="/transacoes">Ver detalhes</a>
        </div>

        <div class="painel-kpis">
            <div>
                <span>Total do mês</span>
                <strong><?= moeda($m['total']) ?></strong>
            </div>
            <div>
                <span>Ticket médio</span>
                <strong><?= moeda($m['ticket']) ?></strong>
            </div>
            <div>
                <span>Transações</span>
                <strong><?= (int) $m['vendas'] ?></strong>
            </div>
        </div>
    </div>

    <div class="painel-corpo" id="painel-semana" role="tabpanel" aria-labelledby="aba-semana"
         data-corpo="semana" hidden>
        <p class="painel-rotulo" data-semana-rotulo
           data-padrao="Vendas desta semana">Vendas desta semana</p>
        <p class="painel-valor" data-semana-valor
           data-padrao="<?= e(moeda($s['total'])) ?>"><?= moeda($s['total']) ?></p>

        <div class="painel-grafico">
            <div class="painel-barras">
                <?php foreach ($s['barras'] as $i => $b): ?>
                    <?php
                    $classe = 'painel-coluna'
                            . ($b['hoje'] ? ' hoje' : '')
                            . ($b['futuro'] ? ' futuro' : '');
                    $rotulo = $longos[$i] . ', ' . date('d/m', strtotime($b['data']));
                    ?>
                    <button type="button" class="<?= $classe ?>" aria-pressed="false"
                            data-dia="<?= e($rotulo) ?>" data-moeda="<?= e(moeda($b['valor'])) ?>"
                            title="<?= e($rotulo . ': ' . ($b['valor'] > 0 ? moeda($b['valor']) : 'sem venda')) ?>">
                        <span class="painel-barra"
                              style="height:<?= number_format($b['altura'], 1, '.', '') ?>%"></span>
                        <span class="painel-dia"><?= e($b['dia']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<h2>Vendidos hoje</h2>

<?php if (!$vendidos): ?>
    <p class="vazio">Nenhum produto vendido hoje ainda.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($vendidos as $p): ?>
            <?php
            $qtd = num_br($p['quantidade']);
            // Unitario e conta, nao coluna: o item grava o total da linha.
            $unitario = $qtd > 0 ? (float) $p['total'] / $qtd : null;
            $linha = '<div class="linha-topo">'
                   . '<span class="forte">' . e($p['descricao']) . '</span>'
                   . '<span class="valor">' . moeda($p['total']) . '</span>'
                   . '</div><div class="linha-baixo">'
                   . '<span>' . e(qtd_fmt($p['quantidade'])) . ' un</span>'
                   . ($unitario !== null ? '<span>' . moeda($unitario) . ' cada</span>' : '')
                   . '</div>';
            ?>
            <li>
                <?php if ((int) $p['produto_id'] > 0): ?>
                    <a href="/produtos/<?= (int) $p['produto_id'] ?>"><?= $linha ?></a>
                <?php else: ?>
                    <div class="linha-cartao"><?= $linha ?></div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
