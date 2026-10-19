/*
 * Produto vendido que abre e mostra onde (e, na tela de vendas, quando) saiu.
 *
 * A linha e um <details data-detalhe="/vendas/produto?..."> e o miolo vem do
 * servidor so na primeira vez que ela abre — montar de antemao os dias de
 * cada produto de um ano filtrado pesaria a tela inteira para responder a
 * pergunta de um produto so.
 *
 * O pedido sai no toque (pointerdown), antes do clique: na maioria das vezes
 * o miolo chega enquanto a sanfona ainda esta abrindo. Quando chega depois,
 * a altura cresce com a mesma mola do movimento.js em vez de pular.
 */
(function (global) {
    'use strict';

    const doc = global.document;

    function crescer(caixa, antes) {
        const Mov = global.Movimento;
        if (!caixa.open || caixa.dataset.fechando || !Mov || Mov.menosMovimento()) return;

        const fim = () => {
            caixa.classList.remove('animando');
            caixa.style.height = '';
        };
        const jaAnimando = caixa.classList.contains('animando');
        if (!jaAnimando) caixa.style.height = antes + 'px';
        caixa.classList.add('animando');

        let mola = caixa.molaAltura;
        if (!mola) {
            mola = Mov.criarMola({
                valor: antes,
                resposta: 0.3,
                amortecimento: 1,
                aoMudar: (v) => { caixa.style.height = v + 'px'; },
            });
            caixa.molaAltura = mola;
        } else if (!jaAnimando) {
            mola.fixar(antes, 0);
        }
        mola.para(caixa.scrollHeight, fim);
    }

    function carregar(caixa) {
        if (caixa.dataset.carregado) return;
        caixa.dataset.carregado = '1';
        const corpo = caixa.querySelector('[data-detalhe-corpo]');
        if (!corpo) return;

        global.fetch(caixa.dataset.detalhe, { credentials: 'same-origin' })
            .then((r) => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then((html) => {
                const antes = caixa.getBoundingClientRect().height;
                corpo.innerHTML = html;
                crescer(caixa, antes);
            })
            .catch(() => {
                // Deixa tentar de novo no proximo toque.
                delete caixa.dataset.carregado;
                corpo.innerHTML = '<p class="ajuda">Não deu para carregar. Feche e abra de novo.</p>';
            });
    }

    function caixaDo(ev) {
        const resumo = ev.target.closest ? ev.target.closest('summary') : null;
        const caixa = resumo ? resumo.parentElement : null;
        return caixa && caixa.matches('details[data-detalhe]') ? caixa : null;
    }

    doc.addEventListener('pointerdown', (ev) => {
        const caixa = caixaDo(ev);
        if (caixa) carregar(caixa);
    }, { passive: true });

    // Captura: roda antes do movimento.js, enquanto o open ainda diz se o
    // toque e para abrir ou para fechar.
    doc.addEventListener('click', (ev) => {
        const caixa = caixaDo(ev);
        if (!caixa) return;
        caixa.dataset.fechando = caixa.open ? '1' : '';
        carregar(caixa);
    }, true);
})(window);
