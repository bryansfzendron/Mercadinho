/*
 * Repor a gondola: a tela que escreve no TouchPay.
 *
 * Todas as outras telas do app leem. Esta grava, e o que ela grava vira o
 * preco que o cliente paga no caixa — entao o desenho inteiro e feito em
 * cima de uma ideia so: NADA vai embora no primeiro toque. "Salvar" abre um
 * resumo de/para, e so o segundo toque manda. Quem esta de pe no corredor
 * com o celular numa mao erra o alvo; errar o alvo aqui nao pode custar o
 * preco de um produto.
 *
 * A conta e a montagem do resumo moram em funcoes puras, testadas no Node
 * (testes/planograma.js): sao elas que decidem o que muda e o que fica.
 *
 * Os numeros aqui sao float e nao centavos, ao contrario do Mercado. La a
 * lista e somada item a item e o erro de float aparece; aqui cada valor vai
 * sozinho para o TouchPay, sem soma nenhuma no meio.
 */
(function (global) {
    'use strict';

    /** Os quatro campos que esta tela mexe, na ordem em que aparecem. */
    const CAMPOS = ['preco', 'estoque', 'necessaria', 'critico'];

    const ROTULOS = {
        preco:      'Preço',
        estoque:    'Estoque',
        necessaria: 'Necessária',
        critico:    'Crítico',
    };

    /**
     * Uma folga de um milesimo na comparacao.
     *
     * 9.1 que voltou do JSON e 9.1 que o dedo digitou nao sao o mesmo bit.
     * Sem folga, abrir a ficha e salvar sem tocar em nada acusaria alteracao
     * em todo campo com casa decimal. Mesmo numero do lado do PHP.
     */
    const FOLGA = 0.0009;

    /**
     * O que o dedo digitou, em numero.
     *
     * Aceita "9,50", "9.50" e "R$ 9,50". Campo vazio devolve null, e null
     * quer dizer "nao mexi neste campo" — diferente de zero, que e um valor
     * de verdade (estoque zerado existe).
     */
    function numero(v) {
        if (v === null || v === undefined || String(v).trim() === '') {
            return null;
        }
        let limpo = String(v).replace(/[^0-9,.\-]/g, '');
        if (limpo.indexOf(',') >= 0) {
            limpo = limpo.replace(/\./g, '').replace(',', '.');
        }
        const n = parseFloat(limpo);
        return isNaN(n) ? null : n;
    }

    /** "R$ 9,50". */
    function moeda(n) {
        return 'R$ ' + (Number(n) || 0).toFixed(2).replace('.', ',');
    }

    /** Quantidade do jeito que se le: 3, ou 0,756 para o que vai na balanca. */
    function qtdTexto(n) {
        const v = Number(n) || 0;
        return Number.isInteger(v) ? String(v) : v.toFixed(3).replace(/0+$/, '').replace('.', ',');
    }

    /** Como este campo se escreve numa frase. */
    function valorTexto(campo, n) {
        return campo === 'preco' ? moeda(n) : qtdTexto(n);
    }

    /**
     * O que mudou, campo a campo. Funcao pura.
     *
     * Campo vazio (null em `agora`) nao e alteracao: e campo que a pessoa nao
     * encostou. Sem essa regra, apagar o preco sem querer viraria "preco = 0"
     * e o produto passaria a sair de graca.
     *
     * @return {Array<{campo:string, de:number, para:number}>}
     */
    function mudancas(antes, agora) {
        const saida = [];
        CAMPOS.forEach((campo) => {
            const para = agora ? agora[campo] : null;
            if (para === null || para === undefined) return;
            const de = Number((antes && antes[campo]) || 0);
            if (Math.abs(de - Number(para)) > FOLGA) {
                saida.push({ campo: campo, de: de, para: Number(para) });
            }
        });
        return saida;
    }

    /** "Preço R$ 9,00 → R$ 9,50". Funcao pura, e o que o resumo mostra. */
    function frase(m) {
        return ROTULOS[m.campo] + ' ' + valorTexto(m.campo, m.de) + ' → ' + valorTexto(m.campo, m.para);
    }

    const api = { numero, moeda, qtdTexto, valorTexto, mudancas, frase, CAMPOS, ROTULOS, FOLGA };

    // ---------------------------------------------------------------
    // A tela
    // ---------------------------------------------------------------

    function iniciar() {
        const doc = global.document;
        const tela = doc.getElementById('planograma');
        if (!tela) return;

        const selPdv   = doc.getElementById('pg-pdv');
        const alvoPlano = doc.getElementById('pg-plano');
        const campo    = doc.getElementById('pg-codigo');
        const form     = doc.getElementById('pg-form');
        const alvo     = doc.getElementById('pg-resultado');
        const dicaCam  = doc.getElementById('dica');

        const LEMBRAR_PDV = 'mercadinho:planograma-pdv';
        let pdvs = [];
        try {
            pdvs = JSON.parse(tela.dataset.pdvs || '[]') || [];
        } catch (e) { /* atributo torto: a tela ainda funciona pelo select */ }

        // O ponto de venda de ontem costuma ser o de hoje: quem repoe volta ao
        // mesmo container. Errar o PDV aqui e mexer no preco da loja errada.
        try {
            const guardado = global.localStorage.getItem(LEMBRAR_PDV);
            if (guardado && selPdv.querySelector('option[value="' + guardado + '"]:not([disabled])')) {
                selPdv.value = guardado;
            }
        } catch (e) { /* modo privado */ }

        function pdvAtual() {
            return Number(selPdv.value) || 0;
        }

        function mostrarPlano() {
            const p = pdvs.filter((x) => x.id === pdvAtual())[0];
            alvoPlano.textContent = p && p.plano
                ? 'Planograma ' + p.plano
                : 'Sem planograma ativo — não dá para repor neste ponto de venda.';
        }

        selPdv.addEventListener('change', () => {
            try { global.localStorage.setItem(LEMBRAR_PDV, String(pdvAtual())); } catch (e) { /* modo privado */ }
            mostrarPlano();
            alvo.innerHTML = '';
        });
        mostrarPlano();

        function esc(t) {
            const d = doc.createElement('div');
            d.textContent = t === null || t === undefined ? '' : String(t);
            return d.innerHTML;
        }

        function aviso(classe, html) {
            alvo.innerHTML = '<div class="aviso aviso-' + classe + '">' + html + '</div>';
        }

        // ---- procurar ----

        let procurando = false;

        async function procurar(codigo) {
            if (procurando) return;
            const pdv = pdvAtual();
            if (!pdv) { aviso('erro', 'Escolha o ponto de venda primeiro.'); return; }

            procurando = true;
            alvo.innerHTML = '<p class="ajuda">Procurando ' + esc(codigo) + '...</p>';
            try {
                const r = await fetch('/api/planograma/buscar?pdv=' + pdv
                    + '&codigo=' + encodeURIComponent(codigo));
                const d = await r.json();
                if (!r.ok || d.erro) {
                    aviso('erro', esc(d.erro || 'Não consegui falar com o TouchPay.'));
                    return;
                }
                desenhar(d);
            } catch (e) {
                aviso('erro', 'Sem resposta — confira o sinal e bipe de novo.');
            } finally {
                procurando = false;
                if (dicaCam) dicaCam.textContent = '';
            }
        }

        // ---- desenho da ficha ----

        function desenhar(d) {
            if (d.onde === 'nenhum') {
                // Terceiro degrau: fim da linha de proposito. Cadastrar produto
                // pede foto, categoria, unidade e tributacao — nada que se
                // preencha de pe no corredor, e errar ali sai caro depois.
                aviso('info',
                    '<strong>' + esc(d.codigo) + ' não está cadastrado.</strong> '
                    + 'Não está no planograma nem no cadastro de produtos. '
                    + 'Esse tem que ser feito na mão, no painel do TouchPay.');
                return;
            }

            const novo  = d.onde === 'cadastro';
            const ficha = novo ? d.produto : d.entrada;
            const atual = novo
                ? { preco: null, estoque: 0, necessaria: 0, critico: 0 }
                : ficha;

            alvo.innerHTML =
                '<div class="cartao" id="pg-ficha">' +
                  '<div class="linha-topo">' +
                    '<span class="forte">' + esc(ficha.descricao || ('produto ' + ficha.produto_id)) + '</span>' +
                    '<span class="valor">' + esc(ficha.ean || ficha.codigo) + '</span>' +
                  '</div>' +
                  (novo
                    ? '<p class="ajuda">Está no cadastro mas <strong>não no planograma</strong>. '
                      + 'Salvando, ele entra.</p>'
                    : '<p class="ajuda">No planograma. Tem <strong>' + qtdTexto(atual.estoque)
                      + '</strong> em estoque agora.</p>') +
                  '<div class="pg-campos">' +
                    linhaCampo('preco', 'Preço (R$)', atual.preco, '0,00') +
                    linhaCampo('estoque', 'Estoque', atual.estoque, '0') +
                    linhaCampo('necessaria', 'Necessária', atual.necessaria, '0') +
                    linhaCampo('critico', 'Crítico', atual.critico, '0') +
                  '</div>' +
                  '<p class="ajuda">' +
                    'O estoque é <strong>definido</strong>, não somado: o TouchPay passa a ter '
                    + 'exatamente o número digitado.' +
                  '</p>' +
                  '<div id="pg-acao">' +
                    '<button type="button" class="botao" id="pg-salvar">' +
                      (novo ? 'Incluir no planograma' : 'Salvar') +
                    '</button>' +
                  '</div>' +
                '</div>';

            const ctx = { d: d, novo: novo, ficha: ficha, atual: atual };
            doc.getElementById('pg-salvar').addEventListener('click', () => resumir(ctx));
        }

        function linhaCampo(nome, rotulo, valor, dica) {
            const v = valor === null || valor === undefined ? '' :
                (nome === 'preco' ? Number(valor).toFixed(2).replace('.', ',') : qtdTexto(valor));
            return '<label>' + rotulo +
                '<input type="text" inputmode="decimal" data-campo="' + nome + '" ' +
                       'value="' + esc(v) + '" placeholder="' + esc(dica) + '" ' +
                       'autocomplete="off" autocorrect="off" spellcheck="false">' +
                '</label>';
        }

        function lidos() {
            const saida = {};
            alvo.querySelectorAll('[data-campo]').forEach((el) => {
                saida[el.dataset.campo] = numero(el.value);
            });
            return saida;
        }

        // ---- confirmar ----

        /**
         * O segundo toque. Entre o "Salvar" e o envio entra este resumo, que
         * e a unica chance de ver que o preco foi de 9,50 para 950 porque a
         * virgula nao entrou.
         */
        function resumir(ctx) {
            const agora = lidos();
            const lista = ctx.novo
                ? mudancas({ preco: null, estoque: 0, necessaria: 0, critico: 0 }, agora)
                : mudancas(ctx.atual, agora);

            const acao = doc.getElementById('pg-acao');

            if (ctx.novo && !(agora.preco > 0)) {
                acao.innerHTML = '<p class="aviso aviso-erro">Produto novo no planograma precisa de preço.</p>'
                    + '<button type="button" class="botao" id="pg-salvar">Incluir no planograma</button>';
                doc.getElementById('pg-salvar').addEventListener('click', () => resumir(ctx));
                return;
            }
            if (!ctx.novo && !lista.length) {
                acao.innerHTML = '<p class="ajuda">Nada mudou.</p>'
                    + '<button type="button" class="botao" id="pg-salvar">Salvar</button>';
                doc.getElementById('pg-salvar').addEventListener('click', () => resumir(ctx));
                return;
            }

            acao.innerHTML =
                '<div class="aviso aviso-info">' +
                  (ctx.novo ? '<strong>Vai entrar no planograma.</strong><br>' : '') +
                  lista.map((m) => esc(frase(m))).join('<br>') +
                '</div>' +
                '<div class="duas">' +
                  '<button type="button" class="botao" id="pg-confirmar">Confirmar</button>' +
                  '<button type="button" class="botao botao-alt" id="pg-cancelar">Voltar</button>' +
                '</div>';

            doc.getElementById('pg-cancelar').addEventListener('click', () => {
                acao.innerHTML = '<button type="button" class="botao" id="pg-salvar">'
                    + (ctx.novo ? 'Incluir no planograma' : 'Salvar') + '</button>';
                doc.getElementById('pg-salvar').addEventListener('click', () => resumir(ctx));
            });
            doc.getElementById('pg-confirmar').addEventListener('click', () => enviar(ctx, agora, lista));
        }

        async function enviar(ctx, agora, lista) {
            const acao = doc.getElementById('pg-acao');
            acao.innerHTML = '<p class="ajuda">Mandando para o TouchPay...</p>';

            const meta = doc.querySelector('meta[name="csrf"]');
            const corpo = {
                pdv:        pdvAtual(),
                produto_id: ctx.ficha.produto_id,
                codigo:     ctx.ficha.ean || ctx.ficha.codigo,
                descricao:  ctx.ficha.descricao,
                preco:      agora.preco,
                estoque:    agora.estoque,
                necessaria: agora.necessaria,
                critico:    agora.critico,
                // O que ESTA tela viu. O servidor compara com o valor de agora
                // antes de gravar: se alguem mexeu nesse meio tempo, a
                // gravacao para em vez de desfazer o trabalho do outro calada.
                visto:      ctx.novo ? null : {
                    preco: ctx.atual.preco, estoque: ctx.atual.estoque,
                    necessaria: ctx.atual.necessaria, critico: ctx.atual.critico,
                },
            };

            try {
                const r = await fetch('/api/planograma/salvar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': meta ? meta.getAttribute('content') : '',
                    },
                    body: JSON.stringify(corpo),
                });
                const d = await r.json();

                if (r.status === 409 && d.conflito) {
                    aviso('erro', '<strong>Alguém mexeu neste produto enquanto a tela estava aberta.</strong> '
                        + 'Nada foi salvo. Bipe de novo para ver os valores de agora.');
                    return;
                }
                if (!r.ok || d.ok === false) {
                    aviso('erro', esc(d.erro || 'O TouchPay recusou a alteração.'));
                    return;
                }

                aviso('ok', '<strong>Salvo.</strong> '
                    + (lista.length ? lista.map((m) => esc(frase(m))).join('<br>') : 'Entrou no planograma.'));
                campo.value = '';
            } catch (e) {
                // Sem resposta nao quer dizer que nao gravou: pode ter ido e a
                // volta e que se perdeu. Dizer "falhou" faria a pessoa mandar
                // de novo, e mandar de novo um estoque que ja entrou nao e
                // grave (define, nao soma) — mas o preco merece conferencia.
                aviso('erro', '<strong>Sem resposta do TouchPay.</strong> '
                    + 'Pode ter salvo ou não. Bipe o produto de novo para conferir.');
            }
        }

        // ---- entradas ----

        form.addEventListener('submit', (ev) => {
            ev.preventDefault();
            const codigo = campo.value.trim();
            if (codigo) procurar(codigo);
        });

        tela.addEventListener('planograma:codigo', (ev) => {
            campo.value = ev.detail;
            procurar(ev.detail);
        });
    }

    api.iniciar = iniciar;
    global.Planograma = api;

    // Em Node (teste da conta) nao ha document nem tela para montar.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (global.document) {
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', iniciar);
        } else {
            iniciar();
        }
    }
})(typeof window !== 'undefined' ? window : globalThis);
