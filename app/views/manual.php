<?php
/** @var array $lojas */
$ean_inicial = ean_normalizado($_GET['ean'] ?? '') ?? '';
?>
<a class="voltar" href="/notas">‹ Notas</a>
<h1>Lançar nota manual</h1>
<p class="ajuda">Para cupons sem QR Code, feiras, açougue — qualquer compra que você queira no histórico.</p>

<form method="post" action="/manual" id="form-manual">
    <?= csrf_campo() ?>

    <div class="cartao">
        <h2>Onde comprei</h2>
        <label>Loja já cadastrada
            <select name="loja_id" id="loja_id">
                <option value="0">— nova loja —</option>
                <?php foreach ($lojas as $l): ?>
                    <option value="<?= (int) $l['id'] ?>">
                        <?= e($l['nome']) ?><?= $l['municipio'] ? ' · ' . e($l['municipio']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div id="loja-nova">
            <label>Nome da loja
                <input type="text" name="loja_nome" placeholder="Mercado do Zé">
            </label>
            <div class="duas">
                <label>CNPJ (opcional)
                    <input type="text" name="cnpj" inputmode="numeric" placeholder="00.000.000/0000-00">
                </label>
                <label>UF
                    <input type="text" name="uf" maxlength="2" placeholder="SP">
                </label>
            </div>
            <label>Município
                <input type="text" name="municipio" placeholder="São Paulo">
            </label>
        </div>

        <label>Data da compra
            <input type="datetime-local" name="emissao" value="<?= date('Y-m-d\TH:i') ?>">
        </label>
    </div>

    <h2>Itens</h2>
    <div id="itens"></div>
    <button type="button" id="add" class="botao botao-alt">+ Adicionar item</button>

    <div class="camera-caixa oculto" id="camera-caixa">
        <video id="video" muted playsinline></video>
        <div class="mira mira-larga"></div>
    </div>

    <p class="total-previa" id="previa">Total: R$ 0,00</p>
    <button type="submit" class="botao botao-grande">Salvar nota</button>
</form>

<script src="/assets/scanner.js?v=3"></script>
<script>
(function () {
    const itens   = document.getElementById('itens');
    const previa  = document.getElementById('previa');
    const caixa   = document.getElementById('camera-caixa');
    const video   = document.getElementById('video');
    const eanInicial = <?= json_encode($ean_inicial) ?>;
    let n = 0;
    let leitor = null;
    let alvoEan = null;

    function novaLinha(ean) {
        const i = n++;
        const div = document.createElement('div');
        div.className = 'cartao item-linha';
        div.innerHTML =
            '<div class="duas">' +
              '<label>Código de barras' +
                '<input type="text" name="itens[' + i + '][ean]" class="campo-ean" ' +
                       'inputmode="numeric" value="' + (ean || '') + '" placeholder="opcional">' +
              '</label>' +
              '<button type="button" class="botao botao-alt bipar">Bipar</button>' +
            '</div>' +
            '<label>Descrição' +
              '<input type="text" name="itens[' + i + '][descricao]" required placeholder="Arroz 5kg">' +
            '</label>' +
            '<div class="tres">' +
              '<label>Qtd<input type="text" name="itens[' + i + '][quantidade]" class="qtd" ' +
                     'inputmode="decimal" value="1"></label>' +
              '<label>Un<input type="text" name="itens[' + i + '][unidade]" value="UN" maxlength="10"></label>' +
              '<label>Valor unit.<input type="text" name="itens[' + i + '][valor_unitario]" class="vu" ' +
                     'inputmode="decimal" placeholder="0,00"></label>' +
            '</div>' +
            '<button type="button" class="remover">remover item</button>';

        div.querySelector('.remover').addEventListener('click', () => { div.remove(); recalcular(); });
        div.querySelector('.bipar').addEventListener('click', () => ligarCamera(div.querySelector('.campo-ean')));
        div.querySelectorAll('.qtd, .vu').forEach(c => c.addEventListener('input', recalcular));
        itens.appendChild(div);
        return div;
    }

    function num(v) {
        v = (v || '').toString().trim().replace(/\s/g, '');
        if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.');
        const f = parseFloat(v);
        return isNaN(f) ? 0 : f;
    }

    function recalcular() {
        let t = 0;
        itens.querySelectorAll('.item-linha').forEach(l => {
            t += num(l.querySelector('.qtd').value) * num(l.querySelector('.vu').value);
        });
        previa.textContent = 'Total: R$ ' + t.toFixed(2).replace('.', ',');
    }

    /*
     * Uma nota manual tem varias linhas de item, e cada uma tem o seu "bipar".
     * Soltando a camera a cada leitura, bipar cinco itens virava cinco
     * getUserMedia — e no iPhone cada um desses pode virar um pedido de
     * permissao novo. Aqui a camera fica guardada para a proxima linha; o
     * preco disso e o LED seguir aceso enquanto se digita, entao um ocioso
     * solta de verdade quando ninguem volta a usar.
     */
    const OCIOSO_MS = 45000;
    let ocioso = null;

    function fecharCamera(opcoes) {
        const guardar = opcoes && opcoes.guardar;
        if (leitor) { leitor.parar({ liberar: !guardar }); leitor = null; }
        caixa.classList.add('oculto');
        clearTimeout(ocioso);
        if (guardar) {
            ocioso = setTimeout(() => Scanner.liberar(), OCIOSO_MS);
        }
    }

    async function ligarCamera(campo) {
        alvoEan = campo;
        caixa.classList.remove('oculto');
        clearTimeout(ocioso);
        if (leitor) return;
        try {
            leitor = await Scanner.iniciar(video, Scanner.BARRAS, (codigo) => {
                if (alvoEan) alvoEan.value = codigo;
                if (navigator.vibrate) navigator.vibrate(60);
                fecharCamera({ guardar: true });
            });
        } catch (e) {
            fecharCamera();
            alert(e.message || 'Não consegui abrir a câmera.');
        }
    }

    // Sair da pagina ou trocar de app solta a camera na hora: guardar so vale
    // enquanto a tela esta na frente de quem esta bipando.
    window.addEventListener('pagehide', () => { clearTimeout(ocioso); fecharCamera(); });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { clearTimeout(ocioso); fecharCamera(); }
    });

    document.getElementById('add').addEventListener('click', () => novaLinha(''));

    document.getElementById('loja_id').addEventListener('change', (ev) => {
        document.getElementById('loja-nova').style.display = ev.target.value === '0' ? '' : 'none';
    });

    novaLinha(eanInicial);
})();
</script>
