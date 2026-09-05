<h1>Escanear nota</h1>
<p class="ajuda">Aponte para o <strong>QR Code</strong> impresso no cupom fiscal.</p>

<div class="camera-caixa">
    <video id="video" muted playsinline></video>
    <div class="mira"></div>
</div>

<div id="estado" class="estado"></div>

<div class="acoes">
    <button id="btn-camera" class="botao">Ligar câmera</button>
</div>

<details class="colar">
    <summary>Colar o link do QR Code na mão</summary>
    <p class="ajuda">Serve quando a câmera não coopera: abra o QR em outro leitor e cole a URL aqui.</p>
    <textarea id="qr-manual" rows="3" placeholder="https://www.nfce.fazenda.sp.gov.br/qrcode?p=..."></textarea>
    <button id="btn-enviar" class="botao botao-alt">Enviar</button>
</details>

<p class="centro"><a href="/manual">Nota sem QR Code? Lançar manualmente</a></p>

<script src="/assets/scanner.js?v=1"></script>
<script>
(function () {
    const CSRF     = <?= json_encode(csrf_token()) ?>;
    const video    = document.getElementById('video');
    const estado   = document.getElementById('estado');
    const btnCam   = document.getElementById('btn-camera');
    const btnEnv   = document.getElementById('btn-enviar');
    const campo    = document.getElementById('qr-manual');
    let leitor = null;
    let enviando = false;

    function msg(texto, tipo) {
        estado.className = 'estado estado-' + (tipo || 'info');
        estado.innerHTML = texto;
    }

    async function pararCamera() {
        if (leitor) { leitor.parar(); leitor = null; }
        btnCam.textContent = 'Ligar câmera';
    }

    btnCam.addEventListener('click', async () => {
        if (leitor) { await pararCamera(); msg(''); return; }
        try {
            msg('Abrindo a câmera...');
            leitor = await Scanner.iniciar(video, Scanner.QR, aoLerQR);
            btnCam.textContent = 'Desligar câmera';
            msg('Procurando o QR Code...');
        } catch (e) {
            msg(e.message || 'Não consegui abrir a câmera.', 'erro');
        }
    });

    btnEnv.addEventListener('click', () => {
        const v = campo.value.trim();
        if (v) { enviar(v); }
    });

    function aoLerQR(texto) {
        pararCamera();
        enviar(texto);
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
                enviando = false;
                return;
            }
            if (d.duplicada) {
                msg('Essa nota já estava cadastrada. <a href="/notas/' + d.nota_id + '">Ver nota</a>', 'ok');
                enviando = false;
                return;
            }
            if (d.erro) {
                msg('Erro ao acionar o n8n: ' + d.erro, 'erro');
                enviando = false;
                return;
            }
            acompanhar(d.nota_id);
        } catch (e) {
            msg('Falha de rede: ' + e.message, 'erro');
            enviando = false;
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
                    enviando = false;
                    msg('Pronto! ' + d.itens + ' itens de <strong>' + (d.loja || 'loja') + '</strong>. ' +
                        '<a href="/notas/' + notaId + '">Ver nota</a>', 'ok');
                    return;
                }
                if (d.status === 'erro') {
                    clearInterval(timer);
                    enviando = false;
                    msg('Deu erro: ' + (d.erro_msg || 'desconhecido') +
                        ' <a href="/notas/' + notaId + '">Ver nota</a>', 'erro');
                    return;
                }
                msg('Processando... (' + tentativas * 2 + 's)');
            } catch (e) { /* tenta de novo */ }

            if (tentativas >= limite) {
                clearInterval(timer);
                enviando = false;
                msg('Demorou demais. A nota ficou como pendente — ' +
                    '<a href="/notas/' + notaId + '">acompanhe aqui</a>.', 'erro');
            }
        }, 2000);
    }
})();
</script>
