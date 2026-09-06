<?php /** @var array $r @var array $f @var array $pdvs @var array $formas */ ?>
<a class="voltar" href="/loja">‹ Loja</a>
<h1>Vendas</h1>

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

<?php $res = $r['resultado']; ?>
<div class="cartao">
    <h2 class="sem-topo">Resultado do período</h2>
    <p class="meta"><?= (int) $res['dias'] ?> dia(s) · custos fixos rateados por dia</p>

    <dl class="dados">
        <div><dt>Faturamento</dt><dd class="valor"><?= moeda($res['receita']) ?></dd></div>
        <div><dt>Mercadoria (CMV)</dt><dd class="valor negativo">− <?= moeda($res['cmv']) ?></dd></div>
        <div><dt>Maquininha</dt><dd class="valor negativo">− <?= moeda($res['taxa']) ?></dd></div>
        <div><dt>Condomínio</dt><dd class="valor negativo">− <?= moeda($res['condominio']) ?></dd></div>
        <div><dt>Franquia</dt><dd class="valor negativo">− <?= moeda($res['franquia']) ?></dd></div>
        <div><dt>Energia + sistema</dt><dd class="valor negativo">− <?= moeda($res['fixos']) ?></dd></div>
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
    (maquininha, condomínio e franquia). Custo fixo fica fora — ele não se divide por produto.
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

<details class="colar">
    <summary>Custos e taxas</summary>
    <form method="post" action="/vendas/custos">
        <?= csrf_campo() ?>
        <div class="filtros">
            <label>Condomínio (% do bruto)
                <input type="text" inputmode="decimal" name="condominio_pct" value="<?= e(number_format($r['parametros']['condominio_pct'], 2, ',', '')) ?>">
            </label>
            <label>Franquia (% do bruto)
                <input type="text" inputmode="decimal" name="franquia_pct" value="<?= e(number_format($r['parametros']['franquia_pct'], 2, ',', '')) ?>">
            </label>
        </div>
        <div class="filtros">
            <label>Taxa débito (%)
                <input type="text" inputmode="decimal" name="taxa_debito" value="<?= e(number_format($r['parametros']['taxa_debito'], 2, ',', '')) ?>">
            </label>
            <label>Taxa crédito (%)
                <input type="text" inputmode="decimal" name="taxa_credito" value="<?= e(number_format($r['parametros']['taxa_credito'], 2, ',', '')) ?>">
            </label>
        </div>
        <div class="filtros">
            <label>Taxa Pix (%)
                <input type="text" inputmode="decimal" name="taxa_pix" value="<?= e(number_format($r['parametros']['taxa_pix'], 2, ',', '')) ?>">
            </label>
            <label>Taxa voucher (%)
                <input type="text" inputmode="decimal" name="taxa_voucher" value="<?= e(number_format($r['parametros']['taxa_voucher'], 2, ',', '')) ?>">
            </label>
        </div>
        <div class="filtros">
            <label>Energia (R$/mês)
                <input type="text" inputmode="decimal" name="fixo_energia" value="<?= e(number_format($r['parametros']['fixo_energia'], 2, ',', '')) ?>">
            </label>
            <label>Sistema (R$/mês)
                <input type="text" inputmode="decimal" name="fixo_sistema" value="<?= e(number_format($r['parametros']['fixo_sistema'], 2, ',', '')) ?>">
            </label>
        </div>
        <label>CMV padrão, para produto sem nota (%)
            <input type="text" inputmode="decimal" name="cmv_padrao_pct" value="<?= e(number_format($r['parametros']['cmv_padrao_pct'], 2, ',', '')) ?>">
        </label>
        <p class="ajuda">
            As taxas vêm da tabela do PagBank e mudam com o seu faturamento e com o fim da
            promoção. Confira no app da maquininha e corrija aqui.
        </p>
        <button type="submit" class="botao">Salvar</button>
    </form>
</details>
