<?php /** @var array $r @var array $f @var array $pdvs @var array $formas @var array $categorias */ ?>
<h1>Loja</h1>
<?= abas_loja('/vendas') ?>
<?= abas(['/vendas' => 'Resumo', '/vendas/transacoes' => 'Transações'], '/vendas') ?>

<div class="chips">
    <?php foreach (vendas_periodos() as $chave => [$rotulo, $de, $ate]): ?>
        <a class="chip <?= $f['de'] === $de && $f['ate'] === $ate ? 'ativo' : '' ?>"
           href="/vendas?<?= e(http_build_query(['de' => $de, 'ate' => $ate] + $f)) ?>"><?= e($rotulo) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="/vendas">
    <div class="filtros">
        <label>De
            <input type="date" name="de" value="<?= e($f['de']) ?>">
        </label>
        <label>Até
            <input type="date" name="ate" value="<?= e($f['ate']) ?>">
        </label>
        <label>Ponto de venda
            <select name="pdv_id">
                <option value="">todos</option>
                <?php foreach ($pdvs as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) $f['pdv_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= e($p['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Pagamento
            <select name="forma">
                <option value="">todas</option>
                <?php foreach ($formas as $nome => $rotulo): ?>
                    <option value="<?= e($nome) ?>" <?= $f['forma'] === $nome ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Categoria
            <select name="categoria">
                <option value="">todas</option>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $f['categoria'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="col-cheia">Agrupar por
            <select name="agrupar">
                <?php foreach (vendas_agrupamentos() as $chave => [$rot, $_]): ?>
                    <option value="<?= e($chave) ?>" <?= $r['agrupar'] === $chave ? 'selected' : '' ?>><?= e($rot) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="linha-form">
        <input type="text" name="q" value="<?= e($f['busca']) ?>" placeholder="produto, código ou categoria"
               autocomplete="off" autocorrect="off" spellcheck="false">
        <button type="submit" class="botao">Filtrar</button>
    </div>
</form>

<div class="acoes">
    <button type="button" id="btn-reconferir" class="botao botao-alt">
        Reconferir este período no TouchPay
    </button>
    <p class="ajuda" id="reconferir-estado">
        Rebusca a janela filtrada e regrava. Serve quando o número não bate com o painel do
        TouchPay: o sync automático só volta 3 dias, então buraco no meio do mês só sai daqui.
    </p>
</div>

<script>
(function () {
    const btn = document.getElementById('btn-reconferir');
    const estado = document.getElementById('reconferir-estado');
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const JANELA = <?= json_encode(['de' => $f['de'], 'ate' => $f['ate']]) ?>;

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        estado.textContent = 'Rebuscando ' + JANELA.de + ' a ' + JANELA.ate + '...';
        try {
            const r = await fetch('/api/vendas/sincronizar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify(JANELA),
            });
            const d = await r.json();
            estado.textContent = d.ok
                ? 'Pedido. Recarregue em alguns segundos; regravar não duplica.'
                : ('Não deu: ' + (d.erro || 'erro desconhecido'));
        } catch (e) {
            estado.textContent = 'Falha de rede: ' + e.message;
        }
        btn.disabled = false;
    });
})();
</script>

<?php $res = $r['resultado']; ?>
<div class="cartao">
    <h2 class="sem-topo">Resultado do período</h2>
    <p class="meta">
        <?= (int) $res['vendas'] ?> venda(s) em <?= (int) $res['dias'] ?> dia(s) ·
        custos fixos rateados por dia
    </p>

    <dl class="dados">
        <div><dt>Faturamento</dt><dd class="valor"><?= moeda($res['receita']) ?></dd></div>
        <div><dt>Mercadoria (CMV)</dt><dd class="valor negativo">− <?= moeda($res['cmv']) ?></dd></div>
        <div><dt>Maquininha</dt><dd class="valor negativo">− <?= moeda($res['taxa']) ?></dd></div>
        <div><dt>Condomínio</dt><dd class="valor negativo">− <?= moeda($res['condominio']) ?></dd></div>
        <div><dt>Franquia</dt><dd class="valor negativo">− <?= moeda($res['franquia']) ?></dd></div>
        <div><dt>Imposto (Simples Nacional)</dt><dd class="valor negativo">− <?= moeda($res['imposto']) ?></dd></div>
        <div><dt>Energia, sistema e internet</dt><dd class="valor negativo">− <?= moeda($res['fixos']) ?></dd></div>
    </dl>

    <div class="margem <?= $res['lucro'] >= 0 ? 'margem-boa' : 'margem-ruim' ?>">
        <strong class="margem-fator"><?= number_format($res['margem'], 1, ',', '.') ?><span>%</span></strong>
        <div class="margem-conta">
            <span class="margem-lucro"><?= $res['lucro'] >= 0 ? '+' : '−' ?><?= moeda(abs($res['lucro'])) ?></span>
            <span class="margem-linha">sobra depois de tudo, no período filtrado</span>
        </div>
    </div>

    <?php if ($r['cobertura'] < 99.5): ?>
        <p class="ajuda">
            <?= number_format($r['cobertura'], 0, ',', '.') ?>% do faturamento tem custo vindo de nota fiscal.
            O resto usa o padrão de <?= number_format($r['parametros']['cmv_padrao_pct'], 0, ',', '.') ?>%
            — escaneie as notas desses produtos e o número para de ser palpite.
        </p>
    <?php endif; ?>
</div>

<?php if ($r['por_forma']): ?>
    <h2>Como pagaram</h2>
    <ul class="lista">
        <?php foreach ($r['por_forma'] as $pf): ?>
            <li><div class="linha-cartao">
                <div class="linha-topo">
                    <span class="forte"><?= e($formas[$pf['forma']] ?? $pf['forma']) ?></span>
                    <span class="valor"><?= moeda($pf['total']) ?></span>
                </div>
                <div class="linha-baixo">
                    <span><?= (int) $pf['n'] ?> venda(s)</span>
                    <span>taxa <?= number_format(custo_taxa_da_forma($pf['forma'], $r['parametros']), 2, ',', '.') ?>%</span>
                </div>
            </div></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2><?= e($r['rotulo']) ?></h2>
<p class="ajuda">
    Contribuição = o que sobra depois da mercadoria e dos
    <?= number_format($r['pct_variavel'], 2, ',', '.') ?>% que acompanham o faturamento
    (maquininha, condomínio, franquia e imposto). Custo fixo fica fora — ele não se divide por produto.
</p>

<?php if (!$r['linhas']): ?>
    <p class="vazio">Nenhuma venda no período. Importe as vendas na tela inicial.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($r['linhas'] as $l): ?>
            <li><div class="linha-cartao">
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
            </div></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p class="ajuda centro">
    <a href="/config/taxas">Ajustar custos e taxas</a>
</p>
