/*
 * "Ver mais": o botao [data-ver-mais] mostra e esconde os .extra do
 * .recolhivel em volta dele. A classe .recolhido so entra por aqui, entao
 * sem JS a lista aparece inteira — nada fica preso escondido.
 */
(function (global) {
    'use strict';
    const doc = global.document;

    function preparar(raiz) {
        Array.prototype.forEach.call(raiz.querySelectorAll('.recolhivel'), (caixa) => {
            if (caixa.dataset.verMaisPronto) return;
            caixa.dataset.verMaisPronto = '1';
            if (caixa.querySelector('.extra')) {
                caixa.classList.add('recolhido');
            } else {
                // Nada a esconder: o botao nao tem o que fazer.
                const b = caixa.querySelector('[data-ver-mais]');
                if (b) b.hidden = true;
            }
        });
    }

    doc.addEventListener('click', (ev) => {
        const botao = ev.target.closest && ev.target.closest('[data-ver-mais]');
        if (!botao) return;
        const caixa = botao.closest('.recolhivel');
        if (!caixa) return;
        const aberto = !caixa.classList.toggle('recolhido');
        botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', () => preparar(doc));
    } else {
        preparar(doc);
    }
})(window);
