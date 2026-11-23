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

    <?php include __DIR__ . '/_disc_form.php'; ?>
</div>
<?php endforeach; ?>

<?php if ($conhecidas):
    $suspeitas = count(array_filter($conhecidas, static fn($i) => $i['conhecida']['suspeita'] !== null));
?>
    <h2>Caixas já conhecidas (<?= count($conhecidas) ?>)</h2>
    <p class="ajuda">
        Esses produtos já foram abertos em unidades noutra nota, mas aqui ficaram fechados. Abrir repete a
        mesma correção: mesma unidade, mesma quantidade por caixa. Desmarque o que não bate e use
        <strong>Conferir à mão</strong> para escolher outra unidade ou outra quantidade.
        <?php if ($suspeitas): ?>
            <?= $suspeitas === 1 ? 'Uma ficou desmarcada' : $suspeitas . ' ficaram desmarcadas' ?>
            porque o custo por unidade sai longe do de sempre.
        <?php endif; ?>
    </p>
    <div class="cartao disc-conhecidas">
        <div class="disc-marcar">
            <button type="button" class="botao botao-alt disc-todas" data-marcar="1">Marcar todas</button>
            <button type="button" class="botao botao-alt disc-todas" data-marcar="0">Desmarcar todas</button>
        </div>
        <?php foreach ($conhecidas as $i):
            $id = (int) $i['id'];
            $unidade = trim((string) $i['unidade']) ?: 'caixa';
            $c = $i['candidatos'][0] ?? null;
            $por = (float) $i['conhecida']['por_caixa'];
            $suspeita = $i['conhecida']['suspeita'];
        ?>
            <div class="disc-conhecida<?= $suspeita ? ' disc-suspeita' : '' ?>">
                <label class="disc-marca">
                    <input type="checkbox" form="disc-conhecidas" name="itens[]" value="<?= $id ?>"
                           <?= $suspeita ? '' : 'checked' ?>>
                    <span class="disc-marca-texto">
                        <span class="linha-topo">
                            <span class="forte"><?= e($i['descricao_original']) ?></span>
                            <span class="valor"><?= moeda($i['valor_total_liquido']) ?></span>
                        </span>
                        <span class="ajuda">
                            <?= e(data_fmt($i['emissao'])) ?> ·
                            <?= qtd_fmt($i['quantidade']) ?> × <?= qtd_fmt($por) ?> de
                            <?= e($c['nome'] ?? 'unidade') ?> a <?= moeda($i['conhecida']['custo_un']) ?> cada
                        </span>
                    </span>
                </label>
                <?php if ($suspeita): ?>
                    <p class="disc-motivo"><?= e($suspeita) ?></p>
                <?php endif; ?>
                <details class="disc-item disc-conferir" id="disc-<?= $id ?>"
                         data-total="<?= e($ponto($i['valor_total_liquido'])) ?>" data-qtd="<?= e($ponto($i['quantidade'])) ?>"
                         <?= $suspeita ? 'open' : '' ?>>
                    <summary>Conferir à mão</summary>
                    <p class="ajuda">
                        <a href="/notas/<?= (int) $i['nota_id'] ?>#item-<?= $id ?>">Ver na nota</a>
                        <?php if (ean_normalizado($i['ean_original'])): ?>
                            · <span class="mono">código na nota <?= e($i['ean_original']) ?></span>
                        <?php endif; ?>
                    </p>
                    <?php include __DIR__ . '/_disc_form.php'; ?>
                </details>
            </div>
        <?php endforeach; ?>
        <form method="post" id="disc-conhecidas">
            <?= csrf_campo() ?>
            <button type="submit" name="acao" value="conhecidas" class="botao disc-abrir-marcadas">
                Abrir as marcadas (<span class="disc-n"><?= count($conhecidas) - $suspeitas ?></span>)
            </button>
        </form>
    </div>
<?php endif; ?>

<script src="/assets/scanner.js?v=3"></script>
<script src="/assets/discrepancias.js?v=2"></script>
