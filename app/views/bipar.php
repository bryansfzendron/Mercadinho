<h1>Bipar produto</h1>
<p class="ajuda">Aponte para o <strong>código de barras</strong> do produto na prateleira.</p>

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

<form id="form-manual" class="linha-form">
    <input id="ean" type="text" inputmode="numeric" pattern="[0-9]*"
           placeholder="ou digite o código" autocomplete="off">
    <button type="submit" class="botao botao-alt">Buscar</button>
</form>

<div id="resultado"></div>

<script src="/assets/scanner.js?v=2"></script>
<script>
(function () {
    const AUTO   = 'mercadinho:camera-auto';
    const video  = document.getElementById('video');
    const caixa  = document.getElementById('camera');
    const espera = document.getElementById('espera-texto');
    const dica   = document.getElementById('dica');
    const btnCam = document.getElementById('btn-camera');
    const btnLuz = document.getElementById('btn-luz');
    const campo  = document.getElementById('ean');
    const alvo   = document.getElementById('resultado');
    let leitor = null;
    let luzAcesa = false;

    function moeda(v) {
        return 'R$ ' + Number(v).toFixed(2).replace('.', ',');
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
        } catch (e) {
            leitor = null;
            dica.textContent = '';
            if (automatico) {
                espera.textContent = 'Toque em "Ligar câmera"';
                return;
            }
            espera.textContent = e.semPermissao ? 'Sem permissão de câmera' : 'Câmera indisponível';
            alvo.innerHTML = '<div class="aviso aviso-erro">' +
                (e.semPermissao ? e.message + '<br>' + Scanner.comoLiberar() : (e.message || 'Erro na câmera')) +
                '</div>';
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

    document.getElementById('form-manual').addEventListener('submit', (ev) => {
        ev.preventDefault();
        if (campo.value.trim()) buscar(campo.value.trim());
    });

    function aoBipar(codigo) {
        campo.value = codigo;
        if (navigator.vibrate) navigator.vibrate(60);
        caixa.classList.add('leu');
        setTimeout(() => caixa.classList.remove('leu'), 500);
        // A camera segue ligada: bipar o proximo produto e so apontar.
        buscar(codigo);
    }

    async function buscar(ean) {
        dica.textContent = 'Buscando ' + ean + '...';
        alvo.innerHTML = '<p class="ajuda">Buscando ' + ean + '...</p>';
        try {
            const r = await fetch('/api/produto?ean=' + encodeURIComponent(ean));
            const d = await r.json();
            dica.textContent = leitor ? 'Aponte para o próximo produto' : '';

            if (!d.encontrado) {
                alvo.innerHTML =
                    '<div class="cartao centro">' +
                    '<p class="grande">—</p>' +
                    '<p>' + (d.mensagem || 'Você nunca comprou esse produto.') + '</p>' +
                    '<p class="mono">' + (d.ean || ean) + '</p>' +
                    '<a class="botao" href="/manual?ean=' + encodeURIComponent(d.ean || ean) + '">Cadastrar compra</a>' +
                    '</div>';
                return;
            }

            let linhas = d.ultimas.map(u =>
                '<li><div class="linha-topo"><span class="forte">' + u.loja + '</span>' +
                '<span class="valor">' + moeda(u.unitario) + '</span></div>' +
                '<div class="linha-baixo"><span>' + u.data + '</span></div></li>'
            ).join('');

            alvo.innerHTML =
                '<div class="cartao">' +
                '<h2>' + d.descricao + '</h2>' +
                '<p class="mono">' + d.ean + '</p>' +
                '<div class="numeros">' +
                '<div class="numero"><strong>' + moeda(d.stats.ultimo) + '</strong><span>último</span></div>' +
                '<div class="numero"><strong>' + moeda(d.stats.min) + '</strong><span>menor</span></div>' +
                '<div class="numero"><strong>' + moeda(d.stats.max) + '</strong><span>maior</span></div>' +
                '</div>' +
                '<p class="ajuda">' + d.stats.n + ' compra(s) registradas</p>' +
                '</div>' +
                '<ul class="lista">' + linhas + '</ul>' +
                '<p class="centro"><a href="' + d.url + '">Ver histórico completo</a></p>';
        } catch (e) {
            dica.textContent = leitor ? 'Aponte para o próximo produto' : '';
            alvo.innerHTML = '<div class="aviso aviso-erro">Falha na busca: ' + e.message + '</div>';
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (!leitor) return;
        if (document.hidden) { leitor.pausar(); } else { leitor.retomar(); }
    });

    (async function abrirSozinha() {
        if (!querAutomatico()) return;
        const estadoPerm = await Scanner.permissao();
        if (estadoPerm === 'denied') {
            espera.textContent = 'Sem permissão de câmera';
            alvo.innerHTML = '<div class="aviso aviso-erro">' + Scanner.comoLiberar() + '</div>';
            return;
        }
        if (estadoPerm === 'granted' || estadoPerm === 'desconhecido') {
            ligar(true);
        }
    })();
})();
</script>
