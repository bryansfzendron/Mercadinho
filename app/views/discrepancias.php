<?php
/**
 * Discrepancias das notas: caixa lancada como unidade.
 *
 * Um cartao por item suspeito, com o motivo, quem pode ser a unidade (o
 * cadastro vinculado, o codigo tirado do GTIN da caixa, nomes parecidos na
 * maquina) e quantas vem na caixa. "Abrir a caixa" e o mesmo "Corrigir item"
 * da nota, com os campos ja preenchidos.
 *
 * @var array $itens   discrepancias_itens() + candidatos e palpites
 * @var ?int  $nota_id
 */
$ponto = static fn($v): string => number_format((float) $v, 4, '.', '');
?>
<a class="voltar" href="<?= $nota_id ? '/notas/' . (int) $nota_id : '/notas' ?>">‹ <?= $nota_id ? 'Nota' : 'Notas' ?></a>
<h1>Discrepâncias</h1>
<p class="ajuda">
    Itens que parecem <strong>caixa lançada como unidade</strong>: o app acha que cada unidade custou o
    preço da caixa, e o custo das vendas, a margem e o preço sugerido saem errados. Escolha a unidade
    que você vende, diga quantas vêm na caixa e abra. O total pago não muda.
</p>
<?php if ($nota_id): ?>
    <p class="ajuda">Só os itens desta nota. <a href="/notas/discrepancias">Ver de todas as notas</a></p>
<?php endif; ?>

<?php
// Caixa ja aberta noutra nota nao tem o que decidir: vai para a lista de baixo,
// que abre tudo de uma vez. Em cima fica so o que precisa de alguem olhando.
$conhecidas = array_values(array_filter($itens, static fn($i) => $i['conhecida'] !== null));
$itens = array_values(array_filter($itens, static fn($i) => $i['conhecida'] === null));
?>
<?php if (!$itens && !$conhecidas): ?>
    <p class="vazio">Nenhuma discrepância. Todo item custa menos do que a unidade vende.</p>
<?php endif; ?>

<?php if ($itens): ?><h2>Para conferir (<?= count($itens) ?>)</h2><?php endif; ?>

<?php foreach ($itens as $i):
    $id = (int) $i['id'];
    $unidade = trim((string) $i['unidade']) ?: 'caixa';
?>
<div class="cartao disc-item" id="disc-<?= $id ?>"
     data-total="<?= e($ponto($i['valor_total_liquido'])) ?>" data-qtd="<?= e($ponto($i['quantidade'])) ?>">
    <div class="linha-topo">
        <span class="forte"><?= e($i['descricao_original']) ?></span>
        <span class="valor"><?= moeda($i['valor_total_liquido']) ?></span>
    </div>
    <p class="ajuda">
        <?= qtd_fmt($i['quantidade']) ?> <?= e($unidade) ?> a <?= moeda($i['valor_unitario_liquido']) ?> ·
        <a href="/notas/<?= (int) $i['nota_id'] ?>#item-<?= $id ?>"><?= e($i['loja'] ?? 'nota') ?>, <?= e(data_fmt($i['emissao'])) ?></a>
        <?php if (ean_normalizado($i['ean_original'])): ?>
            · <span class="mono">código na nota <?= e($i['ean_original']) ?></span>
        <?php endif; ?>
    </p>
    <p class="disc-motivo"><?= e($i['motivo']) ?></p>

    <form method="post" class="disc-form">
        <?= csrf_campo() ?>
        <input type="hidden" name="item" value="<?= $id ?>">

        <fieldset class="disc-unidades">
            <legend>Qual é a unidade que você vende?</legend>
            <?php foreach ($i['candidatos'] as $n => $c): ?>
                <label class="disc-opcao">
                    <input type="radio" name="ean_unidade" value="<?= e($c['ean']) ?>"
                           data-venda="<?= $c['venda'] !== null ? e($ponto($c['venda'])) : '' ?>"
                           <?= $n === 0 ? 'checked' : '' ?>>
                    <span>
                        <span class="forte"><?= e($c['nome'] ?: 'sem nome cadastrado') ?></span><br>
                        <span class="mono"><?= e($c['ean']) ?></span>
                        <?php if ($c['venda'] !== null): ?> · vende a <?= moeda($c['venda']) ?><?php endif; ?><br>
                        <span class="selo"><?= e($c['origem']) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
            <label class="disc-opcao">
                <input type="radio" name="ean_unidade" value="" class="disc-outro-radio" data-venda=""
                       <?= !$i['candidatos'] ? 'checked' : '' ?>>
                <span class="disc-outro">
                    Outro código
                    <span class="duas">
                        <input type="text" class="disc-outro-ean" inputmode="numeric" placeholder="código de barras da unidade"
                               autocomplete="off">
                        <button type="button" class="botao botao-alt disc-bipar">Bipar</button>
                    </span>
                </span>
            </label>
        </fieldset>

        <label>Quantas unidades vêm em cada <?= e($unidade) ?>?
            <input type="text" name="por_caixa" class="disc-por" inputmode="numeric"
                   value="<?= $i['palpites'] ? (int) $i['palpites'][0] : '' ?>">
        </label>
        <?php if (count($i['palpites']) > 1): ?>
            <div class="rn-atalhos">
                <?php foreach ($i['palpites'] as $p): ?>
                    <button type="button" class="botao botao-alt disc-palpite" data-n="<?= (int) $p ?>"><?= (int) $p ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="ajuda disc-previa" aria-live="polite"></p>

        <button type="submit" name="acao" value="abrir" class="botao">Abrir a caixa</button>
        <button type="submit" name="acao" value="ok" class="botao botao-alt disc-certo">Está certo, não é caixa</button>
    </form>
</div>
<?php endforeach; ?>

<?php if ($conhecidas): ?>
    <h2>Caixas já conhecidas (<?= count($conhecidas) ?>)</h2>
    <p class="ajuda">
        Esses produtos já foram abertos em unidades noutra nota, mas aqui ficaram fechados. Abrir repete a
        mesma correção: mesma unidade, mesma quantidade por caixa.
    </p>
    <div class="cartao">
        <?php foreach ($conhecidas as $i):
            $c = $i['candidatos'][0] ?? null;
            $por = (float) $i['conhecida']['por_caixa'];
            $un = (float) $i['valor_total_liquido'] / max(1, (float) $i['quantidade'] * $por);
        ?>
            <div class="disc-conhecida">
                <div class="linha-topo">
                    <span class="forte"><?= e($i['descricao_original']) ?></span>
                    <span class="valor"><?= moeda($i['valor_total_liquido']) ?></span>
                </div>
                <p class="ajuda">
                    <a href="/notas/<?= (int) $i['nota_id'] ?>#item-<?= (int) $i['id'] ?>"><?= e(data_fmt($i['emissao'])) ?></a> ·
                    <?= qtd_fmt($i['quantidade']) ?> × <?= qtd_fmt($por) ?> de
                    <?= e($c['nome'] ?? 'unidade') ?> a <?= moeda($un) ?> cada
                </p>
            </div>
        <?php endforeach; ?>
        <form method="post">
            <?= csrf_campo() ?>
            <button type="submit" name="acao" value="conhecidas" class="botao">
                Abrir as <?= count($conhecidas) ?> caixas
            </button>
        </form>
    </div>
<?php endif; ?>

<script src="/assets/scanner.js?v=3"></script>
<script src="/assets/discrepancias.js?v=1"></script>
