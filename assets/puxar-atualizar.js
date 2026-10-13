/*
 * Puxar para baixo para sincronizar.
 *
 * O mesmo gesto do app Financas (public_html/financas/puxar.js): so dispara
 * com a pagina no topo, o arrasto e amortecido, e so depois de passar do
 * limiar (soltar antes cancela). O indicador e o <div id="puxar"> do layout;
 * o CSS #puxar tira o "elastico" do iPhone (overscroll-behavior-y: none), entao
 * o gesto vale tanto no app instalado quanto no navegador.
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

    const LIMIAR = 72;            // px de arrasto (ja amortecido) para disparar
    const amortece = (d) => Math.min(d * 0.5, 120);

    /*
     * Ate quando esperar a coleta antes de recarregar assim mesmo.
     *
     * Uma carga grande do TouchPay passa disso com folga, e nao da para
     * prender o app ate ela acabar: passados os 22s a pagina recarrega com o
     * que ja chegou, e o resto entra na proxima.
     */
    const ESPERA_MAXIMA = 22000;
    const INTERVALO_PLACAR = 1500;

    /** Quantas voltas o puxao da antes de desistir e recarregar assim mesmo. */
    const MAX_VOLTAS = 12;

    /** O token que as rotas de gravar exigem; o layout o poe no <head>. */
    function csrf() {
        const meta = document.querySelector('meta[name="csrf"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * A janela que esta tela quer reconferir, quando tem uma.
     *
     * So a tela de Vendas define — com o periodo que esta filtrado: filtra o
     * mes, arrasta para baixo, e aquele mes e rebuscado. Nas demais telas nao
     * ha janela, e o puxao atualiza o de sempre.
     */
    function janelaDaTela() {
        const j = global.SYNC_JANELA;
        return (j && j.de && j.ate) ? { de: j.de, ate: j.ate } : {};
    }

    /**
     * Pede a coleta ate ela acabar. Devolve se houve trabalho.
     *
     * A coleta acontece DENTRO da requisicao, e a hospedagem corta em 30s.
     * Entao o servidor trabalha alguns segundos, grava o que colheu e responde
     * `parcial: true`; aqui a gente volta e pede o resto.
     */
    async function pedirColeta() {
        const token = csrf();
        // Sem token e tela sem sessao: nao ha o que sincronizar.
        if (!token) {
            return false;
        }

        const corpo = JSON.stringify(janelaDaTela());
        let trabalhou = false;

        for (let volta = 0; volta < MAX_VOLTAS; volta++) {
            const r = await fetch('/api/sincronizar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                body: corpo,
            });
            const d = await Resposta.ler(r);
            trabalhou = true;
            if (!d || !d.parcial) {
                break;
            }
        }

        return trabalhou;
    }

    /** Segura ate as duas fontes pararem de rodar, ou ate estourar o prazo. */
    async function esperarColeta(prazo) {
        while (Date.now() < prazo) {
            await new Promise((ok) => setTimeout(ok, INTERVALO_PLACAR));
            const r = await fetch('/api/sync/estado');
            const d = await Resposta.ler(r);
            const rodando = (d.loja && d.loja.rodando) || (d.vendas && d.vendas.rodando);
            if (!rodando) {
                return;
            }
        }
    }

    const api = {
        LIMIAR,
        /*
         * O puxao inteiro: pede os dados, espera o que der e recarrega.
         * Falhando a rede, avisa e recolhe, como no Financas.
         */
        async atualizar() {
            if (await pedirColeta()) {
                await esperarColeta(Date.now() + ESPERA_MAXIMA);
            }
        },
        // Trocavel para o teste conseguir observar sem recarregar de verdade.
        recarregar() {
            global.location.reload();
        },
    };

    global.PuxarAtualizar = api;

    function iniciar() {
        const ind = document.getElementById('puxar');
        const txt = ind && ind.querySelector('span');
        if (!ind) return;

        let y0 = null, dist = 0, ocupado = false;

        const pos = (d, transicao) => {
            ind.style.transition = transicao ? 'transform .25s, opacity .25s' : 'none';
            ind.style.transform = `translate(-50%, ${d - 56}px)`;
            ind.style.opacity = Math.min(1, d / LIMIAR);
        };
        const recolher = () => { pos(0, true); ind.classList.remove('pronto', 'girando'); };

        async function sincronizar() {
            if (ocupado) return;
            ocupado = true;
            ind.classList.add('girando');
            pos(LIMIAR, true);
            txt.textContent = 'Sincronizando…';
            try {
                await api.atualizar();
                txt.textContent = 'Atualizado';
                setTimeout(() => api.recarregar(), 600);
            } catch (e) {
                // Com `http` o servidor respondeu (e falhou); sem, foi a rede.
                txt.textContent = e && e.http ? 'Falhou, tente de novo' : 'Sem conexão';
                setTimeout(() => { recolher(); ocupado = false; }, 1200);
            }
        }

        addEventListener('touchstart', (e) => {
            // So no topo da pagina e fora de menus abertos.
            y0 = (!ocupado && scrollY <= 0 && !e.target.closest('details[open]')) ? e.touches[0].clientY : null;
            dist = 0;
        }, { passive: true });

        addEventListener('touchmove', (e) => {
            if (y0 === null) return;
            dist = amortece(Math.max(0, e.touches[0].clientY - y0));
            if (dist <= 0) return;
            pos(dist, false);
            const pronto = dist >= LIMIAR;
            ind.classList.toggle('pronto', pronto);
            txt.textContent = pronto ? 'Solte para sincronizar' : 'Puxe para sincronizar';
        }, { passive: true });

        addEventListener('touchend', () => {
            if (y0 === null) return;
            y0 = null;
            if (dist >= LIMIAR) sincronizar(); else recolher();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})(window);
