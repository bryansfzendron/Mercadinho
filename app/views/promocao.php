<?php
/**
 * Promocao para o que esta perto de vencer.
 *
 * Responde "por quanto ponho isto para sair a tempo?" e cria a resposta no
 * TouchPay como PROMOCAO: desconto percentual com comeco e fim. O preco do
 * planograma nao e tocado e volta sozinho quando a promocao acaba.
 *
 * Mesmo cuidado do Repor: o botao abre um resumo de/por, e so o segundo
 * toque manda. O servidor rele o preco antes de criar.
 *
 * @var array  $item
 * @var array  $validade
 * @var array  $custo
 * @var ?float $venda_dia
 * @var ?float $preco
 * @var array  $minimos
 * @var array  $sugestao
 * @var string $codigo
 * @var ?int   $percentual
 * @var array  $ativas
 */
$pdv_id  = (int) $item['pdv_id'];
$c       = $custo['custo'];
$sug     = $sugestao;
$taxa_br = static fn (float $t): string => number_format($t, 2, ',', '.');
$piso    = promocao_piso($minimos['prejuizo'] ?? null);

// Ate quando: a validade, que e quando o produto tem de sair de qualquer
// jeito. Validade ja passada ou ausente cai numa semana.
$hoje   = date('Y-m-d');
$fim    = $item['validade'] && $item['validade'] >= $hoje
    ? $item['validade']
    : date('Y-m-d', strtotime('+7 days'));
$pode_promover = $preco !== null && $preco > 0
    && !in_array($sug['acao'], ['retirar', 'sem_estoque', 'suspeita'], true);
?>
<a class="voltar" href="/promocoes">‹ Promoções</a>

<div class="cartao">
    <h1 class="sem-topo"><?= e($item['descricao']) ?></h1>
    <p class="mono"><?= e($codigo ?: 'sem código') ?> · <?= e($item['pdv']) ?></p>

    <?php if ($validade['texto']): ?>
        <p><span class="selo selo-validade <?= e($validade['classe']) ?>"><?= e($validade['texto']) ?></span>
            <span class="ajuda"><?= e(data_fmt($item['validade'])) ?></span></p>
    <?php endif; ?>

    <div class="numeros">
        <div class="numero">
            <strong><?= $validade['dias'] === null ? '—' : max(0, (int) $validade['dias']) ?></strong>
            <span><?= $validade['dias'] !== null && $validade['dias'] < 0 ? 'vencido' : 'dias' ?></span>
        </div>
        <div class="numero">
            <strong><?= qtd_fmt($item['estoque']) ?></strong>
            <span>em estoque</span>
        </div>
        <div class="numero">
            <strong><?= $venda_dia === null ? '—' : qtd_fmt(round($venda_dia, 1)) ?></strong>
            <span>vende/dia</span>
        </div>
    </div>

    <div class="dados">
        <div><dt>Preço hoje</dt><dd><?= $preco === null ? '—' : moeda($preco) ?>
            <?php if ($preco && $c): ?><span class="ajuda">(<?= fator_fmt($preco, $c) ?>×)</span><?php endif; ?></dd></div>
        <div><dt>Custo</dt><dd>
            <?php if ($c === null): ?>
                sem nota
            <?php else: ?>
                <?= moeda($c) ?>
                <span class="ajuda"><?= $custo['fonte'] === 'estoque' ? 'do que está na prateleira' : 'da última nota' ?></span>
            <?php endif; ?>
        </dd></div>
    </div>
</div>

<?php foreach ($ativas as $a): ?>
    <div class="aviso aviso-info">
        <?php $por = promocao_preco_final($a['tipo'], $preco, (float) $a['valor']); ?>
        <strong>Já tem promoção <?= e(promocao_status($a['inicio'], $a['fim'])) ?>:</strong>
        <?= e($a['desconto']) ?><?= $por !== null ? ', ' . moeda($por) : '' ?>,
        de <?= e(pg_data_br($a['inicio'])) ?> a <?= e(pg_data_br($a['fim'])) ?>.
    </div>
<?php endforeach; ?>

<?php if ($sug['acao'] === 'retirar'): ?>
    <div class="aviso aviso-erro">
        <strong>Retire da prateleira.</strong> <?= e($sug['motivos'][0]) ?>
    </div>
    <a class="botao" href="<?= e(promocao_link_repor($pdv_id, $codigo, null, null)) ?>">Zerar o estoque no Repor</a>

<?php elseif (in_array($sug['acao'], ['suspeita', 'sem_estoque', 'sem_validade'], true)): ?>
    <div class="aviso aviso-info"><?= e($sug['motivos'][0]) ?></div>
    <?php if ($sug['acao'] === 'suspeita'): ?>
        <a class="botao" href="<?= e(promocao_link_repor($pdv_id, $codigo, null, null)) ?>">Corrigir no Repor</a>
    <?php endif; ?>

<?php else: ?>
    <div class="cartao promo-sugestao">
        <?php if ($sug['acao'] === 'promocao'): ?>
            <p class="promo-rotulo">Promoção sugerida</p>
            <div class="promo-linha">
                <strong class="promo-taxa"><?= $taxa_br($sug['taxa']) ?><span>×</span></strong>
                <?php if ($sug['preco'] !== null): ?>
                    <div class="promo-preco">
                        <strong><?= $percentual !== null ? moeda(promocao_preco_com((float) $preco, $percentual)) : moeda($sug['preco']) ?></strong>
                        <?php if ($percentual !== null): ?>
                            <span>de <s><?= moeda($preco) ?></s> · −<?= $percentual ?>%</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="promo-rotulo">Não precisa de promoção</p>
        <?php endif; ?>
        <ul class="promo-motivos">
            <?php foreach ($sug['motivos'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?>
        </ul>
    </div>

    <?php if ($pode_promover): ?>
        <div class="cartao" id="promo-form"
             data-item="<?= (int) $item['id'] ?>"
             data-preco="<?= e((string) $preco) ?>"
             data-custo="<?= e($c === null ? '' : (string) $c) ?>"
             data-piso="<?= e($piso === null ? '' : (string) $piso) ?>"
             data-pct-variavel="<?= e((string) ($minimos['pct_variavel'] ?? 0)) ?>">
            <h2 class="sem-topo">Criar promoção no TouchPay</h2>
            <div class="pg-campos">
                <label>Desconto (%)
                    <input type="text" inputmode="numeric" data-promo="percentual"
                           value="<?= $sug['acao'] === 'promocao' && $percentual !== null ? $percentual : '' ?>"
                           placeholder="ex.: 20" autocomplete="off">
                </label>
                <label>Preço com desconto
                    <output class="promo-saida" id="promo-saida">—</output>
                </label>
                <label>Começa
                    <input type="date" data-promo="inicio" value="<?= e($hoje) ?>" min="<?= e($hoje) ?>">
                </label>
                <label>Termina
                    <input type="date" data-promo="fim" value="<?= e($fim) ?>" min="<?= e($hoje) ?>">
                </label>
            </div>
            <p class="ajuda" id="promo-conta"></p>

            <?php if ($c !== null): ?>
                <div class="promo-atalhos">
                    <?php foreach ($sug['opcoes'] as $o):
                        $p = promocao_percentual($preco, $o['preco']);
                        if ($p === null) { continue; }
                        $escolhida = $sug['taxa'] !== null && abs($o['taxa'] - $sug['taxa']) < 0.001; ?>
                        <button type="button" class="chip<?= $escolhida ? ' ativo' : '' ?><?= $o['abaixo_piso'] ? ' promo-prejuizo' : '' ?>"
                                data-pct="<?= $p ?>">
                            <?= $taxa_br($o['taxa']) ?>× · −<?= $p ?>%
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div id="promo-acao">
                <button type="button" class="botao" id="promo-criar">Criar promoção</button>
            </div>
            <p class="ajuda">O preço do planograma não muda: a promoção é um desconto com começo e
                fim, e quando ela acaba o preço normal volta sozinho.</p>
        </div>
    <?php elseif ($preco === null): ?>
        <p class="ajuda">Este item não tem preço de venda no planograma, então não há sobre o que dar desconto.</p>
    <?php endif; ?>
<?php endif; ?>

<script src="/assets/promocao.js?v=2"></script>
