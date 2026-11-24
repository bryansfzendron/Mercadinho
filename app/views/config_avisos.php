<?php
/**
 * Configuracoes > Avisos: notificacoes de validade e de margem.
 *
 * @var bool  $ok         as tabelas dos avisos existem
 * @var array $cfg        ligado, dias_validade, fator_minimo
 * @var int   $inscritos  aparelhos inscritos nesta conta
 * @var string $publica   chave publica VAPID
 */
?>
<?= titulo_icone('Configurações', 'engrenagem') ?>
<?= abas_config('/config/avisos') ?>

<?php if (!$ok): ?>
    <div class="aviso aviso-erro">
        Falta criar as tabelas dos avisos. Rode o <strong>setup.php</strong>
        (“criar o que falta”) e volte aqui.
    </div>
<?php else: ?>

<div class="cartao">
    <?= cartao_cabeca('alerta', 'Neste aparelho', $inscritos . ' aparelho(s) recebendo avisos nesta conta', '', true) ?>
    <p class="ajuda" id="av-estado">Verificando...</p>
    <div class="acoes">
        <button type="button" class="botao" id="av-ativar" data-chave="<?= e($publica) ?>" hidden>Ativar notificações neste aparelho</button>
        <button type="button" class="botao botao-alt" id="av-desativar" hidden>Desativar neste aparelho</button>
    </div>
    <p class="ajuda">
        No iPhone só funciona com o app instalado na tela inicial (Compartilhar → Adicionar à Tela de Início)
        e iOS 16.4 ou mais novo.
    </p>
    <form method="post" class="acoes">
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="testar">
        <button type="submit" class="botao botao-alt">Enviar notificação de teste</button>
    </form>
</div>

<form method="post" class="cartao">
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="salvar">
    <?= cartao_cabeca('percentual', 'O que avisar', 'Um aviso de cada tipo por dia, a partir das 8h', '', true) ?>
    <label class="rn-marca">
        <input type="checkbox" name="ligado" value="1" <?= $cfg['ligado'] ? 'checked' : '' ?>>
        <span class="forte">Avisos ligados</span>
    </label>
    <label>Validade: avisar produtos que vencem em até (dias)
        <input type="text" name="dias_validade" inputmode="numeric" value="<?= (int) $cfg['dias_validade'] ?>">
    </label>
    <label>Margem: avisar produtos com fator abaixo de (x)
        <input type="text" name="fator_minimo" inputmode="decimal" value="<?= e(number_format($cfg['fator_minimo'], 2, ',', '')) ?>">
    </label>
    <p class="ajuda">O fator é preço de venda ÷ custo, o mesmo da página Margens. Produtos sem custo não entram.</p>
    <button type="submit" class="botao">Salvar</button>
</form>

<form method="post" class="cartao">
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="rodar">
    <?= cartao_cabeca('sync', 'Conferir agora', 'Roda a checagem na hora, sem esperar o cron', '', true) ?>
    <button type="submit" class="botao botao-alt">Checar e avisar agora</button>
</form>

<script src="/assets/avisos.js?v=1" defer></script>
<?php endif; ?>
