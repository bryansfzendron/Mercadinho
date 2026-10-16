<?php
/**
 * Repor em lote: envio da planilha.
 *
 * @var array   $pdvs
 * @var array   $erros   [{linha, msg}]
 * @var string  $taxa
 * @var int     $pdv_sel
 * @var array   $lotes   lotes recentes do usuario
 * @var ?string $arquivo
 */
?>
<a class="voltar" href="/planograma">‹ Repor</a>
<h1>Repor em lote</h1>
<?= abas_loja('/planograma') ?>

<p class="ajuda">
    Reponha vários produtos de uma vez: baixe o modelo, preencha uma linha por produto
    (<strong>entrada</strong> soma ao estoque que já está no TouchPay) e envie. Você confere
    o que vai mudar antes de gravar. Produto que não existe no TouchPay não é criado:
    sai numa lista para você pedir o cadastro.
</p>

<?php if ($erros): ?>
    <div class="aviso aviso-erro">
        <strong>Nada foi gravado<?= !empty($arquivo) ? ' (' . e($arquivo) . ')' : '' ?>.</strong>
        Corrija a planilha e envie de novo:
        <ul>
            <?php foreach (array_slice($erros, 0, 40) as $er): ?>
                <li>Linha <?= (int) $er['linha'] ?>: <?= e($er['msg']) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($erros) > 40): ?><p>… e mais <?= count($erros) - 40 ?> erro(s).</p><?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$pdvs): ?>
    <p class="vazio">Nenhum ponto de venda sincronizado ainda. Puxe o espelho da loja em <a href="/config">Configurações</a>.</p>
<?php else: ?>
    <div class="cartao">
        <h2>1. Baixe o modelo</h2>
        <div class="acoes-topo">
            <a class="botao botao-grande" href="/planograma/importar/modelo.xlsx">⇩ Modelo Excel (.xlsx)</a>
            <a class="botao botao-alt" href="/planograma/importar/modelo.csv">⇩ Modelo CSV (;)</a>
        </div>
        <p class="ajuda">A aba "Instruções" explica cada coluna. Campo vazio = o app não mexe naquele dado.</p>
    </div>

    <form class="cartao" method="post" action="/planograma/importar" enctype="multipart/form-data">
        <?= csrf_campo() ?>
        <h2>2. Envie preenchida</h2>
        <label>Ponto de venda (quando a linha não trouxer o seu)
            <select name="pdv">
                <?php foreach ($pdvs as $p): $ok = (int) ($p['planograma_id'] ?? 0) > 0; ?>
                    <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $pdv_sel ? ' selected' : '' ?><?= $ok ? '' : ' disabled' ?>>
                        <?= e($p['nome']) ?><?= $ok ? '' : ' — sem planograma' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Taxa padrão (preço = custo × taxa, quando só há custo)
            <input type="text" name="taxa" inputmode="decimal" value="<?= e($taxa) ?>">
        </label>
        <label>Arquivo .xlsx ou .csv (até 2 MB, 500 produtos)
            <input type="file" name="arquivo" accept=".xlsx,.csv,text/csv" required>
        </label>
        <button class="botao botao-grande" type="submit">Ler planilha</button>
    </form>
<?php endif; ?>

<?php if ($lotes): ?>
    <h2>Lotes recentes</h2>
    <ul class="lista">
        <?php foreach ($lotes as $l): ?>
            <li><a href="/planograma/lote/<?= (int) $l['id'] ?>">
                <div class="linha-topo">
                    <span class="forte"><?= e($l['arquivo'] ?: ('Lote ' . $l['id'])) ?></span>
                    <span class="valor"><?= (int) $l['gravadas'] ?>/<?= (int) $l['total'] ?> gravadas</span>
                </div>
                <div class="linha-baixo">
                    <span><?= e(data_fmt($l['criado_em'], true)) ?></span>
                    <?php if ((int) $l['sem_cadastro'] > 0): ?>
                        <span class="selo selo-pendente"><?= (int) $l['sem_cadastro'] ?> sem cadastro</span>
                    <?php endif; ?>
                </div>
            </a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
