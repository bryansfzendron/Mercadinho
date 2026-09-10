<?php
/** @var array $nota */

/**
 * Quantidade num <input> de correcao: vírgula decimal e SEM separador de
 * milhar. qtd_fmt() escreveria "1.234", que num_br() leria de volta como 1,234.
 */
$qtd_campo = static function ($v): string {
    $s = number_format((float) $v, 4, ',', '');
    return rtrim(rtrim($s, '0'), ',') ?: '0';
};
?>
<a class="voltar" href="/notas">‹ Notas</a>

<div class="cartao">
    <h1><?= e($nota['loja'] ?? 'Sem loja identificada') ?></h1>
    <p class="meta">
        <?= cnpj_fmt($nota['cnpj']) ?><br>
        <?= e(trim(($nota['municipio'] ?? '') . ' ' . ($nota['uf'] ?? ''))) ?>
    </p>
    <dl class="dados">
        <div><dt>Emissão</dt><dd><?= data_fmt($nota['emissao'], true) ?></dd></div>
        <div><dt>Número</dt><dd><?= e($nota['numero'] ?: '-') ?> / série <?= e($nota['serie'] ?: '-') ?></dd></div>
        <div><dt>Produtos</dt><dd><?= moeda($nota['valor_produtos']) ?></dd></div>
        <div><dt>Desconto</dt><dd><?= moeda($nota['desconto_total']) ?></dd></div>
        <div><dt>Total</dt><dd class="valor"><?= moeda($nota['valor_total']) ?></dd></div>
        <div><dt>Origem</dt><dd><?= e($nota['origem']) ?></dd></div>
    </dl>
    <?php if ($nota['chave']): ?>
        <p class="chave"><?= e($nota['chave']) ?></p>
    <?php endif; ?>
    <?php if ($nota['status'] === 'erro' && $nota['erro_msg']): ?>
        <div class="aviso aviso-erro"><?= e($nota['erro_msg']) ?></div>
    <?php endif; ?>

    <?php if (in_array($nota['status'], ['pendente', 'processando'], true)): ?>
        <div class="aviso aviso-info">
            Esta nota está <?= e($nota['status']) ?> desde <?= data_fmt($nota['criado_em'], true) ?>.
            Se travou, remova para poder escanear o cupom de novo.
        </div>
    <?php endif; ?>

    <form method="post" action="/notas/<?= (int) $nota['id'] ?>/excluir"
          onsubmit="return confirm('Remover esta nota e todos os seus itens?');">
        <?= csrf_campo() ?>
        <button type="submit" class="botao botao-perigo">Remover nota</button>
    </form>
</div>

<h2><?= count($nota['itens']) ?> itens</h2>
<?php if ($nota['itens']): ?>
    <p class="ajuda">
        Comprou a caixa e vende a unidade? Abra <strong>Corrigir item</strong>: troque o
        código de barras pelo do que vai na prateleira e diga quantas unidades vieram —
        o valor pago fica igual e o preço por unidade se ajusta sozinho.
    </p>
<?php endif; ?>
<ul class="lista">
    <?php foreach ($nota['itens'] as $i): $iid = (int) $i['id']; ?>
        <li id="item-<?= $iid ?>">
            <a href="<?= $i['produto_id'] ? '/produtos/' . (int) $i['produto_id'] : '#' ?>">
                <div class="linha-topo">
                    <span class="forte"><?= e($i['descricao_original']) ?></span>
                    <span class="valor">
                        <?php if ((float) $i['desconto'] > 0): ?>
                            <s class="riscado"><?= moeda($i['valor_total']) ?></s>
                        <?php endif; ?>
                        <?= moeda($i['valor_total_liquido']) ?>
                    </span>
                </div>
                <div class="linha-baixo">
                    <span>
                        <?= qtd_fmt($i['quantidade']) ?> <?= e($i['unidade'] ?: 'un') ?>
                        × <?= moeda($i['valor_unitario_liquido']) ?>
                        <?php if ((float) $i['desconto'] > 0): ?>
                            · desconto <?= moeda($i['desconto']) ?>
                        <?php endif; ?>
                    </span>
                    <span class="mono">
                        <?= $i['produto_ean'] ? e($i['produto_ean']) : 'sem GTIN' ?>
                    </span>
                </div>
            </a>

            <details class="item-editor">
                <summary>Corrigir item</summary>
                <form method="post" action="/notas/<?= (int) $nota['id'] ?>/itens/<?= $iid ?>"
                      class="form-item">
                    <?= csrf_campo() ?>

                    <label>Descrição
                        <input type="text" name="descricao" required maxlength="255"
                               value="<?= e($i['descricao_original']) ?>">
                    </label>

                    <div class="duas">
                        <label>Código de barras
                            <input type="text" name="ean" class="campo-ean" inputmode="numeric"
                                   value="<?= e($i['produto_ean'] ?? '') ?>"
                                   placeholder="o que você bipa na prateleira">
                        </label>
                        <button type="button" class="botao botao-alt bipar">Bipar</button>
                    </div>

                    <div class="tres">
                        <label>Quantidade
                            <input type="text" name="quantidade" class="qtd" inputmode="decimal"
                                   value="<?= e($qtd_campo($i['quantidade'])) ?>">
                        </label>
                        <label>Unidade
                            <input type="text" name="unidade" maxlength="10"
                                   value="<?= e($i['unidade'] ?: 'UN') ?>">
                        </label>
                        <label>Total pago R$
                            <input type="text" name="valor_total" class="vt" inputmode="decimal"
                                   value="<?= number_format((float) $i['valor_total'], 2, ',', '') ?>">
                        </label>
                    </div>

                    <label>Desconto R$
                        <input type="text" name="desconto" class="vd" inputmode="decimal"
                               value="<?= number_format((float) $i['desconto'], 2, ',', '') ?>">
                    </label>

                    <div class="duas">
                        <label>Veio caixa fechada? Quantas unidades tinha dentro
                            <input type="text" class="por-caixa" inputmode="numeric" placeholder="12">
                        </label>
                        <button type="button" class="botao botao-alt abrir-caixa">Abrir caixa</button>
                    </div>

                    <p class="previa" aria-live="polite"></p>

                    <button type="submit" class="botao">Salvar item</button>
                </form>
            </details>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($nota['itens']): ?>
<script src="/assets/scanner.js?v=1"></script>
<script>
(function () {
    // Uma caixa de camera so para a pagina inteira: ela e movida para dentro do
    // item que pediu o bipe, em vez de existir escondida em cada um dos itens.
    let camera = null;
    let leitor = null;
    let alvo   = null;

    function num(v) {
        v = (v || '').toString().trim().replace(/\s/g, '');
        if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.');
        const f = parseFloat(v);
        return isNaN(f) ? 0 : f;
    }

    const brl = (v) => 'R$ ' + v.toFixed(2).replace('.', ',');

    function recalcular(form) {
        const qtd = num(form.querySelector('.qtd').value);
        const vt  = num(form.querySelector('.vt').value);
        const vd  = Math.min(Math.max(num(form.querySelector('.vd').value), 0), vt);
        const un  = (form.querySelector('[name="unidade"]').value || 'un').trim();
        const p   = form.querySelector('.previa');

        if (qtd <= 0 || vt <= 0) {
            p.textContent = 'Informe quantidade e total maiores que zero.';
            return;
        }
        // O unitario mostrado e o liquido: e ele que vira custo no histórico.
        p.textContent = qtd.toString().replace('.', ',') + ' ' + un + ' × ' +
            brl((vt - vd) / qtd) + ' = ' + brl(vt - vd) + ' pagos';
    }

    function caixaCamera() {
        if (camera) return camera;
        camera = document.createElement('div');
        camera.className = 'camera-caixa larga';
        camera.innerHTML = '<video playsinline muted></video><div class="mira mira-larga"></div>';
        return camera;
    }

    function fecharCamera() {
        if (leitor) { leitor.parar(); leitor = null; }
        if (camera) { camera.classList.remove('ligada'); camera.remove(); }
        alvo = null;
    }

    async function ligarCamera(form) {
        const campo = form.querySelector('.campo-ean');
        if (alvo === campo) { fecharCamera(); return; }

        fecharCamera();
        alvo = campo;
        const cx = caixaCamera();
        campo.closest('.duas').after(cx);

        try {
            leitor = await Scanner.iniciar(cx.querySelector('video'), Scanner.BARRAS, (codigo) => {
                campo.value = codigo;
                if (navigator.vibrate) navigator.vibrate(60);
                fecharCamera();
            });
            cx.classList.add('ligada');
        } catch (e) {
            fecharCamera();
            alert(e.message || 'Não consegui abrir a câmera.');
        }
    }

    document.querySelectorAll('.form-item').forEach((form) => {
        form.addEventListener('input', () => recalcular(form));
        recalcular(form);

        form.querySelector('.bipar').addEventListener('click', () => ligarCamera(form));

        // "Abrir caixa" so multiplica a quantidade. O total pago nao se mexe —
        // e por isso que o unitario cai na proporcao certa.
        form.querySelector('.abrir-caixa').addEventListener('click', () => {
            const n = num(form.querySelector('.por-caixa').value);
            if (n < 2) {
                alert('Quantas unidades vieram na caixa? Informe 2 ou mais.');
                return;
            }
            const q = form.querySelector('.qtd');
            // Arredonda em 4 casas (o que a coluna guarda) para 2 x 12 nao
            // virar "24.000000000000004" no campo.
            q.value = (Math.round(num(q.value) * n * 1e4) / 1e4).toString().replace('.', ',');
            form.querySelector('[name="unidade"]').value = 'UN';
            recalcular(form);
        });
    });

    // Fechar o editor solta a camera junto: sem isso o LED fica aceso.
    document.querySelectorAll('.item-editor').forEach((d) => {
        d.addEventListener('toggle', () => { if (!d.open && d.contains(camera)) fecharCamera(); });
    });
})();
</script>
<?php endif; ?>
