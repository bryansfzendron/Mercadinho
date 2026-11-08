/*
 * O cartao de vendas da capa.
 *
 * Tres coisas, so:
 *
 *  - a troca entre "Atual", "Semana" e "Mes", com a pilula deslizando de um
 *    lado para o outro em vez de apagar aqui e acender la;
 *  - tocar num dia do grafico (da semana ou do mes) para ver aquele dia;
 *    tocar de novo no dia ja escolhido volta para o periodo inteiro. No mes,
 *    arrastar o dedo de lado corre os dias, que la sao finos demais para
 *    acertar um por um;
 *  - a lista de produtos vendidos seguindo o que esta escolhido: hoje na aba
 *    "Atual", o periodo inteiro na aba "Semana" ou "Mes" e o dia escolhido
 *    quando ha um.
 *
 * Nenhum numero e calculado aqui, e nenhuma lista e montada aqui. O PHP manda
 * cada valor formatado em data-moeda e os blocos de produtos ja prontos;
 * este arquivo so escolhe qual aparece. Formatar dinheiro em dois lugares e
 * garantir que um dia os dois vao discordar.
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
       A lista de produtos vendidos.

       Um bloco por dia que o grafico deixa escolher, mais o da semana e o do
       mes inteiros, todos ja no HTML. Trocar e so esconder os outros e
       mostrar um — nada de rede, nada de montar linha no navegador.
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
       Um dia do grafico.

       Sem dia escolhido o cartao mostra o periodo inteiro (a semana, o mes),
       que e a resposta padrao. Escolher um dia troca o rotulo junto com o
       numero: "R$ 7,50" sozinho nao diz de quando e.

       Arrastar: o dedo que desce no grafico e corre de lado vai escolhendo
       o dia que estiver embaixo dele. So conta como arrasto se andou mais de
       lado do que para baixo — senao e a pagina rolando, e o navegador fica
       com o gesto (touch-action: pan-y no CSS).
       --------------------------------------------------------------- */
    const FOLGA = 8; // px de lado antes de virar arrasto

    function ligarGrafico(corpo, chavePeriodo) {
        const faixa = corpo ? corpo.querySelector('[data-barras]') : null;
        const rotulo = corpo ? corpo.querySelector('[data-rotulo]') : null;
        const valor = corpo ? corpo.querySelector('[data-valor]') : null;
        const colunas = faixa
            ? Array.prototype.slice.call(faixa.querySelectorAll('[data-data]'))
            : [];
        let atual = null;

        function escolher(coluna) {
            colunas.forEach((c) => {
                const essa = c === coluna;
                c.classList.toggle('ativo', essa);
                c.setAttribute('aria-pressed', essa ? 'true' : 'false');
            });
            atual = coluna;
            faixa.classList.toggle('escolhido', !!coluna);
            if (rotulo) rotulo.textContent = coluna ? coluna.dataset.dia : rotulo.dataset.padrao;
            if (valor) valor.textContent = coluna ? coluna.dataset.moeda : valor.dataset.padrao;
            mostrarVendidos(coluna ? coluna.dataset.data : chavePeriodo);
        }

        const api = { escolhido: () => (atual ? atual.dataset.data : null) };
        if (!faixa || !colunas.length) return api;

        // A coluna mais perto do dedo, mesmo que ele esteja um pouco fora do
        // grafico: arrastar alem da ponta fica no primeiro ou no ultimo dia.
        function colunaEm(x) {
            let melhor = null;
            let distancia = Infinity;
            colunas.forEach((c) => {
                const r = c.getBoundingClientRect();
                const d = Math.abs(x - (r.left + r.width / 2));
                if (d < distancia) { distancia = d; melhor = c; }
            });
            return melhor;
        }

        let x0 = 0;
        let y0 = 0;
        let apertado = false;
        let arrastando = false;
        let engolirClique = false;

        faixa.addEventListener('pointerdown', (ev) => {
            if (ev.pointerType === 'mouse' && ev.button !== 0) return;
            apertado = true;
            arrastando = false;
            x0 = ev.clientX;
            y0 = ev.clientY;
        });
        faixa.addEventListener('pointermove', (ev) => {
            if (!apertado) return;
            if (!arrastando) {
                const dx = Math.abs(ev.clientX - x0);
                if (dx <= FOLGA || dx < Math.abs(ev.clientY - y0)) return;
                arrastando = true;
                try { faixa.setPointerCapture(ev.pointerId); } catch (e) { /* sem captura, segue */ }
            }
            const c = colunaEm(ev.clientX);
            if (c && c !== atual) escolher(c);
        });
        faixa.addEventListener('pointerup', () => {
            if (arrastando) {
                // O clique que vem logo depois do arrasto nao pode desfazer o
                // dia em que o dedo parou.
                engolirClique = true;
                setTimeout(() => { engolirClique = false; }, 0);
            }
            apertado = false;
            arrastando = false;
        });
        faixa.addEventListener('pointercancel', () => { apertado = false; arrastando = false; });

        // Toque simples e teclado passam pelo clique do botao.
        colunas.forEach((coluna) => {
            coluna.addEventListener('click', () => {
                if (engolirClique) return;
                escolher(coluna === atual ? null : coluna);
            });
        });

        return api;
    }

    const graficos = {
        semana: ligarGrafico(painel.querySelector('[data-corpo="semana"]'), 'semana'),
        mes: ligarGrafico(painel.querySelector('[data-corpo="mes"]'), 'mes'),
    };

    /* ---------------------------------------------------------------
       Atual / Semana / Mes.
       --------------------------------------------------------------- */
    const abas = Array.prototype.slice.call(painel.querySelectorAll('.segmento-aba'));
    const pilula = painel.querySelector('.segmento-pilula');

    // A pilula tem exatamente a largura de uma aba (o CSS divide pelo --itens),
    // entao cada posicao fica a um multiplo de 100% dela mesma — nao precisa
    // medir nada em pixel nem saber quantas abas existem.
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

        // A lista segue a aba pelo nome dela. Voltando para "Atual" ela volta
        // para hoje: aquela aba fala do dia, e deixar na tela o sabado que se
        // estava olhando faria o numero de cima e a lista de baixo contarem
        // coisas diferentes.
        const qual = abas[indice] ? abas[indice].dataset.aba : 'atual';
        const grafico = graficos[qual];
        if (grafico) {
            mostrarVendidos(grafico.escolhido() || qual);
        } else {
            mostrarVendidos(hoje);
        }
    }

    abas.forEach((aba, i) => {
        aba.addEventListener('click', () => mostrar(i));
    });

})(window);
