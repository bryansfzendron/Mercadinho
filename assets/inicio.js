/*
 * O cartao de vendas da capa.
 *
 * Duas coisas, so:
 *
 *  - a troca entre "Atual" e "Semana", com a pilula deslizando de um lado
 *    para o outro em vez de apagar aqui e acender la;
 *  - tocar num dia do grafico para ver aquele dia; tocar de novo (ou no dia
 *    ja escolhido) volta para a semana inteira.
 *
 * Nenhum numero e calculado aqui. O PHP ja manda cada valor formatado em
 * data-moeda, porque formatar dinheiro em dois lugares e garantir que um dia
 * os dois vao discordar.
 */
(function (global) {
    'use strict';

    const doc = global.document;
    const painel = doc.querySelector('[data-painel]');
    if (!painel) return;

    const Mov = global.Movimento;
    const menosMovimento = () =>
        Mov ? Mov.menosMovimento()
            : !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);

    /* ---------------------------------------------------------------
       Atual / Semana.
       --------------------------------------------------------------- */
    const abas = Array.prototype.slice.call(painel.querySelectorAll('.segmento-aba'));
    const pilula = painel.querySelector('.segmento-pilula');

    // A pilula tem exatamente a largura de uma metade, entao a segunda posicao
    // fica a 100% dela mesma — nao precisa medir nada em pixel.
    let molaPilula = null;
    function moverPilula(indice) {
        const destino = indice * 100;
        if (!pilula) return;
        if (menosMovimento() || !Mov) {
            pilula.style.transform = 'translateX(' + destino + '%)';
            return;
        }
        if (!molaPilula) {
            molaPilula = Mov.criarMola({
                valor: destino,
                resposta: 0.35,
                amortecimento: 1,
                aoMudar: (v) => { pilula.style.transform = 'translateX(' + v + '%)'; },
            });
        }
        molaPilula.para(destino);
    }

    function mostrar(indice) {
        abas.forEach((aba, i) => {
            const escolhida = i === indice;
            aba.classList.toggle('ativo', escolhida);
            aba.setAttribute('aria-selected', escolhida ? 'true' : 'false');
            const corpo = painel.querySelector('[data-corpo="' + aba.dataset.aba + '"]');
            if (corpo) corpo.hidden = !escolhida;
        });
        moverPilula(indice);
    }

    abas.forEach((aba, i) => {
        aba.addEventListener('click', () => mostrar(i));
    });

    /* ---------------------------------------------------------------
       Um dia da semana.

       Sem dia escolhido o cartao mostra a semana inteira, que e a resposta
       padrao. Escolher um dia troca o rotulo junto com o numero: "R$ 7,50"
       sozinho nao diz de quando e.
       --------------------------------------------------------------- */
    const barras = painel.querySelector('.painel-barras');
    const rotulo = painel.querySelector('[data-semana-rotulo]');
    const valor = painel.querySelector('[data-semana-valor]');

    if (barras && rotulo && valor) {
        const colunas = Array.prototype.slice.call(barras.querySelectorAll('.painel-coluna'));

        function limpar() {
            colunas.forEach((c) => {
                c.classList.remove('ativo');
                c.setAttribute('aria-pressed', 'false');
            });
            barras.classList.remove('escolhido');
            rotulo.textContent = rotulo.dataset.padrao;
            valor.textContent = valor.dataset.padrao;
        }

        colunas.forEach((coluna) => {
            coluna.addEventListener('click', () => {
                if (coluna.classList.contains('ativo')) {
                    limpar();
                    return;
                }
                colunas.forEach((c) => {
                    c.classList.remove('ativo');
                    c.setAttribute('aria-pressed', 'false');
                });
                coluna.classList.add('ativo');
                coluna.setAttribute('aria-pressed', 'true');
                barras.classList.add('escolhido');
                rotulo.textContent = coluna.dataset.dia;
                valor.textContent = coluna.dataset.moeda;
            });
        });
    }
})(window);
