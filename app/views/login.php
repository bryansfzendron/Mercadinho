<?php /** @var ?string $erro @var string $email */ ?>
<div class="login-caixa">
    <h1 class="login-titulo">Mercadinho</h1>
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
        <button type="submit" class="botao">Entrar</button>
    </form>
</div>
