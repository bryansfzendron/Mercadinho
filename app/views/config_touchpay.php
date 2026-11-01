<?php
/**
 * Configuracoes > TouchPay: a conta TouchPay deste usuario.
 *
 * @var bool    $tabelas_ok
 * @var ?array  $conta           vinculo no banco (sem e-mail nem senha)
 * @var bool    $neste_aparelho  o cookie deste aparelho abre e e da mesma conta
 * @var ?string $email           mascarado, so quando $neste_aparelho
 * @var int     $outros          outros usuarios do app na mesma conta
 * @var ?string $erro
 * @var string  $email_digitado
 */
?>
<h1>Configurações</h1>
<?= abas_config('/config/touchpay') ?>

<?php if (!$tabelas_ok): ?>
    <div class="aviso aviso-erro">
        Falta criar as tabelas da conta TouchPay. Rode o <strong>setup.php</strong>
        (“criar o que falta”) e volte aqui.
    </div>
<?php endif; ?>

<?php if ($erro): ?>
    <div class="aviso aviso-erro"><?= e($erro) ?></div>
<?php endif; ?>

<?php if ($neste_aparelho): ?>
    <div class="cartao">
        <h2 class="sem-topo">Conectado</h2>
        <dl class="dados">
            <div><dt>Conta</dt><dd><?= e($email) ?></dd></div>
            <div><dt>Desde</dt><dd><?= data_fmt($conta['conectado_em'], true) ?></dd></div>
            <?php if ($outros > 0): ?>
                <div><dt>Também usam</dt><dd><?= $outros ?> outro<?= $outros > 1 ? 's' : '' ?> usuário<?= $outros > 1 ? 's' : '' ?> do app</dd></div>
            <?php endif; ?>
        </dl>
        <div class="acoes">
            <form method="post">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="testar">
                <button type="submit" class="botao botao-alt">Testar conexão</button>
            </form>
            <form method="post" onsubmit="return confirm('Desconectar a conta TouchPay e apagar a senha deste aparelho?')">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="desconectar">
                <button type="submit" class="botao botao-perigo">Desconectar</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="cartao">
        <h2 class="sem-topo"><?= $conta ? 'Digite a senha neste aparelho' : 'Conectar sua conta TouchPay' ?></h2>
        <?php if ($conta): ?>
            <p class="ajuda">
                Sua conta TouchPay já está ligada ao seu usuário, mas a senha só fica no
                aparelho onde foi digitada. Para usar daqui, entre de novo.
            </p>
        <?php else: ?>
            <p class="ajuda">
                Use o mesmo e-mail e senha com que você entra no painel do TouchPay.
                O app testa o login agora; se o TouchPay recusar, nada é guardado.
            </p>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="conectar">
            <label>E-mail do TouchPay
                <input type="email" name="tp_email" required autocomplete="off" autocapitalize="off"
                       spellcheck="false" value="<?= e($email_digitado) ?>">
            </label>
            <label>Senha do TouchPay
                <input type="password" name="tp_senha" required autocomplete="new-password">
            </label>
            <button type="submit" class="botao"<?= $tabelas_ok ? '' : ' disabled' ?>>Conectar</button>
        </form>
    </div>
<?php endif; ?>

<div class="cartao">
    <h2 class="sem-topo">Onde fica a sua senha</h2>
    <ul class="ajuda tp-promessas">
        <li><strong>Só neste aparelho.</strong> O e-mail e a senha do TouchPay ficam num cookie
            criptografado no seu celular. O banco de dados do app não guarda nenhum dos dois.</li>
        <li><strong>Ninguém lê o cookie sozinho.</strong> Ele só abre com uma chave que fica no
            servidor, e só para o seu login do app.</li>
        <li><strong>O servidor usa e esquece.</strong> A senha passa pelo servidor só no momento
            de entrar no TouchPay, porque é ele quem conversa com o TouchPay.</li>
        <li><strong>Outro aparelho pede a senha de novo.</strong> Desconectar apaga a senha deste
            aparelho na hora.</li>
    </ul>
</div>

<p class="ajuda">
    Por enquanto o app ainda lê a loja pela conta configurada no servidor. A conta que
    você conecta aqui passa a ser usada nas próximas atualizações.
</p>
