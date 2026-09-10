<?php
/**
 * @var string $aba
 * @var array  $p
 * @var array  $pdvs
 * @var array  $loja
 * @var array  $vendas
 * @var array  $sync
 * @var array  $cron
 * @var ?array $imposto
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
        <div class="progresso" id="prog-loja" hidden>
            <div class="progresso-barra"><span></span></div>
            <p class="ajuda progresso-texto"></p>
        </div>
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
        <div class="progresso" id="prog-vendas" hidden>
            <div class="progresso-barra"><span></span></div>
            <p class="ajuda progresso-texto"></p>
        </div>
        <p class="ajuda" id="sync-vendas-estado">
            Traz só o que falta, voltando 3 dias para pegar transação reconciliada. Para
            corrigir um período mais antigo, use <a href="/vendas">Reconferir este período</a>
            na aba Vendas.
        </p>
    </div>

    <div class="cartao">
        <h2 class="sem-topo">Automático</h2>
        <?php if ($cron['nunca']): ?>
            <p class="aviso aviso-info">
                O cron nunca passou por aqui. Enquanto isso, preços, estoque e vendas só
                atualizam quando você clica nos botões acima.
            </p>
        <?php else: ?>
            <dl class="dados">
                <div>
                    <dt>Última passagem</dt>
                    <dd><?= $cron['minutos'] < 60
                            ? 'há ' . (int) $cron['minutos'] . ' min'
                            : data_fmt($cron['quando'], true) ?></dd>
                </div>
                <?php if ($cron['mensagem'] !== null && $cron['mensagem'] !== ''): ?>
                    <div><dt>Fez</dt><dd><?= e($cron['mensagem']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ($cron['atrasado']): ?>
                <p class="aviso aviso-info">
                    Devia passar de 5 em 5 minutos. Confira a tarefa no painel da hospedagem —
                    o caminho do PHP costuma ser o culpado.
                </p>
            <?php endif; ?>
        <?php endif; ?>
        <p class="ajuda">
            A tarefa agendada chama <code>cron.php</code>, que dispara vendas a cada 5 minutos
            e preços e estoque a cada 30 — e não dispara nada enquanto a carga anterior ainda
            está correndo. Para ver o que ele responde:
        </p>
        <p class="ajuda"><code>php ~/domains/bryanzendron.com.br/public_html/mercadinho/cron.php</code></p>
    </div>

    <script>
    (function () {
        const CSRF = <?= json_encode(csrf_token()) ?>;
        const INICIAL = <?= json_encode($sync) ?>;

        /*
         * Os dois botões pedem ao n8n e voltam na hora: quem grava é o
         * callback. A barra vem de perguntar o placar de tempos em tempos —
         * o fluxo diz "lote 7 de 25", então o número é real, não animação.
         */
        const fontes = {
            loja:   { botao: 'btn-sync-loja',   prog: 'prog-loja',   url: '/api/loja/sincronizar',   nome: 'pontos de venda' },
            vendas: { botao: 'btn-sync-vendas', prog: 'prog-vendas', url: '/api/vendas/sincronizar', nome: 'lotes' },
        };
        let relogio = null;

        function desenhar(fonte, e) {
            const cfg = fontes[fonte];
            const caixa = document.getElementById(cfg.prog);
            if (!caixa || !e) return;

            if (e.status === 'parado') { caixa.hidden = true; return; }
            caixa.hidden = false;

            const barra = caixa.querySelector('.progresso-barra');
            const traco = barra.querySelector('span');
            const texto = caixa.querySelector('.progresso-texto');

            // Sem saber o total, barra listrada em vez de percentual chutado.
            barra.classList.toggle('indeterminada', e.pct === null && e.status === 'rodando');
            traco.style.width = (e.pct === null ? 100 : e.pct) + '%';
            caixa.classList.toggle('erro', e.status === 'erro' || e.status === 'perdido');
            caixa.classList.toggle('pronto', e.status === 'ok');

            if (e.status === 'erro') {
                texto.textContent = 'Falhou: ' + (e.mensagem || 'sem detalhe') + '.';
            } else if (e.status === 'perdido') {
                texto.textContent = 'Sem resposta há mais de 10 minutos. Tente de novo — regravar não duplica.';
            } else if (e.status === 'ok') {
                texto.textContent = 'Pronto: ' + e.itens + ' registro(s) em ' + e.lotes + ' ' + cfg.nome + '.';
            } else if (e.lotes > 0) {
                texto.textContent = e.lote + ' de ' + e.lotes + ' ' + cfg.nome + ' · ' + e.itens + ' registro(s)';
            } else {
                texto.textContent = 'Conversando com o TouchPay...';
            }

            document.getElementById(cfg.botao).disabled = (e.status === 'rodando');
        }

        async function olhar() {
            try {
                const r = await fetch('/api/sync/estado');
                const d = await r.json();
                let algumRodando = false;
                for (const fonte of Object.keys(fontes)) {
                    desenhar(fonte, d[fonte]);
                    algumRodando = algumRodando || (d[fonte] && d[fonte].rodando);
                }
                // Parar o relógio quando ninguém está importando: sem isso a
                // tela fica pedindo para sempre e gastando bateria.
                if (!algumRodando && relogio) { clearInterval(relogio); relogio = null; }
            } catch (e) { /* rede caiu; a proxima passada tenta de novo */ }
        }

        function acompanhar() {
            if (relogio) return;
            relogio = setInterval(olhar, 1500);
            olhar();
        }

        for (const [fonte, cfg] of Object.entries(fontes)) {
            desenhar(fonte, INICIAL[fonte]);
            document.getElementById(cfg.botao).addEventListener('click', async () => {
                const caixa = document.getElementById(cfg.prog);
                const barra = caixa.querySelector('.progresso-barra');
                const texto = caixa.querySelector('.progresso-texto');
                const botao = document.getElementById(cfg.botao);

                caixa.hidden = false;
                caixa.classList.remove('erro', 'pronto');
                barra.classList.add('indeterminada');
                barra.querySelector('span').style.width = '100%';
                texto.textContent = 'Pedindo os dados ao TouchPay...';
                botao.disabled = true;

                try {
                    const r = await fetch(cfg.url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    });
                    const d = await r.json();
                    if (!d.ok) {
                        caixa.classList.add('erro');
                        texto.textContent = 'Não deu: ' + (d.erro || 'erro desconhecido');
                        botao.disabled = false;
                        return;
                    }
                } catch (e) {
                    caixa.classList.add('erro');
                    texto.textContent = 'Falha de rede: ' + e.message;
                    botao.disabled = false;
                    return;
                }
                acompanhar();
            });
        }

        // Recarregou no meio de uma importacao: continua acompanhando.
        if (INICIAL.loja.rodando || INICIAL.vendas.rodando) {
            acompanhar();
        }
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

    <?php
    // Cada container tem a sua energia, a sua internet e as vezes o seu
    // condominio. O padrao vale para todos; o que for diferente num PDV fica
    // gravado so nele.
    $grupos = [
        ['condominio_pct' => 'Condomínio (% do bruto)', 'franquia_pct' => 'Franquia (% do bruto)'],
        ['taxa_debito'    => 'Taxa débito (%)',         'taxa_credito' => 'Taxa crédito (%)'],
        ['taxa_pix'       => 'Taxa Pix (%)',            'taxa_voucher' => 'Taxa voucher (%)'],
        ['fixo_energia'   => 'Energia (R$/mês)',        'fixo_sistema' => 'Sistema (R$/mês)'],
        ['fixo_internet'  => 'Internet (R$/mês)'],
    ];
    $por_pdv = $pdv_taxas > 0;
    ?>

    <?php if ($ativos): ?>
        <div class="chips">
            <a class="chip <?= $por_pdv ? '' : 'ativo' ?>" href="/config/taxas">Padrão</a>
            <?php foreach ($ativos as $id => $nome): ?>
                <a class="chip <?= $pdv_taxas === (int) $id ? 'ativo' : '' ?>"
                   href="/config/taxas?pdv=<?= (int) $id ?>"><?= e($nome) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="cartao">
        <h2 class="sem-topo">
            <?= $por_pdv ? e((string) $ativos[$pdv_taxas]) : 'Custos e taxas' ?>
        </h2>
        <?php if ($por_pdv): ?>
            <p class="ajuda">
                Só o que for diferente neste ponto de venda. Campo em branco segue o padrão —
                o valor cinza é o que vale hoje. Apagar um campo desfaz a exceção.
            </p>
        <?php endif; ?>
        <form method="post" action="/config/taxas">
            <?= csrf_campo() ?>
            <?php if ($por_pdv): ?><input type="hidden" name="pdv_id" value="<?= (int) $pdv_taxas ?>"><?php endif; ?>
            <?php foreach ($grupos as $grupo): ?>
                <div class="filtros">
                    <?php foreach ($grupo as $campo => $rotulo): ?>
                        <label class="<?= count($grupo) === 1 ? 'col-cheia' : '' ?>"><?= e($rotulo) ?>
                            <input type="text" inputmode="decimal" name="<?= e($campo) ?>"
                                   value="<?= $por_pdv && !isset($overrides[$campo]) ? '' : e(number_format($por_pdv ? $overrides[$campo] : $p[$campo], 2, ',', '')) ?>"
                                   <?= $por_pdv ? 'placeholder="' . e(number_format($p[$campo], 2, ',', '')) . '"' : '' ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <p class="ajuda">
                Energia, sistema e internet são por container: o app soma os pontos de venda
                ativos para saber quanto a operação custa no mês. Os três viram um percentual
                do faturamento na hora de julgar se um produto paga a operação.
            </p>
            <?php if (!$por_pdv): ?>
                <label>CMV padrão, para produto sem nota (%)
                    <input type="text" inputmode="decimal" name="cmv_padrao_pct" value="<?= e(number_format($p['cmv_padrao_pct'], 2, ',', '')) ?>">
                </label>
            <?php endif; ?>
            <p class="ajuda">
                As taxas vêm da tabela do PagBank e mudam com o seu faturamento e com o fim
                da promoção — confira no app da maquininha. A taxa entra por forma de
                pagamento, venda a venda, e não por média.
            </p>
            <button type="submit" class="botao">
                <?= $por_pdv ? 'Salvar custos deste PDV' : 'Salvar custos' ?>
            </button>
        </form>
    </div>

    <?php if ($imposto): ?>
        <div class="cartao">
            <h2 class="sem-topo">Imposto (Simples Nacional)</h2>
            <p class="ajuda">
                CNAE 4712-1/00, Anexo I do Simples. Não dá para editar aqui porque não é um
                palpite — é calculado do seu faturamento real desde <?= data_fmt(custos_inicio_atividade()) ?>
                (quando a loja mudou de dono), e sobe sozinho se a loja crescer de faixa.
            </p>
            <dl class="dados">
                <div>
                    <dt><?= $imposto['anualizado'] ? 'RBT12 (anualizado)' : 'RBT12 (12 meses)' ?></dt>
                    <dd><?= e(number_format($imposto['rbt12'], 2, ',', '.')) ?></dd>
                </div>
                <div><dt>Faixa</dt><dd><?= e(number_format($imposto['faixa']['aliquota'], 2, ',', '.')) ?>% nominal</dd></div>
                <div><dt>Alíquota efetiva</dt><dd><?= e(number_format($imposto['efetiva'], 2, ',', '.')) ?>%</dd></div>
            </dl>
            <?php if ($imposto['anualizado']): ?>
                <p class="aviso aviso-info">
                    Ainda não tem 12 meses de atividade sob este CNPJ (<?= (int) $imposto['meses'] ?>
                    <?= (int) $imposto['meses'] === 1 ? 'mês' : 'meses' ?> até agora). O RBT12 acima não é o
                    que entrou de fato — é a receita anualizada (o que entrou ÷ meses × 12), que é a regra do
                    Simples para empresa em início de atividade. Sem isso a alíquota apareceria baixa demais
                    agora e daria um salto quando o primeiro ano fechasse.
                </p>
            <?php endif; ?>
            <p class="ajuda">
                É este percentual que entra em todo cálculo de margem, ao lado de maquininha,
                condomínio e franquia — igual para todo ponto de venda, porque o Simples é
                apurado pelo CNPJ, não por container.
            </p>
        </div>
    <?php endif; ?>

<?php endif; ?>
