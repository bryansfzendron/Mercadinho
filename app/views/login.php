<?php /** @var ?string $erro @var string $email */ ?>
<div class="login-caixa">
    <img class="login-logo" src="/assets/logo/completo.png" alt="Alpha Market" width="900" height="622">
    <p class="login-sub">Suas notas, seus preços.</p>

    <?php if ($erro): ?>
        <div class="aviso aviso-erro"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="cartao">
        <?= csrf_campo() ?>
        <label>E-mail
            <input type="email" name="email" value="<?= e($email) ?>" required autocomplete="username" autofocus>
        </label>
        <label>Senha
            <input type="password" name="senha" required autocomplete="current-password">
        </label>
        <label class="caixa-marcar">
            <input type="checkbox" name="lembrar" value="1" checked>
            <span>Manter conectado</span>
        </label>
        <button type="submit" class="botao">Entrar</button>
    </form>
</div>
