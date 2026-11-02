<?php
/**
 * Configuracoes > Aparencia: o tema deste aparelho.
 *
 * @var string $atual chave de temas()
 */
?>
<h1>Configurações</h1>
<?= abas_config('/config/aparencia') ?>

<div class="cartao">
    <h2 class="sem-topo">Tema</h2>
    <p class="ajuda">Vale só para este aparelho. Em outro celular ou no computador você escolhe de novo.</p>
    <form method="post" action="/config/aparencia" class="temas">
        <?= csrf_campo() ?>
        <?php foreach (temas() as $chave => [$rotulo, $explica]): ?>
            <button type="submit" name="tema" value="<?= e($chave) ?>"
                    class="tema-opcao<?= $atual === $chave ? ' ativo' : '' ?>"
                    aria-pressed="<?= $atual === $chave ? 'true' : 'false' ?>">
                <span class="tema-amostra tema-amostra-<?= e($chave) ?>" aria-hidden="true"><i></i><i></i></span>
                <span class="tema-texto">
                    <strong><?= e($rotulo) ?></strong>
                    <span class="ajuda"><?= e($explica) ?></span>
                </span>
                <span class="tema-marca" aria-hidden="true"><?= $atual === $chave ? '✓' : '' ?></span>
            </button>
        <?php endforeach; ?>
    </form>
</div>
