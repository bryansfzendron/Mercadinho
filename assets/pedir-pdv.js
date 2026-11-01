/*
 * O botao Repor pergunta o ponto de venda antes de abrir a tela.
 *
 * O toque em qualquer link com data-pedir-pdv (a aba Repor da Loja) abre a
 * folha #folha-pdv por cima da tela atual, em vez de navegar. Cada PDV da
 * folha e um link para /planograma?pdv=ID, que ja abre no PDV escolhido.
 *
 * Sem a folha na pagina, ou num navegador sem <dialog>, nada e interceptado:
 * o link vai para /planograma e a propria tela faz a pergunta.
 */
(function (global) {
    'use strict';

    const doc = global.document;
    const LEMBRAR_PDV = 'mercadinho:planograma-pdv';

    function marcarUltimo(folha) {
        let guardado = null;
        try { guardado = global.localStorage.getItem(LEMBRAR_PDV); } catch (e) { /* modo privado */ }
        Array.prototype.forEach.call(folha.querySelectorAll('a[data-pdv]'), (a) => {
            const m = a.querySelector('.linha-baixo');
            if (m) m.hidden = a.getAttribute('data-pdv') !== guardado;
        });
    }

    doc.addEventListener('click', (ev) => {
        const link = ev.target.closest && ev.target.closest('a[data-pedir-pdv]');
        if (!link) return;
        const folha = doc.getElementById('folha-pdv');
        if (!folha || typeof folha.showModal !== 'function') return;
        ev.preventDefault();
        marcarUltimo(folha);
        folha.showModal();
        // O foco vai para o titulo, nao para o primeiro PDV: com o anel em volta
        // ele parecia ja escolhido.
        const titulo = folha.querySelector('h2');
        if (titulo) titulo.focus();
    });

    // Tocar fora da folha (no fundo escurecido) fecha, como no iOS.
    doc.addEventListener('click', (ev) => {
        const folha = doc.getElementById('folha-pdv');
        if (folha && ev.target === folha) folha.close();
    });

    // Voltar para esta pagina pelo historico nao pode trazer a folha aberta.
    global.addEventListener('pageshow', () => {
        const folha = doc.getElementById('folha-pdv');
        if (folha && folha.open) folha.close();
    });
})(window);
