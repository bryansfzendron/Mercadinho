<?php /** @var array $r @var array $f @var array $pdvs @var array $formas @var array $categorias */ ?>
<?= titulo_loja() ?>
<?= abas_loja('/vendas') ?>
<?= abas(['/vendas' => 'Resumo', '/vendas/transacoes' => 'Transações'], '/vendas') ?>

<?php
$acao = '/vendas';
$agrupar_atual = $r['agrupar'];
$busca_dica = 'Produto, código ou categoria';
include __DIR__ . '/_vendas_filtros.php';
?>

<script>
// A unica tela que pede uma janela especifica. O gesto de arrastar le isto e
// manda junto — foi assim que "reconferir este periodo" deixou de ser um
// botao proprio e virou o mesmo gesto que atualiza todo o resto do app.
window.SYNC_JANELA = <?= json_encode(['de' => $f['de'], 'ate' => $f['ate']]) ?>;
</script>

<?php
$res = $r['resultado'];
// Os tres custos que mais pesam ficam a vista; o resto recolhe em "Ver todos".
$custos_extra = [
    'Condomínio'                  => $res['condominio'],
    'Franquia'                    => $res['franquia'],
    'Imposto (Simples Nacional)'  => $res['imposto'],
    'Energia, sistema e internet' => $res['fixos'],
];
?>
<div class="cartao resultado">
    <div class="cartao-cabeca">
        <span class="cartao-icone" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 19v-5M12 19V9M18 19V5"/></svg>
        </span>
        <div>
            <h2 class="sem-topo">Resultado do período</h2>
            <p class="meta sem-margem">
                <?= number_format((int) $res['vendas'], 0, ',', '.') ?> venda(s) em <?= (int) $res['dias'] ?> dia(s) ·
                custos fixos rateados por dia
            </p>
        </div>
    </div>

    <div class="margem margem-grande <?= $res['lucro'] >= 0 ? 'margem-boa' : 'margem-ruim' ?>">
        <strong class="margem-fator"><?= number_format($res['margem'], 1, ',', '.') ?><span>%</span></strong>
        <div class="margem-conta">
            <span class="margem-lucro"><?= $res['lucro'] >= 0 ? '+' : '−' ?><?= moeda(abs($res['lucro'])) ?></span>
            <span class="margem-linha">sobra depois de tudo, no período filtrado</span>
        </div>
    </div>

    <dl class="dados dados-grandes">
        <div><dt>Faturamento</dt><dd class="valor"><?= moeda($res['receita']) ?></dd></div>
        <div><dt>Mercadoria (CMV)</dt><dd class="valor">− <?= moeda($res['cmv']) ?></dd></div>
        <div><dt>Maquininha</dt><dd class="valor">− <?= moeda($res['taxa']) ?></dd></div>
    </dl>
    <details class="mais-custos">
        <summary><span class="mc-botao">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            <span class="fechado">Ver todos os custos (<?= count($custos_extra) ?> mais)</span>
            <span class="aberto">Esconder custos</span>
        </span></summary>
        <dl class="dados dados-grandes">
            <?php foreach ($custos_extra as $rot => $v): ?>
                <div><dt><?= e($rot) ?></dt><dd class="valor">− <?= moeda($v) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </details>

    <?php if ($r['cobertura'] < 99.5): ?>
        <p class="ajuda">
            <?= number_format($r['cobertura'], 0, ',', '.') ?>% do faturamento tem custo vindo de nota fiscal.
            O resto usa o padrão de <?= number_format($r['parametros']['cmv_padrao_pct'], 0, ',', '.') ?>%
            — escaneie as notas desses produtos e o número para de ser palpite.
        </p>
    <?php endif; ?>
</div>

<?php if ($r['por_forma']): $mostrar = 2; $extras = max(0, count($r['por_forma']) - $mostrar); ?>
    <div class="cartao como-pagaram<?= $extras ? ' recolhido' : '' ?>" id="como-pagaram">
        <div class="cartao-cabeca">
            <span class="cartao-icone cartao-icone-plano" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h3"/></svg>
            </span>
            <h2 class="sem-topo">Como pagaram</h2>
            <?php if ($extras): ?>
                <button type="button" class="ver-mais" aria-controls="como-pagaram" aria-expanded="false">
                    <span class="fechado">Ver mais</span><span class="aberto">Ver menos</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                </button>
            <?php endif; ?>
        </div>
        <ul class="lista">
            <?php foreach ($r['por_forma'] as $i => $pf): ?>
                <li class="<?= $i >= $mostrar ? 'extra' : '' ?>"><div class="linha-cartao">
                    <div class="linha-topo">
                        <span class="forte"><?= e($formas[$pf['forma']] ?? $pf['forma']) ?></span>
                        <span class="valor"><?= moeda($pf['total']) ?></span>
                    </div>
                    <div class="linha-baixo">
                        <span><?= (int) $pf['n'] ?> venda(s)</span>
                        <span><?= e(vendas_taxa_texto($pf)) ?></span>
                    </div>
                </div></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if ($extras): ?>
        <script>
        (function () {
            const caixa = document.getElementById('como-pagaram');
            const botao = caixa.querySelector('.ver-mais');
            botao.addEventListener('click', () => {
                const aberto = !caixa.classList.toggle('recolhido');
                botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            });
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>

<p class="ajuda">
    <strong>Arraste a tela para baixo</strong> para rebuscar este período no TouchPay e
    regravar. Serve quando o número não bate com o painel deles: o sync automático só volta
    3 dias, então buraco no meio do mês só sai daqui. Regravar não duplica.
</p>

<h2><?= e($r['rotulo']) ?></h2>
<p class="ajuda">
    Contribuição = o que sobra depois da mercadoria e dos
    <?= number_format($r['pct_variavel'], 2, ',', '.') ?>% que acompanham o faturamento
    (maquininha, condomínio, franquia e imposto). Custo fixo fica fora — ele não se divide por produto.
    <?php if ($r['agrupar'] === 'produto'): ?>
        Toque num produto para ver em quais dias e pontos de venda ele saiu.
    <?php endif; ?>
</p>

<?php
// Por produto, cada linha abre com os dias e os PDVs dela, no mesmo periodo,
// PDV e pagamento do filtro — o miolo vem de /vendas/produto ao abrir.
$abre = $r['agrupar'] === 'produto';
$filtro_detalhe = array_filter([
    'de' => $f['de'], 'ate' => $f['ate'], 'pdv_id' => (int) $f['pdv_id'] ?: null,
    'forma' => $f['forma'] !== '' ? $f['forma'] : null, 'dias' => 1,
], static fn ($v): bool => $v !== null);
?>
<?php if (!$r['linhas']): ?>
    <p class="vazio">Nenhuma venda no período. Importe as vendas na tela inicial.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($r['linhas'] as $l): ?>
            <li><?php if ($abre): ?><details class="compra"
                    data-detalhe="/vendas/produto?<?= e(http_build_query(['grupo' => (string) $l['grupo']] + $filtro_detalhe)) ?>">
                <summary><?php else: ?><div class="linha-cartao"><?php endif; ?>
                <div class="linha-topo">
                    <span class="forte"><?= e($l['descricao'] !== '' ? $l['descricao'] : $l['grupo']) ?></span>
                    <span class="valor"><?= moeda($l['receita']) ?></span>
                </div>
                <div class="linha-baixo">
                    <span>
                        <?= qtd_fmt($l['quantidade']) ?> un ·
                        custo <?= moeda($l['custo']) ?>
                        <?php if (!$l['com_nota']): ?><span class="estimado">estimado</span><?php endif; ?>
                    </span>
                    <span class="<?= $l['contribuicao'] >= 0 ? 'lucro-bom' : 'lucro-ruim' ?>">
                        <?= $l['contribuicao'] >= 0 ? '+' : '−' ?><?= moeda(abs($l['contribuicao'])) ?>
                        <?php if ($l['fator'] !== null): ?>
                            · <?= number_format($l['fator'], 2, ',', '.') ?>x
                        <?php endif; ?>
                    </span>
                </div>
            <?php if ($abre): ?>
                </summary>
                <div data-detalhe-corpo><p class="ajuda">Carregando…</p></div>
            </details><?php else: ?></div><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p class="ajuda centro">
    <a href="/config/taxas">Ajustar custos e taxas</a>
</p>
