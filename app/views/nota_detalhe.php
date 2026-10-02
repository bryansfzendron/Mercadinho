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

/**
 * Texto que o filtro de itens procura, ja normalizado no PHP — o mesmo
 * normalizar_texto() da busca de produtos. Alem do nome, casa pelos codigos:
 * o de barras (o do produto e o que a nota mandou) e o codigo interno da loja,
 * que nem aparece na linha mas e o que o fornecedor usa no pedido.
 */
$chave_busca = static function (array $i): string {
    $partes = [
        normalizar_texto($i['descricao_original']),
        (string) ($i['produto_ean'] ?? ''),
        (string) ($i['caixa_ean'] ?? ''),
        (string) ($i['ean_original'] ?? ''),
        normalizar_texto($i['cod_interno'] ?? ''),
        (string) ($i['item_num'] ?? ''),
    ];
    return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($partes))));
};

/**
 * O item visto como a nota o trouxe. Caixa aberta grava a unidade (12 UN e o
 * codigo da lata); o editor e a linha falam da caixa (1 CX e o codigo dela),
 * com a unidade a parte. Sem caixa aberta, e o proprio item.
 *
 * @return array{aberta:bool, por:float, qtd:float, unidade:string, ean:string,
 *               ean_un:string, preco_caixa:float}
 */
$como_nota = static function (array $i): array {
    $por = (float) ($i['por_caixa'] ?? 0);
    if ($por <= 0) {
        return ['aberta' => false, 'por' => 0.0, 'qtd' => (float) $i['quantidade'],
                'unidade' => $i['unidade'] ?: 'UN', 'ean' => (string) ($i['produto_ean'] ?? ''),
                'ean_un' => '', 'preco_caixa' => 0.0];
    }
    $proprio = $i['caixa_produto_id'] !== null;
    return [
        'aberta'      => true,
        'por'         => $por,
        'qtd'         => (float) $i['quantidade'] / $por,
        'unidade'     => $i['caixa_unidade'] ?: 'CX',
        // Caixa e unidade num cadastro so: o codigo e um, e fica la em cima.
        'ean'         => (string) ($proprio ? ($i['caixa_ean'] ?? '') : ($i['produto_ean'] ?? '')),
        'ean_un'      => (string) ($proprio ? ($i['produto_ean'] ?? '') : ''),
        'preco_caixa' => (float) $i['valor_unitario_liquido'] * $por,
    ];
};

// Filtro so aparece quando ha rolagem para valer: numa nota de feira com tres
// linhas ele seria mais um campo para ignorar.
$vale_filtrar = count($nota['itens']) >= 8;
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
        Comprou a caixa e vende a unidade? Abra <strong>Corrigir item</strong>, bipe o
        código da unidade e diga quantas vêm na caixa. A caixa continua com o código e o
        preço dela, e a unidade ganha o seu — o valor pago fica igual.
    </p>
<?php endif; ?>

<?php if ($vale_filtrar): ?>
    <input type="search" id="busca-itens" class="busca-itens"
           placeholder="filtrar por nome ou código" aria-controls="lista-itens"
           autocomplete="off" autocorrect="off" spellcheck="false">
    <p class="ajuda" id="busca-conta" aria-live="polite" hidden></p>
<?php endif; ?>

<ul class="lista" id="lista-itens">
    <?php foreach ($nota['itens'] as $i): $iid = (int) $i['id']; ?>
        <?php $barras = codigo_barras_svg($i['produto_ean'] ?? null); $cx = $como_nota($i); ?>
        <li id="item-<?= $iid ?>" data-busca="<?= e($chave_busca($i)) ?>">
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
                        <?php if ($cx['aberta']): ?>
                            <?= qtd_fmt($cx['qtd']) ?> <?= e($cx['unidade']) ?>
                            × <?= moeda($cx['preco_caixa']) ?> ·
                        <?php endif; ?>
                        <?= qtd_fmt($i['quantidade']) ?> <?= e($i['unidade'] ?: 'un') ?>
                        × <?= moeda($i['valor_unitario_liquido']) ?>
                        <?php if ((float) $i['desconto'] > 0): ?>
                            · desconto <?= moeda($i['desconto']) ?>
                        <?php endif; ?>
                    </span>
                    <?php // Com o simbolo desenhado embaixo, repetir o numero aqui so
                          // faria barulho: o proprio codigo de barras ja o escreve. ?>
                    <?php if (!$barras): ?>
                        <span class="mono">
                            <?= $i['produto_ean'] ? e($i['produto_ean']) : 'sem GTIN' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($barras): ?>
                    <div class="barras"><?= $barras ?></div>
                <?php endif; ?>
                <?php if ($cx['aberta'] && $i['caixa_produto_id'] !== null): ?>
                    <div class="linha-baixo">
                        <span class="ajuda">caixa com <?= qtd_fmt($cx['por']) ?></span>
                        <span class="mono"><?= $cx['ean'] !== '' ? e($cx['ean']) : 'caixa sem GTIN' ?></span>
                    </div>
                <?php endif; ?>
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
                                   value="<?= e($cx['ean']) ?>"
                                   placeholder="o que veio na nota">
                        </label>
                        <button type="button" class="botao botao-alt bipar">Bipar</button>
                    </div>

                    <div class="tres">
                        <label>Quantidade
                            <input type="text" name="quantidade" class="qtd" inputmode="decimal"
                                   value="<?= e($qtd_campo($cx['qtd'])) ?>">
                        </label>
                        <label>Unidade
                            <input type="text" name="unidade" maxlength="10"
                                   value="<?= e($cx['unidade']) ?>">
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

                    <?php // A caixa nao e aberta no cliente: quem multiplica e o
                          // servidor, e o formulario continua falando da caixa.
                          // Vazio fecha de novo. ?>
                    <fieldset class="caixa-aberta">
                        <legend>Veio caixa? Vende por unidade</legend>
                        <label>Unidades por caixa
                            <input type="text" name="por_caixa" class="por-caixa" inputmode="numeric"
                                   value="<?= $cx['aberta'] ? e($qtd_campo($cx['por'])) : '' ?>"
                                   placeholder="12">
                        </label>
                        <div class="duas">
                            <label>Código da unidade
                                <input type="text" name="ean_unidade" class="campo-ean-un" inputmode="numeric"
                                       value="<?= e($cx['ean_un']) ?>"
                                       placeholder="o que você bipa na prateleira">
                            </label>
                            <button type="button" class="botao botao-alt bipar-un">Bipar</button>
                        </div>
                    </fieldset>

                    <p class="previa" aria-live="polite"></p>

                    <button type="submit" class="botao">Salvar item</button>
                </form>

                <?php // Form proprio: <form> nao aninha dentro de <form>.
                      // A confirmacao nomeia o item, entao vem por data- e nao
                      // por onsubmit: escapar descricao para dentro de JS inline
                      // e onde esse tipo de coisa quebra. ?>
                <form method="post" class="acao-linha"
                      action="/notas/<?= (int) $nota['id'] ?>/itens/<?= $iid ?>/excluir"
                      data-confirma="Tirar &quot;<?= e($i['descricao_original']) ?>&quot; desta nota?">
                    <?= csrf_campo() ?>
                    <button type="submit" class="link-perigo">remover item da nota</button>
                </form>
            </details>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($nota['itens']): ?>
<script src="/assets/scanner.js?v=3"></script>
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

    const qtdTxt = (v) => (Math.round(v * 1e4) / 1e4).toString().replace('.', ',');

    function recalcular(form) {
        const qtd = num(form.querySelector('.qtd').value);
        const vt  = num(form.querySelector('.vt').value);
        const vd  = Math.min(Math.max(num(form.querySelector('.vd').value), 0), vt);
        const un  = (form.querySelector('[name="unidade"]').value || 'un').trim();
        const por = num(form.querySelector('.por-caixa').value);
        const p   = form.querySelector('.previa');

        if (qtd <= 0 || vt <= 0) {
            p.textContent = 'Informe quantidade e total maiores que zero.';
            return;
        }
        if (por > 0 && por < 2) {
            p.textContent = 'Quantas unidades vêm na caixa? Informe 2 ou mais.';
            return;
        }
        // O unitario mostrado e o liquido: e ele que vira custo no histórico.
        let txt = qtdTxt(qtd) + ' ' + un + ' × ' + brl((vt - vd) / qtd) + ' = ' + brl(vt - vd) + ' pagos';
        // Caixa aberta: o mesmo total, dividido pelas unidades de dentro.
        if (por >= 2) {
            txt += ' · ' + qtdTxt(qtd * por) + ' UN × ' + brl((vt - vd) / (qtd * por));
        }
        p.textContent = txt;
    }

    function caixaCamera() {
        if (camera) return camera;
        camera = document.createElement('div');
        camera.className = 'camera-caixa larga';
        camera.innerHTML = '<video playsinline muted></video><div class="mira mira-larga"></div>';
        return camera;
    }

    /*
     * Fecha a caixa da camera. Por padrao **guarda** a camera para o proximo
     * item: bipar uma nota de 100 itens chamava getUserMedia 100 vezes, e no
     * iPhone cada chamada dessas pode virar um pedido de permissao novo.
     *
     * A contrapartida de guardar e o LED continuar aceso enquanto o usuario
     * digita, entao quem guarda marca a hora: passado OCIOSO_MS sem ninguem
     * pedir a camera de volta, ela e solta de verdade. Sair da pagina ou
     * esconder a aba solta na hora (ver mais abaixo).
     */
    const OCIOSO_MS = 45000;
    let ocioso = null;

    function fecharCamera(opcoes) {
        const guardar = opcoes && opcoes.guardar;
        if (leitor) { leitor.parar({ liberar: !guardar }); leitor = null; }
        if (camera) { camera.classList.remove('ligada'); camera.remove(); }
        alvo = null;

        clearTimeout(ocioso);
        if (guardar) {
            ocioso = setTimeout(() => Scanner.liberar(), OCIOSO_MS);
        }
    }

    async function ligarCamera(campo) {
        // Tocar de novo no mesmo item e desligar de proposito: ai solta mesmo,
        // senao "desliguei" e o LED continua aceso por mais 45 segundos.
        if (alvo === campo) { fecharCamera(); return; }

        fecharCamera({ guardar: true });
        // Depois do fecharCamera(): e ele que arma o timer de ocioso, e aqui a
        // camera vai ser reaberta agora mesmo. Sem esta linha, o timer armado
        // ali em cima soltaria a camera 45s depois, com ela em uso.
        clearTimeout(ocioso);
        alvo = campo;
        const cx = caixaCamera();
        campo.closest('.duas').after(cx);

        try {
            leitor = await Scanner.iniciar(cx.querySelector('video'), Scanner.BARRAS, (codigo) => {
                campo.value = codigo;
                campo.dispatchEvent(new Event('input', { bubbles: true }));
                if (navigator.vibrate) navigator.vibrate(60);
                // Leu um item e provavelmente vai ler o proximo: guarda.
                fecharCamera({ guardar: true });
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

        form.querySelector('.bipar').addEventListener('click',
            () => ligarCamera(form.querySelector('.campo-ean')));
        form.querySelector('.bipar-un').addEventListener('click',
            () => ligarCamera(form.querySelector('.campo-ean-un')));
    });

    // Fechar o editor solta a camera junto: sem isso o LED fica aceso.
    document.querySelectorAll('.item-editor').forEach((d) => {
        d.addEventListener('toggle', () => { if (!d.open && d.contains(camera)) fecharCamera(); });
    });

    // Guardar a camera entre os itens so vale enquanto a pagina esta na frente
    // do usuario. Saindo dela, ou trocando de app, ela e solta na hora — o
    // mesmo principio das outras telas: trocar de aba nao pode manter a camera
    // ligada consumindo bateria. pagehide cobre tambem o Safari, que congela a
    // pagina em vez de descarregar quando o "voltar" ainda pode traze-la.
    window.addEventListener('pagehide', () => { clearTimeout(ocioso); fecharCamera(); });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { clearTimeout(ocioso); fecharCamera(); }
    });

    // Remover item pede confirmacao nomeando o item.
    document.querySelectorAll('form[data-confirma]').forEach((f) => {
        f.addEventListener('submit', (ev) => {
            if (!confirm(f.dataset.confirma)) ev.preventDefault();
        });
    });

    // ---- filtro dos itens ----
    // Tudo ja esta na pagina: filtrar no cliente responde a cada tecla, nao
    // recarrega e nao perde o editor que estiver aberto.
    const busca = document.getElementById('busca-itens');
    if (busca) {
        const conta  = document.getElementById('busca-conta');
        const linhas = Array.from(document.querySelectorAll('#lista-itens > li'));
        const GUARDA = 'busca-nota-<?= (int) $nota['id'] ?>';

        // Mesma normalizacao do normalizar_texto() que gerou o data-busca.
        const normal = (s) => (s || '').toUpperCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^A-Z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();

        function filtrar() {
            // Termos somam (E), nao trocam: "coca lata" acha a lata de coca.
            const termos = normal(busca.value).split(' ').filter(Boolean);
            let vistos = 0;

            linhas.forEach((li) => {
                const bate = termos.every((t) => (li.dataset.busca || '').includes(t));
                li.hidden = !bate;
                if (bate) vistos++;
            });

            conta.hidden = termos.length === 0;
            conta.textContent = vistos
                ? vistos + ' de ' + linhas.length + ' itens'
                : 'Nenhum item bate com essa busca.';

            // O item que estava com a camera aberta pode ter saido do filtro.
            const dono = camera && camera.closest('li');
            if (dono && dono.hidden) fecharCamera();

            try { sessionStorage.setItem(GUARDA, busca.value); } catch (e) { /* modo privado */ }
        }

        busca.addEventListener('input', filtrar);

        // Salvar um item volta para ca com #item-N. So nesse caso o filtro e
        // restaurado: quem corrige tres itens de uma busca nao redigita a cada
        // um. Visita nova comeca limpa, senao a nota abriria escondendo linhas.
        if (location.hash.startsWith('#item-')) {
            let guardado = '';
            try { guardado = sessionStorage.getItem(GUARDA) || ''; } catch (e) { /* modo privado */ }
            if (guardado) {
                busca.value = guardado;
                filtrar();
                // O navegador ja tinha rolado antes de a linha reaparecer.
                const alvo = document.querySelector(location.hash);
                if (alvo && !alvo.hidden) alvo.scrollIntoView();
            }
        } else {
            // Visita nova zera o que ficou guardado, senao um filtro de meia
            // hora atras ressuscitaria no primeiro "Salvar item" desta visita.
            try { sessionStorage.removeItem(GUARDA); } catch (e) { /* modo privado */ }
        }
    }
})();
</script>
<?php endif; ?>
