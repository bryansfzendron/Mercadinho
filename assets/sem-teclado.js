/*
 * Nao abrir o teclado sozinho.
 *
 * Nenhuma tela usa autofocus, mas o iOS devolve o foco ao campo de texto
 * quando restaura a pagina do cache (voltar, trocar de aba, reabrir o app
 * instalado) — e o teclado sobe sem ninguem pedir. Ao carregar, ninguem
 * clicou em nada ainda, entao tirar o foco de um campo e sempre correto.
 * Quem tocar no campo continua abrindo o teclado normalmente.
 */
(function (global) {
    'use strict';

    function ehCampo(el) {
        if (!el || !el.tagName) return false;
        const tag = el.tagName.toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
    }

    function tirarFoco() {
        const ativo = document.activeElement;
        if (ehCampo(ativo) && typeof ativo.blur === 'function') {
            ativo.blur();
        }
    }

    global.SemTeclado = { tirarFoco, ehCampo };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tirarFoco);
    } else {
        tirarFoco();
    }
    // pageshow com persisted=true e a volta do cache do Safari, que e
    // justamente quando o foco reaparece sozinho.
    global.addEventListener('pageshow', tirarFoco);
})(window);
