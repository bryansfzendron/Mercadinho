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
 *  - enquanto arrasta, aplica resistencia (metade do dedo) e um teto.
 */
(function (global) {
    'use strict';

    const GATILHO = 70;     // px arrastados para valer o recarregamento
    const TETO = 130;       // px, o quanto o indicador desce no maximo
    const RESISTENCIA = 0.5;

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

        function soltar() {
            indicador.style.transform = '';
            indicador.style.opacity = '';
            indicador.classList.remove('puxar-pronto');
        }

        document.addEventListener('touchstart', (ev) => {
            if (recarregando || ev.touches.length !== 1) {
                return;
            }
            // So comeca valendo se a pagina ja estiver no topo.
            arrastando = (global.scrollY || document.documentElement.scrollTop || 0) <= 0;
            inicioY = ev.touches[0].clientY;
            distancia = 0;
        }, { passive: true });

        document.addEventListener('touchmove', (ev) => {
            if (!arrastando || recarregando || ev.touches.length !== 1) {
                return;
            }
            const bruto = ev.touches[0].clientY - inicioY;
            if (bruto <= 0) {
                // Subindo: e rolagem normal, sai da frente.
                if (distancia > 0) {
                    distancia = 0;
                    soltar();
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

            distancia = Math.min(bruto * RESISTENCIA, TETO);
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
                api.recarregar();
                return;
            }
            distancia = 0;
            soltar();
        }

        document.addEventListener('touchend', terminar, { passive: true });
        document.addEventListener('touchcancel', terminar, { passive: true });
    }

    const api = {
        iniciar,
        ehStandalone,
        GATILHO,
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
