/*
 * O cartao de vendas da capa.
 *
 * Tres coisas, so:
 *
 *  - a troca entre "Atual" e "Semana", com a pilula deslizando de um lado
 *    para o outro em vez de apagar aqui e acender la;
 *  - tocar num dia do grafico para ver aquele dia; tocar de novo (ou no dia
 *    ja escolhido) volta para a semana inteira;
 *  - a lista de produtos vendidos seguindo o que esta escolhido: hoje na aba
 *    "Atual", a semana inteira na aba "Semana" e o dia escolhido quando ha um.
 *
 * Nenhum numero e calculado aqui, e nenhuma lista e montada aqui. O PHP manda
 * cada valor formatado em data-moeda e os oito blocos de produtos ja prontos;
 * este arquivo so escolhe qual aparece. Formatar dinheiro em dois lugares e
 * garantir que um dia os dois vao discordar.
 */
(function (global) {
    'use strict';

    const doc = global.document;
    const painel = doc.querySelector('[data-painel]');
    if (!painel) return;

    const barras = painel.querySelector('.painel-barras');

    const Mov = global.Movimento;
    const menosMovimento = () =>
        Mov ? Mov.menosMovimento()
            : !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);

    /* ---------------------------------------------------------------
       A lista de produtos vendidos.

       Um bloco por dia da semana mais o da semana inteira, todos ja no HTML.
       Trocar e so esconder sete e mostrar um — nada de rede, nada de montar
       linha no navegador.
       --------------------------------------------------------------- */
    const caixaVendidos = doc.querySelector('[data-vendidos]');
    const blocos = caixaVendidos
        ? Array.prototype.slice.call(caixaVendidos.querySelectorAll('[data-lista]'))
        : [];
    const hoje = caixaVendidos ? caixaVendidos.dataset.hoje : '';

    function mostrarVendidos(chave) {
        blocos.forEach((b) => { b.hidden = b.dataset.lista !== chave; });
    }

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

        // Voltando para "Atual", a lista volta para hoje: aquela aba fala do
        // dia, e deixar na tela o sabado que se estava olhando faria o numero
        // de cima e a lista de baixo contarem coisas diferentes.
        if (indice === 0) {
            mostrarVendidos(hoje);
        } else {
            const escolhido = barras ? barras.querySelector('.painel-coluna.ativo') : null;
            mostrarVendidos(escolhido ? escolhido.dataset.data : 'semana');
        }
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
            mostrarVendidos('semana');
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
                mostrarVendidos(coluna.dataset.data);
            });
        });
    }
})(window);
