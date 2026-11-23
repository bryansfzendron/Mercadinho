<?php
/**
 * O formulario de abrir uma caixa (ou dispensar o item), igual nas duas listas
 * da tela de discrepancias. Quem inclui define $i (linha de
 * discrepancias_itens() com candidatos e palpites) e $unidade.
 */
$id = (int) $i['id'];
?>
<form method="post" class="disc-form">
    <?= csrf_campo() ?>
    <input type="hidden" name="item" value="<?= $id ?>">

    <fieldset class="disc-unidades">
        <legend>Qual é a unidade que você vende?</legend>
        <?php foreach ($i['candidatos'] as $n => $c): ?>
            <label class="disc-opcao">
                <input type="radio" name="ean_unidade" value="<?= e($c['ean']) ?>"
                       data-venda="<?= $c['venda'] !== null ? e(number_format((float) $c['venda'], 4, '.', '')) : '' ?>"
                       <?= $n === 0 ? 'checked' : '' ?>>
                <span>
                    <span class="forte"><?= e($c['nome'] ?: 'sem nome cadastrado') ?></span><br>
                    <span class="mono"><?= e($c['ean']) ?></span>
                    <?php if ($c['venda'] !== null): ?> · vende a <?= moeda($c['venda']) ?><?php endif; ?><br>
                    <span class="selo"><?= e($c['origem']) ?></span>
                </span>
            </label>
        <?php endforeach; ?>
        <label class="disc-opcao">
            <input type="radio" name="ean_unidade" value="" class="disc-outro-radio" data-venda=""
                   <?= !$i['candidatos'] ? 'checked' : '' ?>>
            <span class="disc-outro">
                Outro código
                <span class="duas">
                    <input type="text" class="disc-outro-ean" inputmode="numeric" placeholder="código de barras da unidade"
                           autocomplete="off">
                    <button type="button" class="botao botao-alt disc-bipar">Bipar</button>
                </span>
            </span>
        </label>
    </fieldset>

    <label>Quantas unidades vêm em cada <?= e($unidade) ?>?
        <input type="text" name="por_caixa" class="disc-por" inputmode="numeric"
               value="<?= $i['palpites'] ? (int) $i['palpites'][0] : '' ?>">
    </label>
    <?php if (count($i['palpites']) > 1): ?>
        <div class="rn-atalhos">
            <?php foreach ($i['palpites'] as $p): ?>
                <button type="button" class="botao botao-alt disc-palpite" data-n="<?= (int) $p ?>"><?= (int) $p ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="ajuda disc-previa" aria-live="polite"></p>

    <button type="submit" name="acao" value="abrir" class="botao">Abrir a caixa</button>
    <button type="submit" name="acao" value="ok" class="botao botao-alt disc-certo">Está certo, não é caixa</button>
</form>
