<a class="voltar" href="/notas">‹ Notas</a>
<h1>Escanear nota</h1>
<p class="ajuda">Aponte para o <strong>QR Code</strong> impresso no cupom fiscal.</p>

<div class="camera-caixa" id="camera">
    <video id="video" muted playsinline></video>
    <div class="camera-espera" id="espera">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 9a2 2 0 0 1 2-2h1.6l1.2-2h6.4l1.2 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <circle cx="12" cy="13" r="3.4"/>
        </svg>
        <span id="espera-texto">Câmera desligada</span>
    </div>
    <div class="mira"><b></b><i></i></div>
    <p class="camera-dica" id="dica"></p>
</div>

<div class="acoes camera-acoes">
    <button id="btn-camera" class="botao">Ligar câmera</button>
    <button id="btn-luz" class="botao botao-alt botao-icone" hidden>Lanterna</button>
</div>

<div id="estado" class="estado"></div>

<details class="colar">
    <summary>Colar o link do QR Code na mão</summary>
    <p class="ajuda">Serve quando a câmera não coopera: abra o QR em outro leitor e cole a URL aqui.</p>
    <textarea id="qr-manual" rows="3" placeholder="https://www.nfce.fazenda.sp.gov.br/qrcode?p=..."></textarea>
    <button id="btn-enviar" class="botao botao-alt">Enviar</button>
</details>

<p class="centro"><a href="/manual">Nota sem QR Code? Lançar manualmente</a></p>

<script src="/assets/scanner.js?v=2"></script>
<script>
(function () {
    const CSRF    = <?= json_encode(csrf_token()) ?>;
    const AUTO    = 'mercadinho:camera-auto';
    const video   = document.getElementById('video');
    const caixa   = document.getElementById('camera');
    const espera  = document.getElementById('espera-texto');
    const dica    = document.getElementById('dica');
    const estado  = document.getElementById('estado');
    const btnCam  = document.getElementById('btn-camera');
    const btnLuz  = document.getElementById('btn-luz');
    const btnEnv  = document.getElementById('btn-enviar');
    const campo   = document.getElementById('qr-manual');
    let leitor = null;
    let enviando = false;
    let luzAcesa = false;

    function msg(texto, tipo) {
        estado.className = 'estado estado-' + (tipo || 'info');
        estado.innerHTML = texto;
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
            leitor = await Scanner.iniciar(video, Scanner.QR, aoLerQR);
            caixa.classList.add('ligada');
            btnCam.textContent = 'Desligar câmera';
            btnCam.classList.add('botao-alt');
            dica.textContent = 'Procurando o QR Code...';
            btnLuz.hidden = !leitor.lanternaDisponivel();
            lembrar(true);
        } catch (e) {
            leitor = null;
            dica.textContent = '';
            // Na tentativa automatica o erro fica quieto: alguns navegadores
            // exigem um toque antes de abrir a camera, e avisar "permissao
            // negada" ali seria mentira. O botao continua no lugar.
            if (automatico) {
                espera.textContent = 'Toque em "Ligar câmera"';
                return;
            }
            espera.textContent = e.semPermissao ? 'Sem permissão de câmera' : 'Câmera indisponível';
            msg(
                e.semPermissao
                    ? e.message + '<br>' + Scanner.comoLiberar()
                    : (e.message || 'Não consegui abrir a câmera.'),
                'erro'
            );
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
        if (leitor) {
            // Desligar na mao vale como preferencia: nao abre sozinha na proxima.
            lembrar(false);
            desligar();
            msg('');
            return;
        }
        ligar(false);
    });

    btnLuz.addEventListener('click', async () => {
        if (!leitor) return;
        luzAcesa = !luzAcesa;
        const ok = await leitor.lanterna(luzAcesa);
        if (!ok) { luzAcesa = false; btnLuz.hidden = true; return; }
        btnLuz.classList.toggle('botao-ligado', luzAcesa);
    });

    btnEnv.addEventListener('click', () => {
        const v = campo.value.trim();
        if (v) { enviar(v); }
    });

    function aoLerQR(texto) {
        // A camera continua ligada, so para de procurar: assim ler o proximo
        // cupom nao pede permissao de novo.
        if (leitor) leitor.pausar();
        if (navigator.vibrate) navigator.vibrate(60);
        caixa.classList.add('leu');
        setTimeout(() => caixa.classList.remove('leu'), 500);
        dica.textContent = 'QR Code lido';
        enviar(texto);
    }

    function liberado() {
        enviando = false;
        if (leitor) {
            leitor.retomar();
            dica.textContent = 'Procurando o QR Code...';
        }
    }

    async function enviar(qrcode) {
        if (enviando) return;
        enviando = true;
        msg('Consultando a SEFAZ... isso leva alguns segundos.');
        try {
            const r = await fetch('/api/notas', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ qrcode: qrcode })
            });
            const d = await r.json();

            if (!r.ok && !d.nota_id) {
                msg(d.erro || 'Não deu para registrar a nota.', 'erro');
                liberado();
                return;
            }
            if (d.duplicada) {
                msg('Essa nota já estava cadastrada. <a href="/notas/' + d.nota_id + '">Ver nota</a>', 'ok');
                liberado();
                return;
            }
            if (d.erro) {
                msg('Erro ao acionar o n8n: ' + d.erro, 'erro');
                liberado();
                return;
            }
            if (d.reprocessa) {
                msg('Essa nota tinha travado. Reprocessando...');
            }
            acompanhar(d.nota_id);
        } catch (e) {
            msg('Falha de rede: ' + e.message, 'erro');
            liberado();
        }
    }

    function acompanhar(notaId) {
        let tentativas = 0;
        const limite = 45; // ~90 segundos

        const timer = setInterval(async () => {
            tentativas++;
            try {
                const r = await fetch('/api/notas/' + notaId + '/status');
                const d = await r.json();

                if (d.status === 'ok') {
                    clearInterval(timer);
                    liberado();
                    msg('Pronto! ' + d.itens + ' itens de <strong>' + (d.loja || 'loja') + '</strong>. ' +
                        '<a href="/notas/' + notaId + '">Ver nota</a>', 'ok');
                    return;
                }
                if (d.status === 'erro') {
                    clearInterval(timer);
                    liberado();
                    msg('Deu erro: ' + (d.erro_msg || 'desconhecido') +
                        ' <a href="/notas/' + notaId + '">Ver nota</a>', 'erro');
                    return;
                }
                msg('Processando... (' + tentativas * 2 + 's)');
            } catch (e) { /* tenta de novo */ }

            if (tentativas >= limite) {
                clearInterval(timer);
                liberado();
                msg('Demorou demais. A nota ficou como pendente — ' +
                    '<a href="/notas/' + notaId + '">acompanhe aqui</a>.', 'erro');
            }
        }, 2000);
    }

    // Trocar de aba nao deve manter a camera ligada consumindo bateria.
    document.addEventListener('visibilitychange', () => {
        if (!leitor) return;
        if (document.hidden) { leitor.pausar(); } else if (!enviando) { leitor.retomar(); }
    });

    // Abre sozinha quando a permissao ja esta dada: e o caso comum depois da
    // primeira vez. So nao insiste se o usuario desligou de proposito.
    (async function abrirSozinha() {
        if (!querAutomatico()) return;
        const estadoPerm = await Scanner.permissao();
        if (estadoPerm === 'denied') {
            espera.textContent = 'Sem permissão de câmera';
            msg(Scanner.comoLiberar(), 'erro');
            return;
        }
        if (estadoPerm === 'granted' || estadoPerm === 'desconhecido') {
            ligar(true);
        }
    })();
})();
</script>
