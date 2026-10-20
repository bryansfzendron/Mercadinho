<?php
/**
 * Um dia do Inicio contra o painel do TouchPay.
 *
 * Em cima, os dois numeros lado a lado. Embaixo, cada transacao do TouchPay
 * num caso que diz por que ela esta (ou nao) no numero da capa — o caso que
 * explica a diferenca vem primeiro, o que bate vem por ultimo e fechado.
 *
 * @var string  $dia
 * @var ?array  $c        de vendas_conferir_dia(); null quando o TouchPay falhou
 * @var ?string $erro
 * @var array   $casos    de vendas_conferir_casos()
 * @var array   $formas   forma bruta => nome de gente
 * @var string  $pdv_capa o PDV que a capa esta mostrando; vazio e todos
 */
?>
<a class="voltar" href="/config">‹ Configurações</a>
<h1>Conferir vendas</h1>
<p class="meta">As transações do dia no TouchPay, uma a uma, contra o que o Início soma.</p>

<form method="get" action="/config/conferir">
    <div class="linha-form">
        <input type="date" name="dia" value="<?= e($dia) ?>" aria-label="Dia">
        <button type="submit" class="botao">Conferir</button>
    </div>
</form>

<?php if ($pdv_capa !== ''): ?>
    <p class="aviso aviso-info">
        O Início está mostrando só <strong><?= e($pdv_capa) ?></strong>. O número abaixo é o de
        todos os pontos de venda. <a href="/?pdv_id=0">Ver todos no Início</a>
    </p>
<?php endif; ?>

<?php if ($erro !== null): ?>
    <p class="aviso aviso-erro"><?= e($erro) ?></p>
<?php else: ?>
    <?php
    $tp = $c['touchpay'];
    $in = $c['inicio'];
    $falta_n    = $tp['n'] - $in['n'];
    $falta_pago = $tp['pago'] - $in['pago'];
    ?>
    <div class="cartao">
        <h2 class="sem-topo"><?= data_fmt($dia) ?></h2>
        <dl class="dados">
            <div><dt>TouchPay</dt><dd><?= (int) $tp['n'] ?> transações · <?= moeda($tp['pago']) ?></dd></div>
            <div><dt>Início</dt><dd><?= (int) $in['n'] ?> transações · <?= moeda($in['pago']) ?></dd></div>
            <div>
                <dt>Diferença</dt>
                <?php $bate = $falta_n === 0 && abs($falta_pago) < 0.005; ?>
                <dd class="<?= $bate ? 'lucro-bom' : 'lucro-ruim' ?>">
                    <?php if ($bate): ?>
                        nenhuma
                    <?php else: ?>
                        <?= $falta_n > 0 ? 'faltam ' . $falta_n . ' no Início'
                            : ($falta_n < 0 ? abs($falta_n) . ' a mais no Início' : 'mesma quantidade') ?>
                        · <?= moeda(abs($falta_pago)) ?> <?= $falta_pago >= 0 ? 'a menos' : 'a mais' ?>
                    <?php endif; ?>
                </dd>
            </div>
            <?php if (abs($tp['total'] - $tp['pago']) >= 0.005): ?>
                <div><dt>Preço cheio no TouchPay</dt><dd><?= moeda($tp['total']) ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if (abs($tp['total'] - $tp['pago']) >= 0.005): ?>
            <p class="ajuda">
                O Início soma o valor <strong>pago</strong>. O preço cheio é a soma antes de
                desconto: se o painel mostra esse, a diferença no valor vem daí.
            </p>
        <?php endif; ?>
        <?php if ((int) $c['repetidas'] > 0): ?>
            <p class="ajuda">O TouchPay devolveu <?= (int) $c['repetidas'] ?> transação(ões) repetida(s); contei uma vez só.</p>
        <?php endif; ?>
        <?php if ($c['total_api'] !== null && (int) $c['total_api'] !== $tp['n'] + (int) $c['repetidas']): ?>
            <p class="aviso aviso-info">
                O TouchPay disse ter <?= (int) $c['total_api'] ?> transações, mas devolveu
                <?= $tp['n'] + (int) $c['repetidas'] ?>.
            </p>
        <?php endif; ?>
    </div>

    <?php if (count($tp['por_resultado']) > 1 || count($tp['por_pdv']) > 1): ?>
        <div class="cartao">
            <h2 class="sem-topo">No TouchPay</h2>
            <dl class="dados">
                <?php foreach ($tp['por_resultado'] as $r => $x): ?>
                    <div><dt>Resultado <?= e($r) ?></dt><dd><?= (int) $x['n'] ?> · <?= moeda($x['pago']) ?></dd></div>
                <?php endforeach; ?>
                <?php foreach ($tp['por_pdv'] as $p => $x): ?>
                    <div><dt><?= e($p) ?></dt><dd><?= (int) $x['n'] ?> · <?= moeda($x['pago']) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </div>
    <?php endif; ?>

    <?php foreach ($casos as $chave => [$titulo, $explica]): $caso = $c['casos'][$chave]; ?>
        <?php if ((int) $caso['n'] === 0) { continue; } ?>
        <details class="cartao compra" <?= $chave === 'na_tela' ? '' : 'open' ?>>
            <summary>
                <div class="linha-topo">
                    <span class="forte"><?= e($titulo) ?></span>
                    <span class="valor"><?= (int) $caso['n'] ?> · <?= moeda($caso['pago']) ?></span>
                </div>
                <div class="linha-baixo"><span class="ajuda"><?= e($explica) ?></span></div>
            </summary>
            <div class="compra-detalhe">
            <ul class="itens-compra">
                <?php foreach ($caso['linhas'] as $l): $b = $l['banco']; ?>
                    <li>
                        <span class="item-nome">
                            <?= hora_fmt($l['data_hora']) ?> · <?= e($l['pdv'] !== '' ? $l['pdv'] : 'sem PDV') ?>
                            · <?= e($formas[$l['forma_pagamento']] ?? $l['forma_pagamento'] ?? 'sem forma') ?>
                            <?php if ($chave === 'resultado' || ($l['resultado'] !== null && strcasecmp((string) $l['resultado'], 'Ok') !== 0)): ?>
                                · <?= e((string) ($b['resultado'] ?? $l['resultado'])) ?>
                            <?php endif; ?>
                            <?php if ($chave === 'outro_dia' && $b): ?>
                                · gravada <?= data_fmt($b['data_hora'], true) ?>
                            <?php endif; ?>
                            <?php if ($chave === 'valor_diferente' && $b): ?>
                                · no banco <?= moeda($b['valor_pago']) ?>
                            <?php endif; ?>
                            <span class="item-qtd">#<?= (int) $l['externo_id'] ?><?= !empty($l['codigo']) ? ' · ' . e(mb_substr((string) $l['codigo'], 0, 8)) : '' ?></span>
                        </span>
                        <span class="item-valor"><?= moeda($l['valor_pago']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            </div>
        </details>
    <?php endforeach; ?>

    <p class="ajuda">
        <strong>Arraste a tela para baixo</strong> para rebuscar este dia no TouchPay e regravar.
        Regravar não duplica. Se depois disso ainda sobrar “Nunca foi importada”, o problema é o sync.
    </p>
<?php endif; ?>

<script>
// O gesto de arrastar le isto e rebusca so este dia, como na tela de Vendas.
window.SYNC_JANELA = <?= json_encode(['de' => $dia, 'ate' => $dia]) ?>;
</script>
