<?php
/**
 * Repor os itens de uma nota.
 *
 * Nada aqui grava no TouchPay: "Continuar" cria um lote e abre a tela dele, que
 * confere cada produto, mostra o de/para e so grava depois do clique. Esta tela
 * existe para decidir o que repor, quanto, e se o preco muda.
 *
 * O preco sugerido e custo (da nota, ja sem o desconto) x taxa. Por item a
 * pessoa decide: manter o preco que esta na maquina ou trocar pelo sugerido —
 * o app nao escolhe por ela, porque preco errado aqui e preco errado no caixa.
 *
 * @var array   $nota
 * @var array   $itens    repor_nota_itens()
 * @var array   $pdvs
 * @var ?array  $pdv
 * @var string  $taxa
 * @var ?string $erro_tp
 * @var array   $erros    [{linha, msg}]
 * @var array   $post     o que ja estava digitado, quando a validacao devolve a tela
 */
$num = static fn($v, int $casas = 2): string => number_format((float) $v, $casas, ',', '');
$ponto = static fn($v): string => number_format((float) $v, 4, '.', '');
$qtd_campo = static function ($v): string {
    $s = number_format((float) $v, 4, ',', '');
    return rtrim(rtrim($s, '0'), ',') ?: '0';
};
$reenvio = $post !== [];
?>
<a class="voltar" href="/notas/<?= (int) $nota['id'] ?>">‹ Nota</a>
<h1>Repor itens</h1>
<p class="ajuda">
    <?= e($nota['loja'] ?? 'Sem loja') ?> · <?= e(data_fmt($nota['emissao'], true)) ?> ·
    <?= count($itens) ?> itens. Escolha o ponto de venda, marque o que vai para ele e decida item a item
    se o preço muda. Nada é gravado agora: no próximo passo você confere o de → para.
</p>

<?php if ($erro_tp): ?>
    <div class="aviso aviso-erro"><strong>TouchPay:</strong> <?= e($erro_tp) ?>
        <?php if (array_filter($itens, static fn($i) => $i['situacao'] === 'desconhecida')): ?>
            Os preços e estoques atuais não aparecem; dá para continuar mesmo assim, a conferência lê de novo.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($erros): ?>
    <div class="aviso aviso-erro">
        <strong>Nada foi criado.</strong> Corrija e continue:
        <ul>
            <?php foreach (array_slice($erros, 0, 30) as $er): ?>
                <li>Item <?= (int) $er['linha'] ?>: <?= e($er['msg']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$pdvs): ?>
    <p class="vazio">Nenhum ponto de venda com planograma ativo. Veja em <a href="/planograma">Repor</a>.</p>
<?php else: ?>
<form method="post" action="/notas/<?= (int) $nota['id'] ?>/repor" id="rn" data-id="<?= (int) $nota['id'] ?>">
    <?= csrf_campo() ?>

    <div class="cartao rn-topo">
        <label>Ponto de venda
            <select name="pdv" id="rn-pdv">
                <?php foreach ($pdvs as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= $pdv && (int) $p['id'] === (int) $pdv['id'] ? ' selected' : '' ?>>
                        <?= e($p['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Taxa (preço sugerido = custo × taxa)
            <input type="text" name="taxa" id="rn-taxa" inputmode="decimal" value="<?= e($taxa) ?>">
        </label>
        <div class="rn-atalhos">
            <button type="button" class="botao botao-alt" data-acao="marcar">Marcar todos</button>
            <button type="button" class="botao botao-alt" data-acao="desmarcar">Nenhum</button>
            <button type="button" class="botao botao-alt" data-acao="trocar">Trocar todos pelo sugerido</button>
            <button type="button" class="botao botao-alt" data-acao="manter">Manter todos</button>
        </div>
        <p class="ajuda" id="rn-conta"></p>
    </div>

    <?php foreach ($itens as $i):
        $id = (int) $i['id'];
        $p = $post[$id] ?? [];
        $a = $i['atual'];
        $marcado = $reenvio ? isset($p['sel']) : $i['marcado'];
        $fora = in_array($i['situacao'], ['fora'], true);
        $escolha = $p['escolha'] ?? ($a === null ? 'trocar' : 'manter');
        $sug = $p['preco'] ?? ($i['sugerido'] !== null ? $num($i['sugerido']) : '');
        $entrada = $p['entrada'] ?? $qtd_campo($i['entrada']);
        $nec = $p['necessaria'] ?? ($a ? $qtd_campo($a['necessaria']) : '');
        $cri = $p['critico'] ?? ($a ? $qtd_campo($a['critico']) : '');
        $val = $p['validade'] ?? '';
    ?>
    <div class="cartao rn-item<?= $i['travado'] ? ' rn-travado' : '' ?>"
         data-custo="<?= e($ponto($i['custo'])) ?>"
         data-atual="<?= $a ? e($ponto($a['preco'])) : '' ?>"
         data-estoque="<?= $a ? e($ponto($a['estoque'])) : '' ?>"
         data-editado="<?= isset($p['preco']) ? '1' : '0' ?>"
         data-travado="<?= $i['travado'] ? '1' : '0' ?>">

        <label class="rn-marca">
            <input type="checkbox" name="itens[<?= $id ?>][sel]" value="1" class="rn-sel"
                   <?= $marcado && !$i['travado'] ? 'checked' : '' ?> <?= $i['travado'] ? 'disabled' : '' ?>>
            <span class="forte"><?= (int) $i['num'] ?>. <?= e($i['descricao']) ?></span>
        </label>
        <div class="linha-baixo">
            <span class="ajuda">
                <?= qtd_fmt($i['qtd']) ?> <?= e($i['unidade']) ?> na nota · custo
                <?php if ($i['desconto'] > 0 && $i['custo_bruto'] > $i['custo']): ?>
                    <s class="riscado"><?= e(moeda($i['custo_bruto'])) ?></s>
                <?php endif; ?>
                <strong><?= e(moeda($i['custo'])) ?></strong> (líquido, por unidade)
            </span>
            <span class="mono"><?= $i['ean'] !== '' ? e($i['ean']) : 'sem GTIN' ?></span>
        </div>

        <div class="selos-linha">
            <?php if ($i['situacao'] === 'planograma'): ?><span class="selo selo-ok">no planograma</span><?php endif; ?>
            <?php if ($fora): ?><span class="selo selo-pendente">fora do planograma</span><?php endif; ?>
            <?php if ($i['situacao'] === 'desconhecida'): ?><span class="selo selo-pendente">planograma não lido</span><?php endif; ?>
            <?php foreach ($i['feitos'] as $f): ?>
                <span class="selo selo-ok">já reposto em <?= e($f['pdv']) ?> · <?= e(data_fmt($f['quando'])) ?></span>
            <?php endforeach; ?>
        </div>
        <?php if ($i['aviso']): ?><p class="ajuda"><?= e($i['aviso']) ?></p><?php endif; ?>
        <?php if ($i['fracionada']): ?>
            <p class="ajuda">Quantidade fracionada na nota: a entrada precisa ser um número inteiro, ajuste abaixo.</p>
        <?php endif; ?>

        <?php if (!$i['travado']): ?>
        <div class="rn-grade">
            <label>Entrada (soma ao estoque)
                <input type="text" name="itens[<?= $id ?>][entrada]" class="rn-entrada" inputmode="numeric"
                       value="<?= e($entrada) ?>">
            </label>
            <div class="rn-estoque">
                <span class="ajuda">Estoque</span>
                <strong class="rn-estoque-txt">—</strong>
            </div>
        </div>

        <div class="rn-preco">
            <div class="rn-atual">
                <span class="ajuda">Preço atual</span>
                <strong><?= $a ? e(moeda($a['preco'])) : '—' ?></strong>
            </div>
            <label>Preço sugerido
                <input type="text" name="itens[<?= $id ?>][preco]" class="rn-sug" inputmode="decimal"
                       value="<?= e($sug) ?>">
            </label>
            <div class="rn-calc">
                <span class="rn-var"></span>
                <span class="rn-margem"></span>
            </div>
        </div>

        <?php if ($a !== null): ?>
            <div class="rn-escolha" role="radiogroup" aria-label="Preço">
                <label><input type="radio" name="itens[<?= $id ?>][escolha]" value="manter" class="rn-esc"
                    <?= $escolha === 'manter' ? 'checked' : '' ?>> Manter o atual</label>
                <label><input type="radio" name="itens[<?= $id ?>][escolha]" value="trocar" class="rn-esc"
                    <?= $escolha === 'trocar' ? 'checked' : '' ?>> Trocar pelo sugerido</label>
            </div>
        <?php elseif ($i['situacao'] === 'desconhecida'): ?>
            <div class="rn-escolha">
                <label><input type="radio" name="itens[<?= $id ?>][escolha]" value="manter" class="rn-esc"
                    <?= $escolha === 'manter' ? 'checked' : '' ?>> Não mexer no preço</label>
                <label><input type="radio" name="itens[<?= $id ?>][escolha]" value="trocar" class="rn-esc"
                    <?= $escolha === 'trocar' ? 'checked' : '' ?>> Usar o sugerido</label>
            </div>
        <?php else: ?>
            <input type="hidden" name="itens[<?= $id ?>][escolha]" value="trocar" class="rn-esc-fixo">
            <p class="ajuda">Ainda não tem preço na máquina: o sugerido (ou o que você digitar) entra como preço.</p>
        <?php endif; ?>

        <details class="rn-mais"<?= ($reenvio && ($val !== '' )) ? ' open' : '' ?>>
            <summary>Necessária, crítico e validade</summary>
            <div class="rn-grade">
                <label>Necessária
                    <input type="text" name="itens[<?= $id ?>][necessaria]" inputmode="numeric" value="<?= e($nec) ?>">
                </label>
                <label>Crítico
                    <input type="text" name="itens[<?= $id ?>][critico]" inputmode="numeric" value="<?= e($cri) ?>">
                </label>
                <label>Validade atual
                    <input type="text" value="<?= $a && $a['validade'] ? e(pg_data_br($a['validade'])) : '—' ?>" disabled>
                </label>
                <label>Nova validade (dd/mm/aaaa)
                    <input type="text" name="itens[<?= $id ?>][validade]" inputmode="numeric" placeholder="vazio = não mexe"
                           value="<?= e($val) ?>">
                </label>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <button type="submit" class="botao botao-grande" id="rn-continuar">Continuar para a conferência</button>
</form>
<script src="/assets/repor-nota.js?v=1"></script>
<?php endif; ?>
