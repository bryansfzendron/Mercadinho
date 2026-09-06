<?php
/**
 * @var string $aba
 * @var array  $p
 * @var array  $pdvs
 * @var array  $loja
 * @var array  $vendas
 */
?>
<h1>Configurações</h1>
<?= abas_config($aba === 'sync' ? '/config' : '/config/' . $aba) ?>

<?php if ($aba === 'sync'): ?>

    <div class="cartao">
        <h2 class="sem-topo">Preços e estoque</h2>
        <?php if ($loja): ?>
            <dl class="dados">
                <?php foreach ($loja as $l): ?>
                    <div>
                        <dt><?= e($l['nome']) ?></dt>
                        <dd><?= (int) $l['itens'] ?> itens · <?= data_fmt($l['atualizado_em'], true) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php else: ?>
            <p class="ajuda">Nenhum ponto de venda sincronizado ainda.</p>
        <?php endif; ?>
        <button type="button" id="btn-sync-loja" class="botao">Atualizar preços e estoque</button>
        <p class="ajuda" id="sync-estado">
            Rebusca o planograma e o inventário de cada PDV. É o que alimenta o preço de
            venda que aparece ao bipar.
        </p>
    </div>

    <div class="cartao">
        <h2 class="sem-topo">Vendas</h2>
        <?php if ((int) $vendas['vendas'] > 0): ?>
            <dl class="dados">
                <div><dt>Gravadas</dt><dd><?= (int) $vendas['vendas'] ?> vendas</dd></div>
                <div><dt>Faturamento</dt><dd><?= moeda($vendas['total']) ?></dd></div>
                <div><dt>Período</dt><dd><?= data_fmt($vendas['primeira']) ?> a <?= data_fmt($vendas['ultima'], true) ?></dd></div>
            </dl>
        <?php else: ?>
            <p class="ajuda">Nenhuma venda importada ainda. A primeira carga puxa 12 meses.</p>
        <?php endif; ?>
        <button type="button" id="btn-sync-vendas" class="botao">
            <?= (int) $vendas['vendas'] === 0 ? 'Importar 12 meses de vendas' : 'Buscar vendas novas' ?>
        </button>
        <p class="ajuda" id="sync-vendas-estado">
            Traz só o que falta, voltando 3 dias para pegar transação reconciliada. Para
            corrigir um período mais antigo, use <a href="/vendas">Reconferir este período</a>
            na aba Vendas.
        </p>
    </div>

    <script>
    (function () {
        const CSRF = <?= json_encode(csrf_token()) ?>;

        /* Os dois botões pedem ao n8n e voltam na hora: quem grava é o callback. */
        function ligar(botaoId, estadoId, url, pronto) {
            const btn = document.getElementById(botaoId);
            const estado = document.getElementById(estadoId);
            const original = estado.innerHTML;
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                estado.textContent = 'Pedindo os dados ao TouchPay...';
                try {
                    const r = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    });
                    const d = await r.json();
                    estado.textContent = d.ok ? pronto(d) : ('Não deu: ' + (d.erro || 'erro desconhecido'));
                } catch (e) {
                    estado.textContent = 'Falha de rede: ' + e.message;
                }
                btn.disabled = false;
            });
        }

        ligar('btn-sync-loja', 'sync-estado', '/api/loja/sincronizar',
            () => 'Sincronização disparada. Leva alguns segundos; recarregue para ver.');

        ligar('btn-sync-vendas', 'sync-vendas-estado', '/api/vendas/sincronizar',
            (d) => 'Buscando vendas de ' + (d.desde || '?') + ' a ' + (d.ate || '?') +
                   '. Chegam em lotes; recarregue em alguns segundos.');
    })();
    </script>

<?php elseif ($aba === 'pdvs'): ?>

    <div class="cartao">
        <h2 class="sem-topo">Pontos de venda</h2>
        <p class="ajuda">
            Desmarcar tira o ponto de venda do app inteiro — catálogo, bipe, vendas e metas.
            Serve para o PDV que está na mesma conta do TouchPay mas não é seu: o sync
            continua trazendo, as telas é que ignoram. Ele continua listado aqui para você
            poder ligar de volta quando quiser.
        </p>
        <?php if (!$pdvs): ?>
            <p class="vazio">Nenhum ponto de venda ainda. Sincronize preços e estoque primeiro.</p>
        <?php else: ?>
            <form method="post" action="/config/pdvs">
                <?= csrf_campo() ?>
                <input type="hidden" name="pdvs_enviados" value="1">
                <?php foreach ($pdvs as $pdv): ?>
                    <label class="caixa-marcar">
                        <input type="checkbox" name="pdvs[]" value="<?= (int) $pdv['id'] ?>"
                               <?= (int) $pdv['ativo'] === 1 ? 'checked' : '' ?>>
                        <span><?= e($pdv['nome']) ?></span>
                        <span class="ajuda">
                            <?= (int) $pdv['itens'] ?> itens<?= (int) $pdv['ativo'] === 1 ? '' : ' · fora do app' ?>
                        </span>
                    </label>
                <?php endforeach; ?>
                <button type="submit" class="botao">Salvar pontos de venda</button>
            </form>
        <?php endif; ?>
    </div>

<?php elseif ($aba === 'metas'): ?>

    <div class="cartao">
        <h2 class="sem-topo">Metas do mês</h2>
        <form method="post" action="/config/metas">
            <?= csrf_campo() ?>
            <div class="filtros">
                <label>Faturamento (R$/mês)
                    <input type="text" inputmode="decimal" name="meta_faturamento"
                           value="<?= e(number_format($p['meta_faturamento'], 2, ',', '')) ?>">
                </label>
                <label>Lucro (R$/mês)
                    <input type="text" inputmode="decimal" name="meta_lucro"
                           value="<?= e(number_format($p['meta_lucro'], 2, ',', '')) ?>">
                </label>
            </div>
            <p class="ajuda">
                Zero desliga a meta e deixa só a projeção. O lucro sai do mesmo cálculo da aba
                Vendas: já com mercadoria, maquininha, condomínio, franquia e os fixos do mês.
                O progresso aparece em <a href="/metas">Loja → Metas</a>.
            </p>
            <button type="submit" class="botao">Salvar metas</button>
        </form>
    </div>

<?php else: ?>

    <div class="cartao">
        <h2 class="sem-topo">Custos e taxas</h2>
        <form method="post" action="/config/taxas">
            <?= csrf_campo() ?>
            <div class="filtros">
                <label>Condomínio (% do bruto)
                    <input type="text" inputmode="decimal" name="condominio_pct" value="<?= e(number_format($p['condominio_pct'], 2, ',', '')) ?>">
                </label>
                <label>Franquia (% do bruto)
                    <input type="text" inputmode="decimal" name="franquia_pct" value="<?= e(number_format($p['franquia_pct'], 2, ',', '')) ?>">
                </label>
            </div>
            <div class="filtros">
                <label>Taxa débito (%)
                    <input type="text" inputmode="decimal" name="taxa_debito" value="<?= e(number_format($p['taxa_debito'], 2, ',', '')) ?>">
                </label>
                <label>Taxa crédito (%)
                    <input type="text" inputmode="decimal" name="taxa_credito" value="<?= e(number_format($p['taxa_credito'], 2, ',', '')) ?>">
                </label>
            </div>
            <div class="filtros">
                <label>Taxa Pix (%)
                    <input type="text" inputmode="decimal" name="taxa_pix" value="<?= e(number_format($p['taxa_pix'], 2, ',', '')) ?>">
                </label>
                <label>Taxa voucher (%)
                    <input type="text" inputmode="decimal" name="taxa_voucher" value="<?= e(number_format($p['taxa_voucher'], 2, ',', '')) ?>">
                </label>
            </div>
            <div class="filtros">
                <label>Energia (R$/mês)
                    <input type="text" inputmode="decimal" name="fixo_energia" value="<?= e(number_format($p['fixo_energia'], 2, ',', '')) ?>">
                </label>
                <label>Sistema (R$/mês)
                    <input type="text" inputmode="decimal" name="fixo_sistema" value="<?= e(number_format($p['fixo_sistema'], 2, ',', '')) ?>">
                </label>
            </div>
            <label>CMV padrão, para produto sem nota (%)
                <input type="text" inputmode="decimal" name="cmv_padrao_pct" value="<?= e(number_format($p['cmv_padrao_pct'], 2, ',', '')) ?>">
            </label>
            <p class="ajuda">
                As taxas vêm da tabela do PagBank e mudam com o seu faturamento e com o fim
                da promoção — confira no app da maquininha. A taxa entra por forma de
                pagamento, venda a venda, e não por média.
            </p>
            <button type="submit" class="botao">Salvar custos</button>
        </form>
    </div>

<?php endif; ?>
