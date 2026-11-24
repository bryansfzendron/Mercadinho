/* Promocoes: botao para ocultar/mostrar o cartao "Vencidos na prateleira" (lembra a escolha neste aparelho). */
(function () {
    'use strict';
    const botao = document.getElementById('pv-alterna');
    const cartao = document.getElementById('pv-cartao');
    if (!botao || !cartao) return;
    const CHAVE = 'mercadinho:ocultar-vencidos';
    const n = botao.dataset.total;

    function aplicar(oculto) {
        cartao.hidden = oculto;
        botao.textContent = (oculto ? 'Mostrar' : 'Ocultar') + ' vencidos (' + n + ')';
    }
    let oculto = false;
    try { oculto = localStorage.getItem(CHAVE) === '1'; } catch (e) {}
    aplicar(oculto);
    botao.addEventListener('click', function () {
        oculto = !oculto;
        aplicar(oculto);
        try { localStorage.setItem(CHAVE, oculto ? '1' : '0'); } catch (e) {}
    });
})();
