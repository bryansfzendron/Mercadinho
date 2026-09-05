/*
 * Leitor de camera do Mercadinho.
 *
 * Usa a BarcodeDetector nativa quando existe (Chrome/Android: rapida e precisa)
 * e cai no ZXing-js quando nao existe (Safari/iOS, Firefox).
 *
 * Uso:
 *   const leitor = await Scanner.iniciar(videoEl, Scanner.QR, texto => {...});
 *   leitor.parar();
 */
(function (global) {
    'use strict';

    const ZXING_CDN = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js';

    const QR = ['qr_code'];
    const BARRAS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf'];

    function carregarScript(src) {
        return new Promise((ok, falha) => {
            if (document.querySelector('script[data-src="' + src + '"]')) {
                return ok();
            }
            const s = document.createElement('script');
            s.src = src;
            s.dataset.src = src;
            s.onload = () => ok();
            s.onerror = () => falha(new Error('Nao consegui carregar o leitor de codigos.'));
            document.head.appendChild(s);
        });
    }

    async function formatosSuportados(desejados) {
        try {
            const disp = await global.BarcodeDetector.getSupportedFormats();
            const f = desejados.filter(x => disp.includes(x));
            return f.length ? f : null;
        } catch (e) {
            return null;
        }
    }

    async function viaNativo(video, formatos, aoLer) {
        const suportados = await formatosSuportados(formatos);
        if (!suportados) {
            return null;
        }

        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } },
            audio: false
        });
        video.srcObject = stream;
        video.setAttribute('playsinline', 'true');
        await video.play();

        const detector = new global.BarcodeDetector({ formats: suportados });
        let ativo = true;

        const timer = setInterval(async () => {
            if (!ativo || video.readyState < 2) return;
            try {
                const achados = await detector.detect(video);
                if (achados && achados.length) {
                    aoLer(achados[0].rawValue);
                }
            } catch (e) { /* frame ruim, ignora */ }
        }, 250);

        return {
            parar() {
                ativo = false;
                clearInterval(timer);
                stream.getTracks().forEach(t => t.stop());
                video.srcObject = null;
            }
        };
    }

    async function viaZXing(video, aoLer) {
        await carregarScript(ZXING_CDN);
        if (!global.ZXing) {
            throw new Error('Leitor de codigos indisponivel.');
        }
        const leitor = new global.ZXing.BrowserMultiFormatReader();
        await leitor.decodeFromConstraints(
            { video: { facingMode: { ideal: 'environment' } }, audio: false },
            video,
            (resultado) => {
                if (resultado) {
                    aoLer(resultado.getText());
                }
            }
        );
        return {
            parar() {
                try { leitor.reset(); } catch (e) { /* ok */ }
            }
        };
    }

    async function iniciar(video, formatos, aoLer) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error(
                'Este navegador nao dá acesso à câmera. ' +
                'Confira se a página está em https e use Chrome ou Safari atualizado.'
            );
        }

        // Evita disparar o callback varias vezes com o mesmo codigo
        let ultimo = null;
        let ultimoEm = 0;
        const filtrado = (texto) => {
            const agora = Date.now();
            if (texto === ultimo && agora - ultimoEm < 2500) return;
            ultimo = texto;
            ultimoEm = agora;
            aoLer(texto);
        };

        if ('BarcodeDetector' in global) {
            const nativo = await viaNativo(video, formatos, filtrado);
            if (nativo) return nativo;
        }
        return viaZXing(video, filtrado);
    }

    global.Scanner = { iniciar, QR, BARRAS };
})(window);
