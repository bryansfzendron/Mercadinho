<?php
/**
 * Mercado: conferir a conta antes de passar no caixa.
 *
 * Lista de rascunho, e so isso: bipa o produto na gondola, digita o preco da
 * etiqueta e no fim compara com o que o caixa cobrou. Nao grava nota, nao
 * mexe no catalogo e nao toca no espelho da loja — ela vive no localStorage
 * do aparelho, e some quando voce manda limpar.
 *
 * A conta e o desenho moram no /assets/mercado.js; aqui fica a camera, que e
 * a mesma do "bipar produto" com metade do trabalho: ali o codigo lido vira
 * uma consulta ao historico, aqui ele so preenche um campo.
 */
?>
<h1>Mercado</h1>
<p class="ajuda">
    Bipe os produtos enquanto compra e digite o preço da etiqueta. No fim,
    informe o total do caixa: o app diz se bate. Nada aqui mexe na sua loja.
</p>

<div id="mercado">

    <div class="camera-caixa larga" id="camera">
        <video id="video" muted playsinline></video>
        <div class="camera-espera" id="espera">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 9a2 2 0 0 1 2-2h1.6l1.2-2h6.4l1.2 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <circle cx="12" cy="13" r="3.4"/>
            </svg>
            <span id="espera-texto">Câmera desligada</span>
        </div>
        <div class="mira mira-larga"><b></b><i></i></div>
        <p class="camera-dica" id="dica"></p>
    </div>

    <div class="acoes camera-acoes">
        <button id="btn-camera" class="botao">Ligar câmera</button>
        <button id="btn-luz" class="botao botao-alt botao-icone" hidden>Lanterna</button>
    </div>

    <form id="m-form" class="cartao">
        <label>Código de barras
            <input id="m-codigo" type="text" inputmode="numeric" pattern="[0-9]*"
                   placeholder="bipe ou digite" autocomplete="off">
        </label>
        <label>Produto
            <input id="m-nome" type="text" placeholder="o nome vem sozinho quando dá"
                   autocomplete="off">
        </label>
        <p class="ajuda" id="m-dica-produto"></p>

        <div class="mercado-linha">
            <label>Preço da etiqueta
                <input id="m-preco" type="text" inputmode="decimal" placeholder="0,00"
                       autocomplete="off" autocorrect="off" spellcheck="false">
            </label>
            <label>Qtd
                <input id="m-qtd" type="text" inputmode="decimal" value="1"
                       autocomplete="off" autocorrect="off" spellcheck="false">
            </label>
        </div>
        <?php // Botao em linha propria: dentro do mercado o celular esta numa
              // mao so, e alvo largo erra menos que alvo espremido ao lado do
              // campo. ?>
        <button type="submit" class="botao">Adicionar</button>
        <p class="ajuda">
            Produto pesado entra pelo peso: 0,756 na quantidade e o preço do quilo.
            O nome que você escrever fica guardado naquele código de barras e volta
            sozinho na próxima compra.
        </p>
    </form>

    <div class="cartao centro">
        <p class="grande" id="m-total">R$ 0,00</p>
        <p class="ajuda">0 itens</p>
    </div>

    <ul class="lista" id="m-lista"></ul>

    <div class="cartao">
        <h2 class="sem-topo">No caixa</h2>
        <label>Total que o caixa cobrou
            <input id="m-caixa" type="text" inputmode="decimal" placeholder="0,00"
                   autocomplete="off" autocorrect="off" spellcheck="false">
        </label>
        <div id="m-conta" hidden></div>
    </div>

    <p class="centro"><button type="button" id="m-limpar" class="link-perigo">Limpar a lista</button></p>
</div>

<script src="/assets/scanner.js?v=3"></script>
<script src="/assets/ean-nome.js?v=1"></script>
<script src="/assets/mercado.js?v=4"></script>
<script>
(function () {
    /*
     * A camera desta tela. Mesmas regras do "bipar produto" — inclusive a de
     * nao chamar getUserMedia sozinha na primeira vez da sessao, que e o que
     * evita o iPhone perguntar da permissao em toda tela que tem camera.
     */
    const AUTO   = 'mercadinho:camera-auto';
    const SESSAO = 'mercadinho:camera-sessao';

    const tela   = document.getElementById('mercado');
    const video  = document.getElementById('video');
    const caixa  = document.getElementById('camera');
    const espera = document.getElementById('espera-texto');
    const dica   = document.getElementById('dica');
    const btnCam = document.getElementById('btn-camera');
    const btnLuz = document.getElementById('btn-luz');
    let leitor = null;
    let luzAcesa = false;

    function jaAbriuNestaSessao() {
        try { return sessionStorage.getItem(SESSAO) === '1'; } catch (e) { return false; }
    }
    function marcarSessao() {
        try { sessionStorage.setItem(SESSAO, '1'); } catch (e) { /* modo privado */ }
    }
    function lembrar(ligada) {
        try { localStorage.setItem(AUTO, ligada ? '1' : '0'); } catch (e) { /* modo privado */ }
    }
    function querAutomatico() {
        try { return localStorage.getItem(AUTO) !== '0'; } catch (e) { return false; }
    }

    async function ligar(automatico) {
        if (leitor) return;
        dica.textContent = 'Abrindo a câmera...';
        try {
            leitor = await Scanner.iniciar(video, Scanner.BARRAS, aoBipar);
            caixa.classList.add('ligada');
            btnCam.textContent = 'Desligar câmera';
            btnCam.classList.add('botao-alt');
            dica.textContent = 'Procurando o código de barras...';
            btnLuz.hidden = !leitor.lanternaDisponivel();
            lembrar(true);
            marcarSessao();
        } catch (e) {
            leitor = null;
            dica.textContent = '';
            if (automatico) { espera.textContent = 'Toque em "Ligar câmera"'; return; }
            espera.textContent = e.semPermissao ? 'Sem permissão de câmera' : 'Câmera indisponível';
        }
    }

    function desligar() {
        if (leitor) { leitor.parar(); leitor = null; }
        caixa.classList.remove('ligada');
        btnCam.textContent = 'Ligar câmera';
        btnCam.classList.remove('botao-alt');
        btnLuz.hidden = true;
        luzAcesa = false;
        btnLuz.classList.remove('botao-ligado');
        espera.textContent = 'Câmera desligada';
        dica.textContent = '';
    }

    btnCam.addEventListener('click', () => {
        if (leitor) { lembrar(false); desligar(); return; }
        ligar(false);
    });

    btnLuz.addEventListener('click', async () => {
        if (!leitor) return;
        luzAcesa = !luzAcesa;
        const ok = await leitor.lanterna(luzAcesa);
        if (!ok) { luzAcesa = false; btnLuz.hidden = true; return; }
        btnLuz.classList.toggle('botao-ligado', luzAcesa);
    });

    let ultimo = '';
    let quando = 0;

    function aoBipar(codigo) {
        // O leitor repete o mesmo codigo enquanto o produto estiver na mira.
        // Sem esta trava, um produto parado na frente da camera entraria na
        // lista varias vezes enquanto o preco e digitado.
        const agora = Date.now();
        if (codigo === ultimo && agora - quando < 3000) return;
        ultimo = codigo;
        quando = agora;

        if (navigator.vibrate) navigator.vibrate(60);
        caixa.classList.add('leu');
        setTimeout(() => caixa.classList.remove('leu'), 500);
        dica.textContent = 'Digite o preço e toque em Adicionar';

        tela.dispatchEvent(new CustomEvent('mercado:codigo', { detail: codigo }));
    }

    document.addEventListener('visibilitychange', () => {
        if (!leitor) return;
        if (document.hidden) { leitor.pausar(); } else { leitor.retomar(); }
    });

    (async function abrirSozinha() {
        if (!querAutomatico()) return;
        const estadoPerm = await Scanner.permissao();
        if (estadoPerm === 'denied') { espera.textContent = 'Sem permissão de câmera'; return; }
        if (estadoPerm === 'granted' || jaAbriuNestaSessao()) { ligar(true); return; }
        espera.textContent = 'Toque em "Ligar câmera"';
    })();
})();
</script>
