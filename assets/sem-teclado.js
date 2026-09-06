/*
 * Nao abrir o teclado sozinho.
 *
 * Nenhuma tela usa autofocus, mas o teclado subia mesmo assim em duas
 * situacoes:
 *
 *  1. o iOS devolve o foco ao campo ao restaurar a pagina do cache (voltar,
 *     trocar de app, reabrir o app instalado);
 *  2. depois de tocar em "Ligar camera" — o layout muda quando o video entra
 *     e o WebKit joga o foco no campo de texto mais proximo.
 *
 * Tirar o foco so no carregamento resolvia (1) e nao (2). A regra aqui e
 * geral: **um campo so fica focado se o dedo tocou nele**. Qualquer foco que
 * apareca sem toque (ou sem Tab, no teclado fisico) e desfeito na hora.
 */
(function (global) {
    'use strict';

    // Janela entre o toque e o foco que ele provoca. Curta o bastante para
    // nao adotar um foco que veio de outra coisa.
    const JANELA_MS = 700;

    let alvoDoToque = null;
    let momentoDoToque = 0;
    let veioDoTeclado = false;

    function ehCampo(el) {
        if (!el || !el.tagName) return false;
        const tag = el.tagName.toLowerCase();
        if (tag === 'input') {
            // Botao vestido de input nao abre teclado; deixa em paz.
            const tipo = (el.type || 'text').toLowerCase();
            return !['button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'image', 'range'].includes(tipo);
        }
        return tag === 'textarea' || tag === 'select' || el.isContentEditable === true;
    }

    /** O usuario pediu este foco? So se tocou no proprio campo, ou usou Tab. */
    function foiPedido(el) {
        if (veioDoTeclado) {
            return true;
        }
        if (!alvoDoToque || Date.now() - momentoDoToque > JANELA_MS) {
            return false;
        }
        if (alvoDoToque === el || (el.contains && el.contains(alvoDoToque))) {
            return true;
        }
        // Aqui os campos moram dentro do rotulo: tocar no texto foca o campo,
        // e isso e um foco pedido. label.control resolve tanto o rotulo que
        // envolve o campo quanto o que aponta com "for".
        const rotulo = alvoDoToque.closest ? alvoDoToque.closest('label') : null;
        return !!rotulo && rotulo.control === el;
    }

    function tirarFoco() {
        const ativo = document.activeElement;
        if (ehCampo(ativo) && typeof ativo.blur === 'function') {
            ativo.blur();
        }
    }

    function anotarToque(ev) {
        alvoDoToque = ev.target;
        momentoDoToque = Date.now();
        veioDoTeclado = false;
    }

    // pointerdown cobre toque e mouse; touchstart fica de reserva para
    // navegadores que nao disparam pointer events.
    document.addEventListener('pointerdown', anotarToque, { passive: true, capture: true });
    document.addEventListener('touchstart', anotarToque, { passive: true, capture: true });

    document.addEventListener('keydown', (ev) => {
        // Tab e navegacao legitima por teclado: o foco resultante e desejado.
        if (ev.key === 'Tab') {
            veioDoTeclado = true;
        }
    }, { capture: true });

    document.addEventListener('focusin', (ev) => {
        const el = ev.target;
        if (!ehCampo(el) || foiPedido(el)) {
            return;
        }
        // Fora do quadro atual: dar blur dentro do proprio focusin nao
        // segura o WebKit, que reaplica o foco logo depois.
        setTimeout(() => {
            if (document.activeElement === el && !foiPedido(el)) {
                el.blur();
            }
        }, 0);
    }, { capture: true });

    global.SemTeclado = { tirarFoco, ehCampo, foiPedido };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tirarFoco);
    } else {
        tirarFoco();
    }
    // persisted=true e a volta do cache do Safari, quando o foco reaparece.
    global.addEventListener('pageshow', tirarFoco);
})(window);
