/*
 * Leitor de camera do Mercadinho.
 *
 * Usa a BarcodeDetector nativa quando existe (Chrome/Android: rapida e precisa)
 * e cai no ZXing-js quando nao existe (Safari/iOS, Firefox).
 *
 * O stream da camera e aberto UMA vez e fica guardado no modulo: ligar e
 * desligar o leitor na mesma pagina reaproveita o mesmo stream, em vez de
 * chamar getUserMedia de novo. E isso que evita o navegador perguntar a
 * permissao varias vezes — no iOS cada getUserMedia novo pode virar um novo
 * pedido. Quem realmente encerra a camera e parar({ liberar: true }).
 *
 * **O ZXing recebe uma copia do stream, nunca o nosso.** O reset() dele para
 * as tracks do stream que recebeu, por conta propria — entao, entregando o
 * stream original, o cache acima morria no primeiro parar() e a promessa do
 * paragrafo anterior valia so no Android (onde o caminho e a BarcodeDetector).
 * No iPhone, que sempre cai no ZXing, cada leitura virava um pedido novo.
 * stream.clone() faz tracks independentes da *mesma* camera: o ZXing para as
 * dele, o original segue vivo, e a camera so fecha quando o ultimo morre.
 *
 * Uso:
 *   const leitor = await Scanner.iniciar(video, Scanner.QR, texto => {...});
 *   leitor.pausar(); leitor.retomar();
 *   leitor.lanterna(true);
 *   leitor.parar();                    // solta a camera
 *   leitor.parar({ liberar: false });  // guarda a camera para a proxima
 *   Scanner.liberar();                 // solta de fora (pagehide, ocioso)
 */
(function (global) {
    'use strict';

    const ZXING_CDN = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js';

    const QR = ['qr_code'];
    const BARRAS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf'];

    const RESTRICOES = {
        video: {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1280 },
            height: { ideal: 720 },
        },
        audio: false,
    };

    // Stream vivo, compartilhado entre as idas e vindas da mesma pagina.
    let streamAtual = null;

    function streamVivo() {
        return streamAtual && streamAtual.getVideoTracks().some(t => t.readyState === 'live');
    }

    async function pegarStream() {
        if (streamVivo()) {
            return streamAtual;
        }
        streamAtual = await navigator.mediaDevices.getUserMedia(RESTRICOES);
        // Foco continuo ajuda muito no codigo de barras de perto. Nem todo
        // aparelho aceita, e falhar aqui nao pode derrubar a leitura.
        try {
            const track = streamAtual.getVideoTracks()[0];
            const caps = track.getCapabilities ? track.getCapabilities() : {};
            if (caps.focusMode && caps.focusMode.includes('continuous')) {
                await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] });
            }
        } catch (e) { /* segue sem foco continuo */ }
        return streamAtual;
    }

    function liberarStream() {
        if (streamAtual) {
            streamAtual.getTracks().forEach(t => t.stop());
            streamAtual = null;
        }
    }

    /**
     * 'granted' | 'prompt' | 'denied' | 'desconhecido'
     * O Safari nao implementa a consulta para camera: devolve 'desconhecido'.
     */
    async function permissao() {
        if (streamVivo()) {
            return 'granted';
        }
        try {
            const r = await navigator.permissions.query({ name: 'camera' });
            return r.state;
        } catch (e) {
            return 'desconhecido';
        }
    }

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

    function mostrarNoVideo(video, stream) {
        video.srcObject = stream;
        video.setAttribute('playsinline', 'true');
        video.muted = true;
        return video.play();
    }

    /** Controles comuns aos dois motores de leitura. */
    function montarControle(video, encerrar, estado) {
        return {
            pausar() { estado.pausado = true; },
            retomar() { estado.pausado = false; },
            get pausado() { return estado.pausado; },

            lanternaDisponivel() {
                try {
                    const t = streamAtual && streamAtual.getVideoTracks()[0];
                    const caps = t && t.getCapabilities ? t.getCapabilities() : {};
                    return 'torch' in caps;
                } catch (e) {
                    return false;
                }
            },

            async lanterna(ligada) {
                const t = streamAtual && streamAtual.getVideoTracks()[0];
                if (!t) return false;
                try {
                    await t.applyConstraints({ advanced: [{ torch: !!ligada }] });
                    return true;
                } catch (e) {
                    return false;
                }
            },

            /**
             * Por padrao solta a camera. Com { liberar: false } o stream fica
             * vivo para a proxima leitura da mesma pagina, sem novo pedido de
             * permissao.
             */
            parar(opcoes) {
                estado.pausado = true;
                try { encerrar(); } catch (e) { /* ok */ }
                const liberar = !opcoes || opcoes.liberar !== false;
                if (liberar) {
                    liberarStream();
                    video.srcObject = null;
                }
            },
        };
    }

    async function viaNativo(video, formatos, aoLer) {
        const suportados = await formatosSuportados(formatos);
        if (!suportados) {
            return null;
        }

        const stream = await pegarStream();
        await mostrarNoVideo(video, stream);

        const detector = new global.BarcodeDetector({ formats: suportados });
        const estado = { pausado: false };
        let ativo = true;

        const timer = setInterval(async () => {
            if (!ativo || estado.pausado || video.readyState < 2) return;
            try {
                const achados = await detector.detect(video);
                if (achados && achados.length) {
                    aoLer(achados[0].rawValue);
                }
            } catch (e) { /* frame ruim, ignora */ }
        }, 200);

        return montarControle(video, () => {
            ativo = false;
            clearInterval(timer);
        }, estado);
    }

    async function viaZXing(video, aoLer) {
        await carregarScript(ZXING_CDN);
        if (!global.ZXing) {
            throw new Error('Leitor de codigos indisponivel.');
        }

        // Abrimos o stream aqui em vez de deixar o ZXing abrir: assim o modulo
        // continua dono da camera (lanterna, reaproveitamento, liberacao).
        const stream = await pegarStream();
        // E entregamos uma COPIA: o reset() do ZXing para as tracks do que
        // recebe, e com o original ele levava junto o cache do modulo — era
        // isso que fazia o iPhone pedir permissao a cada leitura. A copia
        // aponta pra mesma camera, entao o que o ZXing encerra e so a ponta
        // dele; a lanterna e a liberacao continuam falando com o original.
        const copia = stream.clone();
        const leitor = new global.ZXing.BrowserMultiFormatReader();
        const estado = { pausado: false };
        const aoDecodificar = (resultado) => {
            if (resultado && !estado.pausado) {
                aoLer(resultado.getText());
            }
        };

        if (typeof leitor.decodeFromStream === 'function') {
            await leitor.decodeFromStream(copia, video, aoDecodificar);
        } else {
            // Caminho de ZXing muito antigo: ele abre a propria camera e o
            // modulo perde o controle dela. Nao da pra reaproveitar nada aqui,
            // entao pelo menos a copia sem uso nao fica segurando a camera.
            copia.getTracks().forEach(t => t.stop());
            await leitor.decodeFromConstraints(RESTRICOES, video, aoDecodificar);
        }

        return montarControle(video, () => {
            // Leva as tracks da copia, nao as do stream do modulo.
            try { leitor.reset(); } catch (e) { /* ok */ }
            copia.getTracks().forEach(t => t.stop());
        }, estado);
    }

    function erroAmigavel(e) {
        const nome = e && e.name ? e.name : '';
        if (nome === 'NotAllowedError' || nome === 'SecurityError') {
            const erro = new Error('Permissão de câmera negada.');
            erro.semPermissao = true;
            return erro;
        }
        if (nome === 'NotFoundError' || nome === 'OverconstrainedError') {
            return new Error('Não achei uma câmera traseira neste aparelho.');
        }
        if (nome === 'NotReadableError') {
            return new Error('A câmera está ocupada por outro aplicativo. Feche os outros e tente de novo.');
        }
        return e instanceof Error ? e : new Error(String(e));
    }

    async function iniciar(video, formatos, aoLer) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error(
                'Este navegador não dá acesso à câmera. ' +
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

        try {
            if ('BarcodeDetector' in global) {
                const nativo = await viaNativo(video, formatos, filtrado);
                if (nativo) return nativo;
            }
            return await viaZXing(video, filtrado);
        } catch (e) {
            liberarStream();
            throw erroAmigavel(e);
        }
    }

    function ehIOS() {
        const ua = navigator.userAgent;
        return /iPad|iPhone|iPod/.test(ua)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function ehStandalone() {
        if (navigator.standalone === true) {
            return true;
        }
        return !!(global.matchMedia && global.matchMedia('(display-mode: standalone)').matches);
    }

    /** Dica de onde reabrir a permissao, por navegador. */
    function comoLiberar() {
        // Aberto pela tela de inicio nao existe barra de endereco, nem o aA,
        // nem "Configuracoes do Site": mandar o usuario ate la era mandar pra
        // um botao que nao esta na tela dele. O que vale nesse modo e outro
        // fato — a permissao dura enquanto o app continuar aberto.
        if (ehStandalone()) {
            return ehIOS()
                ? 'Aberto pela tela de início, o iPhone pergunta uma vez a cada vez que o app ' +
                  'é aberto — e não guarda a resposta. Deixando o app em segundo plano, em vez ' +
                  'de fechá-lo no seletor de apps, ele não pergunta de novo. Se você recusou ' +
                  'sem querer, feche o app e abra outra vez para a pergunta voltar.'
                : 'Toque em <strong>Ligar câmera</strong> de novo para o app pedir a permissão. ' +
                  'Se você recusou antes, libere a câmera nas configurações do app.';
        }
        if (ehIOS()) {
            return 'No iPhone: toque em <strong>aA</strong> na barra de endereço → ' +
                '<strong>Configurações do Site</strong> → <strong>Câmera</strong> → <strong>Permitir</strong>. ' +
                'Assim o Safari para de perguntar a cada visita.';
        }
        return 'No Chrome: toque no <strong>cadeado</strong> ao lado do endereço → ' +
            '<strong>Permissões</strong> → <strong>Câmera</strong> → <strong>Permitir</strong>.';
    }

    global.Scanner = {
        iniciar, permissao, comoLiberar, ehStandalone, QR, BARRAS,
        /**
         * Solta a camera de fora do leitor.
         *
         * Existe por causa do parar({ liberar: false }): quem guarda o stream
         * pra proxima leitura fica devendo o momento de largar de vez — sair
         * da pagina, esconder a aba, ou simplesmente ficar um tempo sem usar.
         */
        liberar: liberarStream,
    };
})(window);
