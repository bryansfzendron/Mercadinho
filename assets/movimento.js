/*
 * Movimento.
 *
 * O visual do app ja era de vidro; o que faltava era ele *se mexer* como
 * coisa fisica. Este arquivo e a camada de interacao:
 *
 *  - um motor de molas (amortecimento + resposta, do jeito que a Apple
 *    parametriza), que anima a partir do valor que esta na tela e pode ser
 *    agarrado e redirecionado no meio do caminho;
 *  - resposta ao toque no *apertar*, nao no soltar;
 *  - sanfonas (<details>) que abrem com altura animada em vez de pular;
 *  - aviso que sai arrastando pro lado, com a inercia do dedo;
 *  - borda do topo que so aparece quando ha conteudo passando por baixo.
 *
 * Nada aqui depende de biblioteca: sao ~200 linhas e um requestAnimationFrame.
 * Uma biblioteca de animacao custaria mais bytes do que o app inteiro de CSS.
 */
(function (global) {
    'use strict';

    const doc = global.document;
    const menosMovimento = () =>
        !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);

    /* ---------------------------------------------------------------
       Motor de molas.

       Mola nao tem duracao: tem *resposta* (em quanto tempo alcanca o alvo)
       e *amortecimento* (1 = chega sem passar do ponto, abaixo de 1 = passa
       e volta). Foi assim que a Apple trocou massa/rigidez/atrito por dois
       numeros que da pra escolher olhando, e e por isso que os valores de
       referencia (0,4s pra mover, 0,3s pra gaveta) fazem sentido.

       O ganho real nao e a curva: e poder trocar o alvo no meio do movimento
       sem cortar a velocidade. Uma transicao de CSS, agarrada na metade,
       salta; a mola so muda de destino e segue.
       --------------------------------------------------------------- */
    const PADRAO = { amortecimento: 1, resposta: 0.4 };

    function criarMola(opcoes) {
        const conf = Object.assign({}, PADRAO, opcoes);
        let valor = conf.valor || 0;
        let alvo = valor;
        let velocidade = 0;
        let quadro = 0;
        let ultimo = 0;
        let aoTerminar = null;

        // omega0 = 2*pi/resposta e a frequencia natural; dela saem a rigidez
        // (omega0 ao quadrado) e o atrito (2 * amortecimento * omega0), com
        // massa 1.
        const w0 = (2 * Math.PI) / conf.resposta;
        const k = w0 * w0;
        const c = 2 * conf.amortecimento * w0;
        const precisao = conf.precisao || 0.35;
        const precisaoVel = precisao * 12;

        function passo(agora) {
            quadro = 0;
            // Teto de 32ms: voltar de segundo plano nao pode integrar meio
            // segundo de uma vez, senao a mola explode.
            const dt = Math.min((agora - ultimo) / 1000, 0.032);
            ultimo = agora;

            // Subpassos fixos: com o dt do quadro cru, uma resposta curta
            // (mola dura) fica instavel em tela de 60Hz.
            const n = Math.max(1, Math.ceil(dt / 0.004));
            const h = dt / n;
            for (let i = 0; i < n; i++) {
                const forca = -k * (valor - alvo) - c * velocidade;
                velocidade += forca * h;
                valor += velocidade * h;
            }

            conf.aoMudar(valor, velocidade);

            // Parada pequena demais pra alguem ver: encaixa no alvo e encerra.
            if (Math.abs(valor - alvo) < precisao && Math.abs(velocidade) < precisaoVel) {
                valor = alvo;
                velocidade = 0;
                conf.aoMudar(valor, 0);
                const fim = aoTerminar;
                aoTerminar = null;
                if (fim) fim();
                return;
            }
            quadro = global.requestAnimationFrame(passo);
        }

        return {
            /*
             * Novo destino. A velocidade atual e mantida de proposito: e ela
             * que faz um gesto revertido continuar liso em vez de bater numa
             * parede. Passe velocidadeInicial pra entregar a velocidade do
             * dedo no instante em que ele soltou.
             */
            para(novoAlvo, fim, velocidadeInicial) {
                alvo = novoAlvo;
                aoTerminar = fim || null;
                if (typeof velocidadeInicial === 'number') {
                    velocidade = velocidadeInicial;
                }
                if (menosMovimento()) {
                    if (quadro) { global.cancelAnimationFrame(quadro); quadro = 0; }
                    valor = alvo;
                    velocidade = 0;
                    conf.aoMudar(valor, 0);
                    const f = aoTerminar;
                    aoTerminar = null;
                    if (f) f();
                    return;
                }
                if (!quadro) {
                    ultimo = global.performance.now();
                    quadro = global.requestAnimationFrame(passo);
                }
            },
            /* Reposiciona sem animar: usado enquanto o gesto esta no comando. */
            fixar(v, vel) {
                if (quadro) { global.cancelAnimationFrame(quadro); quadro = 0; }
                valor = v;
                alvo = v;
                velocidade = typeof vel === 'number' ? vel : 0;
                aoTerminar = null;
            },
            get valor() { return valor; },
            get velocidade() { return velocidade; },
        };
    }

    /*
     * Onde um arremesso pararia sozinho, se fosse desacelerando.
     *
     * E a mesma conta da rolagem do iOS. Serve pra decidir o destino pela
     * *intencao* do gesto, nao pelo pixel onde o dedo saiu: um peteleco curto
     * e rapido joga o item pra longe, como jogaria na vida real.
     */
    function projetar(velocidade, desaceleracao) {
        const d = desaceleracao || 0.998;
        return (velocidade / 1000) * d / (1 - d);
    }

    /* ---------------------------------------------------------------
       Resposta ao toque.

       Regra: acende no *apertar*, confirma no soltar. Esperar o clique pra
       dar sinal e o que faz uma tela parecer morta. Arrastar pra fora
       cancela (e voltar acende de novo), que e o que o iOS faz e o que
       deixa desistir de um toque sem medo.
       --------------------------------------------------------------- */
    const TOCAVEIS = [
        '.lista li a', '.linha-cartao', '.chip', '.aba', '.barra a',
        '.compra > summary', '.item-editor > summary', '.colar > summary',
        '.engrenagem', '.voltar',
    ].join(', ');

    const FOLGA = 10; // px de tolerancia antes de virar arrasto

    function ligarToque() {
        let alvo = null;
        let x0 = 0;
        let y0 = 0;

        function apagar() {
            if (alvo) alvo.classList.remove('tocando');
            alvo = null;
        }

        doc.addEventListener('pointerdown', (ev) => {
            if (ev.pointerType === 'mouse' && ev.button !== 0) return;
            const el = ev.target.closest ? ev.target.closest(TOCAVEIS) : null;
            if (!el) return;
            alvo = el;
            x0 = ev.clientX;
            y0 = ev.clientY;
            el.classList.add('tocando');
        }, { passive: true, capture: true });

        doc.addEventListener('pointermove', (ev) => {
            if (!alvo) return;
            const longe = Math.abs(ev.clientX - x0) > FOLGA || Math.abs(ev.clientY - y0) > FOLGA;
            // Rolar a lista nao pode deixar um rastro de linhas acesas.
            alvo.classList.toggle('tocando', !longe);
        }, { passive: true });

        doc.addEventListener('pointerup', apagar, { passive: true });
        doc.addEventListener('pointercancel', apagar, { passive: true });
        // A rolagem por inercia rouba o ponteiro sem mandar pointerup.
        doc.addEventListener('scroll', apagar, { passive: true, capture: true });
    }

    /* ---------------------------------------------------------------
       Sanfonas (<details>).

       O <details> nativo troca de altura num quadro so. Aqui a altura vira
       uma mola: abre crescendo e fecha encolhendo, e um toque no meio do
       caminho so troca o alvo — a mola continua da altura que esta na tela,
       sem pulo.

       Resposta 0,3 e o valor de gaveta; amortecimento 1 porque nada aqui veio
       de arremesso, e passar do ponto sem gesto por tras parece defeito.
       --------------------------------------------------------------- */
    function ligarSanfonas() {
        doc.addEventListener('click', (ev) => {
            const resumo = ev.target.closest ? ev.target.closest('summary') : null;
            if (!resumo) return;
            const caixa = resumo.parentElement;
            if (!caixa || caixa.tagName !== 'DETAILS') return;
            if (menosMovimento()) return;

            const fechada = resumo.getBoundingClientRect().height;
            const atual = caixa.getBoundingClientRect().height;
            const abrindo = !caixa.open;

            // O <details> nativo *inverte* o open depois que os handlers rodam.
            // Sem cancelar, abrir na mao aqui viraria fechar logo em seguida —
            // quem manda no open passa a ser este arquivo, do inicio ao fim.
            ev.preventDefault();

            if (abrindo) {
                // Abre de verdade primeiro: so com o conteudo no fluxo da pra
                // medir onde a altura tem que chegar.
                caixa.open = true;
            }

            caixa.classList.add('animando');
            const destino = abrindo ? caixa.scrollHeight : fechada;

            let mola = caixa.molaAltura;
            if (!mola) {
                mola = criarMola({
                    valor: atual,
                    resposta: 0.3,
                    amortecimento: 1,
                    aoMudar: (v) => { caixa.style.height = v + 'px'; },
                });
                caixa.molaAltura = mola;
            } else {
                // Interrompida no meio: parte da altura que o olho ve agora,
                // levando junto a velocidade que ela ja tinha.
                mola.fixar(atual, mola.velocidade);
            }

            mola.para(destino, () => {
                caixa.classList.remove('animando');
                caixa.style.height = '';
                if (!abrindo) caixa.open = false;
            });
        });
    }

    /* ---------------------------------------------------------------
       Aviso que se joga pro lado.

       Um recado de "nota importada" nao deveria exigir mirar num X. Ele entra
       com mola e sai no empurrao: durante o arrasto acompanha o dedo 1:1, e
       no soltar a inercia decide — peteleco curto e rapido ja manda embora,
       dedo parado no meio do caminho volta pro lugar.
       --------------------------------------------------------------- */
    function ligarAvisos() {
        doc.querySelectorAll('.aviso').forEach((aviso) => {
            if (!menosMovimento()) {
                // Entra subindo um pouco, com a mesma mola de gaveta. Opacidade
                // pede precisao menor: 0,35 num valor que vai de 0 a 1 cortaria
                // a animacao antes de comecar.
                const entrada = criarMola({
                    valor: 0, resposta: 0.35, amortecimento: 1, precisao: 0.004,
                    aoMudar: (v) => {
                        aviso.style.transform = 'translateY(' + (1 - v) * -10 + 'px)';
                        aviso.style.opacity = String(v);
                    },
                });
                entrada.para(1, () => {
                    aviso.style.transform = '';
                    aviso.style.opacity = '';
                });
            }

            let x = 0;
            let arrastando = false;
            let pegouEm = 0;
            let historico = [];
            const largura = () => aviso.getBoundingClientRect().width || 1;

            function desenhar(v) {
                x = v;
                aviso.style.transform = 'translateX(' + v + 'px)';
                // Some conforme sai: o sumico e a previa do que vai acontecer.
                aviso.style.opacity = String(Math.max(0, 1 - Math.abs(v) / largura()));
            }

            const mola = criarMola({
                valor: 0, resposta: 0.4, amortecimento: 1,
                aoMudar: (v) => desenhar(v),
            });

            function sumir() {
                const altura = aviso.getBoundingClientRect().height;
                const margem = parseFloat(getComputedStyle(aviso).marginBottom) || 0;
                aviso.style.overflow = 'hidden';
                // A linha em branco que sobra tambem tem que fechar, senao o
                // resto da tela so pula depois — duas coisas em vez de uma.
                const encolher = criarMola({
                    valor: altura + margem, resposta: 0.3, amortecimento: 1,
                    aoMudar: (v) => {
                        const total = Math.max(0, v);
                        aviso.style.height = Math.max(0, total - margem) + 'px';
                        aviso.style.marginBottom = Math.min(margem, total) + 'px';
                        aviso.style.paddingTop = '0px';
                        aviso.style.paddingBottom = '0px';
                    },
                });
                encolher.para(0, () => aviso.remove());
            }

            function soltar(ev) {
                if (!arrastando) return;
                arrastando = false;
                if (ev && ev.pointerId != null && aviso.hasPointerCapture(ev.pointerId)) {
                    aviso.releasePointerCapture(ev.pointerId);
                }
                // Velocidade da ponta do gesto, nao da media dele: o que
                // importa e pra onde o dedo estava indo quando saiu.
                const a = historico[0];
                const b = historico[historico.length - 1];
                const dt = Math.max(b.t - a.t, 1);
                const vel = ((b.x - a.x) / dt) * 1000;

                const parada = x + projetar(vel);
                if (Math.abs(parada) > largura() * 0.5) {
                    const fora = Math.sign(parada || vel || 1) * largura() * 1.2;
                    // A velocidade do dedo entra na mola: sem isso da pra ver
                    // a emenda entre arrastar e voar.
                    mola.para(fora, sumir, vel);
                } else {
                    mola.para(0, null, vel);
                }
            }

            aviso.addEventListener('pointerdown', (ev) => {
                if (ev.pointerType === 'mouse' && ev.button !== 0) return;
                aviso.setPointerCapture(ev.pointerId);
                arrastando = true;
                pegouEm = ev.clientX - x; // respeita onde o dedo pegou
                historico = [{ x: ev.clientX, t: ev.timeStamp }];
                mola.fixar(x);
            });

            aviso.addEventListener('pointermove', (ev) => {
                if (!arrastando) return;
                historico.push({ x: ev.clientX, t: ev.timeStamp });
                if (historico.length > 5) historico.shift();
                desenhar(ev.clientX - pegouEm);
            });

            aviso.addEventListener('pointerup', soltar);
            aviso.addEventListener('pointercancel', soltar);
        });
    }

    /* ---------------------------------------------------------------
       Borda do topo por rolagem.

       Um risco fixo embaixo do cabecalho aparece mesmo quando nao ha nada
       passando por baixo dele — vira decoracao. A sombra so entra quando o
       conteudo de fato desliza por tras do vidro, que e quando ela tem o que
       separar. Sentinela + IntersectionObserver em vez de ouvir o scroll:
       nenhum trabalho por quadro rolado.
       --------------------------------------------------------------- */
    function ligarBordaDoTopo() {
        const topo = doc.querySelector('.topo');
        if (!topo || !global.IntersectionObserver) return;

        const sentinela = doc.createElement('div');
        sentinela.setAttribute('aria-hidden', 'true');
        sentinela.style.cssText =
            'position:absolute;top:0;left:0;width:1px;height:1px;pointer-events:none';
        doc.body.insertBefore(sentinela, doc.body.firstChild);

        new global.IntersectionObserver(
            ([entrada]) => topo.classList.toggle('rolado', !entrada.isIntersecting),
            { rootMargin: '-4px 0px 0px 0px' }
        ).observe(sentinela);
    }

    function iniciar() {
        ligarToque();
        ligarSanfonas();
        ligarAvisos();
        ligarBordaDoTopo();
    }

    global.Movimento = { criarMola, projetar, menosMovimento, iniciar };

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})(window);
