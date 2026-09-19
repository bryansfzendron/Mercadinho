/*
 * Puxar para atualizar.
 *
 * Instalado na tela de inicio do iPhone, o app roda em modo standalone e o
 * Safari **nao** oferece o gesto nativo de recarregar: nao ha barra de
 * endereco e o puxao so faz a borracha da rolagem. Aqui o gesto e refeito na
 * mao, e so nesse modo — no navegador comum o proprio Safari/Chrome ja resolve.
 *
 * Regras que evitam brigar com a rolagem normal:
 *  - so age quando a pagina ja esta no topo;
 *  - so age em movimento para baixo, com um dedo so;
 *  - enquanto arrasta, resiste cada vez mais em vez de parar de repente.
 *
 * E o puxao nao recarrega so a pagina: ele PEDE dados novos ao TouchPay
 * (estoque e vendas) e segura o indicador girando ate a coleta terminar.
 * Recarregar sem isso mostraria de novo exatamente os mesmos numeros, que e o
 * contrario do que o gesto promete. A trava contra martelar a API e do
 * servidor (a mesma do cron), nao daqui: quando as duas fontes estao dentro do
 * intervalo, a resposta diz "nada a fazer" e o puxao so recarrega.
 */
(function (global) {
    'use strict';

    const GATILHO = 70;     // px arrastados para valer o recarregamento
    const TETO = 130;       // px, o limite de que a borracha se aproxima
    const RESISTENCIA = 0.5;
    const ELASTICO = 0.55;  // quanto a borracha cede depois do gatilho

    /*
     * Ate quando esperar a coleta antes de recarregar assim mesmo.
     *
     * Uma carga grande do TouchPay passa disso com folga, e nao da para
     * prender o app ate ela acabar: passados os 22s a pagina recarrega com o
     * que ja chegou, e o resto entra na proxima. Vinte e dois porque um ciclo
     * curto de vendas costuma fechar em menos disso, e mais que isso o gesto
     * comeca a parecer travado.
     */
    const ESPERA_MAXIMA = 22000;
    const INTERVALO_PLACAR = 1500;

    /*
     * Quanto o indicador desce, para um dedo que andou `bruto` pixels.
     *
     * Ate o gatilho e o de sempre: metade do dedo, direto — e esse trecho que
     * o usuario esta lendo pra saber se ja deu, e mexer nele mudaria o gesto
     * que ele conhece. Depois do gatilho, o antigo travava seco em 130px, e
     * travar de repente le como app congelado.
     *
     * Dali em diante entra a borracha: cada pixel a mais rende menos que o
     * anterior, e o valor se *aproxima* do teto sem nunca encostar. E a mesma
     * conta da borracha de rolagem do iOS, e ela diz a verdade — "estou
     * respondendo, mas nao tem mais nada por aqui".
     */
    function borracha(bruto) {
        const direto = bruto * RESISTENCIA;
        if (direto <= GATILHO) {
            return direto;
        }
        const excesso = direto - GATILHO;
        const folga = TETO - GATILHO;
        return GATILHO + (excesso * folga * ELASTICO) / (folga + ELASTICO * excesso);
    }

    function ehStandalone() {
        // iOS usa navigator.standalone; o resto usa a media query.
        if (global.navigator && global.navigator.standalone === true) {
            return true;
        }
        return !!(global.matchMedia && global.matchMedia('(display-mode: standalone)').matches);
    }

    function montarIndicador() {
        const caixa = document.createElement('div');
        caixa.className = 'puxar';
        caixa.setAttribute('aria-hidden', 'true');
        caixa.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" ' +
            'stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
        document.body.appendChild(caixa);
        return caixa;
    }

    function iniciar() {
        if (!ehStandalone()) {
            return;
        }

        const indicador = montarIndicador();
        let inicioY = 0;
        let distancia = 0;
        let arrastando = false;
        let recarregando = false;

        function desenhar(d) {
            const proporcao = Math.min(d / GATILHO, 1);
            indicador.style.transform = 'translate(-50%, ' + d + 'px) rotate(' + (proporcao * 270) + 'deg)';
            indicador.style.opacity = String(Math.min(d / 40, 1));
            indicador.classList.toggle('puxar-pronto', d >= GATILHO);
        }

        /*
         * Volta pro lugar com mola, quando o puxao nao valeu.
         *
         * Zerar o transform e deixar o CSS voltar sozinho ja funcionava, mas a
         * volta saia com a mesma curva sempre, sem relacao com o gesto. A mola
         * parte da posicao em que o dedo largou e recolhe de la — e, se o dedo
         * voltar a puxar no meio da volta, ela e trocada de alvo em vez de
         * brigar com uma transicao pela metade.
         */
        let molaVolta = null;

        function soltar() {
            indicador.classList.remove('puxar-pronto');
            const Mov = global.Movimento;
            if (!Mov || Mov.menosMovimento()) {
                indicador.style.transform = '';
                indicador.style.opacity = '';
                return;
            }
            if (!molaVolta) {
                molaVolta = Mov.criarMola({
                    valor: distancia, resposta: 0.35, amortecimento: 1,
                    aoMudar: (v) => desenhar(v),
                });
            } else {
                molaVolta.fixar(distancia);
            }
            molaVolta.para(0, () => {
                indicador.style.transform = '';
                indicador.style.opacity = '';
            });
        }

        document.addEventListener('touchstart', (ev) => {
            if (recarregando || ev.touches.length !== 1) {
                return;
            }
            // So comeca valendo se a pagina ja estiver no topo.
            arrastando = (global.scrollY || document.documentElement.scrollTop || 0) <= 0;
            inicioY = ev.touches[0].clientY;
            distancia = 0;
            // Pegar de novo no meio da volta: quem manda passa a ser o dedo,
            // entao a mola para onde esta em vez de continuar puxando sozinha.
            if (molaVolta) {
                molaVolta.fixar(0);
            }
        }, { passive: true });

        document.addEventListener('touchmove', (ev) => {
            if (!arrastando || recarregando || ev.touches.length !== 1) {
                return;
            }
            const bruto = ev.touches[0].clientY - inicioY;
            if (bruto <= 0) {
                // Subindo: e rolagem normal, sai da frente.
                if (distancia > 0) {
                    soltar();
                    distancia = 0;
                }
                arrastando = false;
                return;
            }
            // Se a pagina saiu do topo no meio do caminho, desiste.
            if ((global.scrollY || document.documentElement.scrollTop || 0) > 0) {
                arrastando = false;
                soltar();
                return;
            }

            distancia = borracha(bruto);
            desenhar(distancia);

            // Sem isto o iOS faz a borracha da rolagem por cima do gesto.
            if (ev.cancelable) {
                ev.preventDefault();
            }
        }, { passive: false });

        function terminar() {
            if (!arrastando || recarregando) {
                arrastando = false;
                return;
            }
            arrastando = false;
            if (distancia >= GATILHO) {
                recarregando = true;
                indicador.classList.add('puxar-girando');
                api.atualizar();
                return;
            }
            soltar();
            distancia = 0;
        }

        document.addEventListener('touchend', terminar, { passive: true });
        document.addEventListener('touchcancel', terminar, { passive: true });
    }

    /** O token que as rotas de gravar exigem; o layout o poe no <head>. */
    function csrf() {
        const meta = document.querySelector('meta[name="csrf"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /** Pede as duas coletas. Devolve se alguma foi mesmo disparada. */
    async function pedirColeta() {
        const token = csrf();
        // Sem token e tela sem sessao: nao ha o que sincronizar.
        if (!token) {
            return false;
        }
        const r = await fetch('/api/sincronizar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        });
        const d = await r.json();
        return !!(d && d.disparou);
    }

    /** Segura ate as duas fontes pararem de rodar, ou ate estourar o prazo. */
    async function esperarColeta(prazo) {
        while (Date.now() < prazo) {
            await new Promise((ok) => setTimeout(ok, INTERVALO_PLACAR));
            const r = await fetch('/api/sync/estado');
            const d = await r.json();
            const rodando = (d.loja && d.loja.rodando) || (d.vendas && d.vendas.rodando);
            if (!rodando) {
                return;
            }
        }
    }

    const api = {
        iniciar,
        ehStandalone,
        GATILHO,
        /*
         * O puxao inteiro: pede os dados, espera o que der e recarrega.
         *
         * Recarregar e o ULTIMO passo e acontece sempre — rede caida, sessao
         * expirada ou coleta demorada nao podem deixar o indicador girando
         * para sempre. No pior caso o gesto faz o que sempre fez.
         */
        async atualizar() {
            try {
                if (await pedirColeta()) {
                    await esperarColeta(Date.now() + ESPERA_MAXIMA);
                }
            } catch (e) {
                /* sem rede ou sessao vencida: recarrega do mesmo jeito */
            }
            api.recarregar();
        },
        // Trocavel para o teste conseguir observar sem recarregar de verdade.
        recarregar() {
            global.location.reload();
        },
    };

    global.PuxarAtualizar = api;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})(window);
