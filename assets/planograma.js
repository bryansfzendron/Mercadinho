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
 *
 * Dos seis campos da ficha, dois — custo e taxa — nao existem do lado de la:
 * sao a conta que produz o preco, e param nesta tela.
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
     * O preco da etiqueta: o que se pagou no mercado, vezes a taxa.
     *
     * Custo e taxa sao os dois unicos campos desta tela que NAO vao para o
     * TouchPay — o painel deles so conhece o preco final. Eles existem aqui
     * porque e no corredor, com a nota do atacado na mao, que se sabe por
     * quanto o produto entrou; fazer essa conta na calculadora e digitar o
     * resultado e um passo a mais para errar.
     *
     * Faltando qualquer um dos dois, devolve null — "nao mexe no preco" — e
     * nao zero. Zero aqui seria o produto saindo de graca no caixa, que e
     * exatamente o erro que esta tela inteira existe para nao cometer. Custo
     * zero e taxa zero caem na mesma regra: sao digitacao pela metade, nunca
     * uma etiqueta de R$ 0,00 de verdade.
     *
     * O arredondamento e no centavo, porque centavo e o que a etiqueta tem.
     */
    function precoSugerido(custo, taxa) {
        const c = Number(custo);
        const t = Number(taxa);
        if (!(c > 0) || !(t > 0)) return null;
        return Math.round(c * t * 100) / 100;
    }

    /**
     * Uma data em AAAA-MM-DD, venha de onde vier. Funcao pura.
     *
     * O <input type="date"> ja manda assim, mas onde ele nao existe o campo
     * vira texto e o dedo digita "14/02/2027". Vazio e null, e null quer
     * dizer "nao encostei nesta validade" — nunca "apague a validade".
     */
    function dataIso(v) {
        const t = String(v === null || v === undefined ? '' : v).trim();
        if (t === '') return null;
        let a, m, d;
        let r = /^(\d{4})-(\d{2})-(\d{2})/.exec(t);
        if (r) { a = r[1]; m = r[2]; d = r[3]; }
        else {
            r = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(t);
            if (!r) return null;
            a = r[3]; m = r[2]; d = r[1];
        }
        // 2027-02-31 passa na regex e nao existe: o Date devolve marco.
        const dt = new Date(Number(a), Number(m) - 1, Number(d));
        if (dt.getFullYear() !== Number(a) || dt.getMonth() !== Number(m) - 1
            || dt.getDate() !== Number(d)) {
            return null;
        }
        return a + '-' + m + '-' + d;
    }

    /** "2027-02-14" -> "14/02/2027". Funcao pura. */
    function dataBr(iso) {
        return iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '—';
    }

    /**
     * Data que gente digita? Funcao pura. Mesma janela do lado do PHP.
     *
     * Existe por causa de um item real no inventario deles com validade em
     * 5027: alguem trocou o 2 por 5 e o sistema aceitou. E o mesmo erro do
     * preco que vira R$ 950,00 sem a virgula, so que mil anos adiante.
     */
    function validadePlausivel(iso, hoje) {
        if (!iso) return false;
        const dias = (Date.parse(iso + 'T00:00:00')
                    - Date.parse((hoje || new Date().toISOString().slice(0, 10)) + 'T00:00:00'))
                    / 86400000;
        return dias >= -730 && dias <= 3650;
    }

    /**
     * Manter a validade do estoque ou gravar a que esta entrando? Funcao pura.
     *
     * O TouchPay guarda UMA data por item, mas a gondola tem mistura. A que
     * vale e a que vence PRIMEIRO, porque e ela que manda na hora de tirar o
     * produto da prateleira: repor com lote novo nao pode empurrar a data
     * para a frente e esconder o pacote velho que ficou la atras.
     *
     * Devolve a recomendacao, nao a decisao — quem esta no corredor e quem
     * enxerga se o lote antigo ainda existe.
     */
    function validadeDecidir(atual, nova) {
        if (!nova) {
            return { acao: 'nada', data: null, recomendado: 'nada', motivo: '' };
        }
        if (!atual) {
            return { acao: 'gravar', data: nova, recomendado: 'gravar',
                     motivo: 'Não havia validade cadastrada.' };
        }
        if (atual === nova) {
            return { acao: 'nada', data: atual, recomendado: 'nada',
                     motivo: 'A validade cadastrada já é essa.' };
        }
        if (nova < atual) {
            return { acao: 'gravar', data: nova, recomendado: 'gravar',
                     motivo: 'A que você está repondo vence antes.' };
        }
        return { acao: 'manter', data: atual, recomendado: 'manter',
                 motivo: 'A do estoque vence antes.' };
    }

    /** "Validade 14/02/2027 → 30/06/2027". Funcao pura. */
    function fraseValidade(de, para) {
        return 'Validade ' + dataBr(de) + ' → ' + dataBr(para);
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

    /**
     * Os campos lidos agora sao os mesmos de quando o resumo foi montado?
     * Funcao pura.
     *
     * Serve para o resumo nao poder mentir. Campo vazio (null) e diferente de
     * zero aqui tambem: quem apagou o preco depois de pedir o resumo mudou de
     * ideia, e isso tem de reabrir a conferencia em vez de mandar o valor
     * antigo que ninguem esta mais vendo.
     */
    function mesmosCampos(a, b) {
        return CAMPOS.every((campo) => {
            const x = (a ? a[campo] : null) ?? null;
            const y = (b ? b[campo] : null) ?? null;
            if (x === null || y === null) {
                return x === y;
            }
            return Math.abs(Number(x) - Number(y)) <= FOLGA;
        });
    }

    /** "Preço R$ 9,00 → R$ 9,50". Funcao pura, e o que o resumo mostra. */
    function frase(m) {
        return ROTULOS[m.campo] + ' ' + valorTexto(m.campo, m.de) + ' → ' + valorTexto(m.campo, m.para);
    }

    const api = { numero, moeda, qtdTexto, valorTexto, precoSugerido, mudancas, frase,
                  dataIso, dataBr, validadePlausivel, validadeDecidir, fraseValidade,
                  mesmosCampos, CAMPOS, ROTULOS, FOLGA };

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
        // A taxa e a mesma o dia inteiro: quem repoe trabalha com uma margem
        // so. O custo muda a cada produto e por isso nao se guarda; a taxa,
        // se nao voltasse sozinha, seria digitada trinta vezes seguidas.
        const LEMBRAR_TAXA = 'mercadinho:planograma-taxa';

        const AJUDA_CONTA = 'Custo × taxa vira o preço. Nenhum dos dois vai para o TouchPay.';

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
                  '<div class="pg-calc">' +
                    '<div class="pg-campos">' +
                      linhaAux('custo', 'Custo (R$)', '', '0,00') +
                      linhaAux('taxa', 'Taxa (×)', taxaLembrada(), '1,90') +
                    '</div>' +
                    '<p class="ajuda" id="pg-conta"></p>' +
                  '</div>' +
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
                  blocoValidade(atual.validade || null) +
                  '<div id="pg-acao">' +
                    '<button type="button" class="botao" id="pg-salvar">' +
                      (novo ? 'Incluir no planograma' : 'Salvar') +
                    '</button>' +
                  '</div>' +
                '</div>';

            const ctx = { d: d, novo: novo, ficha: ficha, atual: atual };
            ligarConta();
            ligarValidade(atual.validade || null);
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

        /**
         * Campo que morre nesta tela.
         *
         * Vai com `data-aux` e nao com `data-campo` de proposito: `lidos()`
         * procura por `data-campo`, entao o que nasce aqui nunca entra no
         * corpo do POST nem no resumo de/para. O TouchPay continua recebendo
         * os mesmos quatro campos de sempre.
         */
        function linhaAux(nome, rotulo, valor, dica) {
            return '<label>' + rotulo +
                '<input type="text" inputmode="decimal" data-aux="' + nome + '" ' +
                       'value="' + esc(valor) + '" placeholder="' + esc(dica) + '" ' +
                       'autocomplete="off" autocorrect="off" spellcheck="false">' +
                '</label>';
        }

        function taxaLembrada() {
            try { return global.localStorage.getItem(LEMBRAR_TAXA) || ''; } catch (e) { return ''; }
        }

        /**
         * Custo x taxa -> preco, a cada tecla.
         *
         * A linha de baixo repete a conta por extenso, e nao so o resultado,
         * porque e nela que se ve o custo que entrou como R$ 950,00 em vez de
         * R$ 9,50. O preco calculado continua sendo um campo comum: da para
         * corrigir na mao por cima, e o resumo do "Salvar" confere o numero
         * final de um jeito so, venha ele da conta ou do dedo.
         */
        function ligarConta() {
            const elCusto = alvo.querySelector('[data-aux="custo"]');
            const elTaxa  = alvo.querySelector('[data-aux="taxa"]');
            const elPreco = alvo.querySelector('[data-campo="preco"]');
            const elConta = doc.getElementById('pg-conta');
            if (!elCusto || !elTaxa || !elPreco || !elConta) return;

            function refazer() {
                const custo = numero(elCusto.value);
                const taxa  = numero(elTaxa.value);

                if (taxa !== null) {
                    try { global.localStorage.setItem(LEMBRAR_TAXA, qtdTexto(taxa)); } catch (e) { /* modo privado */ }
                }

                const p = precoSugerido(custo, taxa);
                if (p === null) {
                    // Falta um dos dois: o preco que ja esta no campo fica
                    // onde esta. Apagar o custo nao pode apagar o preco.
                    elConta.textContent = AJUDA_CONTA;
                    return;
                }
                elPreco.value = p.toFixed(2).replace('.', ',');
                elConta.textContent = moeda(custo) + ' × ' + qtdTexto(taxa) + ' = ' + moeda(p);
            }

            elCusto.addEventListener('input', refazer);
            elTaxa.addEventListener('input', refazer);
            refazer();
        }

        /**
         * A validade. Um campo, e uma escolha que so aparece quando precisa.
         *
         * Mostrar a data que ja esta no estoque nao e enfeite: e o que torna
         * a decisao possivel. Sem ela, quem repoe com um lote mais novo
         * empurraria a validade para a frente sem saber que ha pacote velho
         * atras — e o aviso de vencimento chegaria tarde demais.
         */
        function blocoValidade(atual) {
            return '<div class="pg-validade">' +
                     '<label>Validade do que estou repondo' +
                       '<input type="date" data-data="validade" ' +
                              'autocomplete="off" spellcheck="false">' +
                     '</label>' +
                     '<p class="ajuda" id="pg-val-atual">' +
                       (atual
                         ? 'No estoque hoje: <strong>' + esc(dataBr(atual)) + '</strong>'
                         : 'Nenhuma validade cadastrada neste produto.') +
                     '</p>' +
                     '<div id="pg-val-escolha"></div>' +
                   '</div>';
        }

        function ligarValidade(atual) {
            const el = alvo.querySelector('[data-data="validade"]');
            const cx = doc.getElementById('pg-val-escolha');
            if (!el || !cx) return;

            function refazer() {
                const nova = dataIso(el.value);
                if (!nova) { cx.innerHTML = ''; return; }

                if (!validadePlausivel(nova)) {
                    cx.innerHTML = '<p class="aviso aviso-erro">' + esc(dataBr(nova))
                        + ' está fora do razoável. Confira o ano.</p>';
                    return;
                }

                const dec = validadeDecidir(atual, nova);
                if (dec.acao === 'nada') {
                    cx.innerHTML = '<p class="ajuda">' + esc(dec.motivo) + '</p>';
                    return;
                }
                if (!atual) {
                    cx.innerHTML = '<p class="ajuda">' + esc(dec.motivo)
                        + ' Vai gravar <strong>' + esc(dataBr(nova)) + '</strong>.</p>';
                    return;
                }

                // As duas datas existem e brigam: a escolha e de quem esta
                // olhando a gondola. A recomendada vem marcada, nunca imposta.
                cx.innerHTML =
                    '<p class="ajuda">' + esc(dec.motivo) + '</p>' +
                    '<label class="pg-escolha">' +
                      '<input type="radio" name="pg-val" value="manter"' +
                        (dec.recomendado === 'manter' ? ' checked' : '') + '>' +
                      '<span>Manter ' + esc(dataBr(atual)) + '</span>' +
                    '</label>' +
                    '<label class="pg-escolha">' +
                      '<input type="radio" name="pg-val" value="gravar"' +
                        (dec.recomendado === 'gravar' ? ' checked' : '') + '>' +
                      '<span>Gravar ' + esc(dataBr(nova)) + '</span>' +
                    '</label>';
            }

            el.addEventListener('input', refazer);
            el.addEventListener('change', refazer);
        }

        /** O que a pessoa decidiu sobre a validade. */
        function validadeLida(atual) {
            const el = alvo.querySelector('[data-data="validade"]');
            const nova = el ? dataIso(el.value) : null;
            if (!nova) return { acao: 'nada', data: null };

            const marcado = alvo.querySelector('input[name="pg-val"]:checked');
            const acao = marcado ? marcado.value : validadeDecidir(atual, nova).recomendado;
            return { acao: acao, data: nova };
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
        /** Todo campo que a pessoa consegue mexer na ficha. */
        function camposDaFicha() {
            return alvo.querySelectorAll(
                '[data-campo], [data-aux], [data-data], input[name="pg-val"]');
        }

        /** De volta ao botao de Salvar, com o resumo descartado. */
        function voltarAoSalvar(ctx) {
            const acao = doc.getElementById('pg-acao');
            if (!acao) return;
            acao.innerHTML = '<button type="button" class="botao" id="pg-salvar">'
                + (ctx.novo ? 'Incluir no planograma' : 'Salvar') + '</button>';
            doc.getElementById('pg-salvar').addEventListener('click', () => resumir(ctx));
        }

        function resumir(ctx) {
            const agora = lidos();
            const lista = ctx.novo
                ? mudancas({ preco: null, estoque: 0, necessaria: 0, critico: 0 }, agora)
                : mudancas(ctx.atual, agora);

            const val = validadeLida(ctx.atual.validade || null);
            // Validade so entra no resumo quando vai mesmo mudar: "manter" e
            // uma decisao de nao escrever, e anunciar isso como alteracao
            // faria o resumo mentir sobre o que esta prestes a sair daqui.
            const trocaValidade = val.acao === 'gravar'
                && val.data !== (ctx.atual.validade || null);

            const acao = doc.getElementById('pg-acao');

            if (ctx.novo && !(agora.preco > 0)) {
                voltarAoSalvar(ctx);
                acao.insertAdjacentHTML('afterbegin',
                    '<p class="aviso aviso-erro">Produto novo no planograma precisa de preço.</p>');
                return;
            }
            if (!ctx.novo && !lista.length && !trocaValidade) {
                voltarAoSalvar(ctx);
                acao.insertAdjacentHTML('afterbegin', '<p class="ajuda">Nada mudou.</p>');
                return;
            }

            const linhas = lista.map((m) => esc(frase(m)));
            if (trocaValidade) {
                linhas.push(esc(fraseValidade(ctx.atual.validade || null, val.data)));
            }

            acao.innerHTML =
                '<div class="aviso aviso-info">' +
                  (ctx.novo ? '<strong>Vai entrar no planograma.</strong><br>' : '') +
                  linhas.join('<br>') +
                '</div>' +
                '<div class="duas">' +
                  '<button type="button" class="botao" id="pg-confirmar">Confirmar</button>' +
                  '<button type="button" class="botao botao-alt" id="pg-cancelar">Voltar</button>' +
                '</div>';

            doc.getElementById('pg-cancelar').addEventListener('click', () => voltarAoSalvar(ctx));

            // Os campos continuam editaveis com o resumo na tela, e e assim
            // que tem de ser: quem viu o de/para e reparou que digitou errado
            // corrige ali mesmo. So que dai o resumo passa a descrever um
            // estado que nao existe mais — entao mexer em qualquer campo o
            // derruba, e a conferencia recomeca. Confirmar so confirma o que
            // esta escrito na tela naquele instante.
            let vivo = true;
            const derrubar = () => {
                if (!vivo) return;
                vivo = false;
                voltarAoSalvar(ctx);
            };
            camposDaFicha().forEach((el) => {
                el.addEventListener('input', derrubar);
                el.addEventListener('change', derrubar);
            });

            doc.getElementById('pg-confirmar').addEventListener('click', () => {
                // Cinto e suspensorio. Se algum campo mudou por um caminho que
                // nao disparou os eventos acima, o resumo volta em vez de
                // mandar numero que ninguem conferiu.
                const conferir = lidos();
                const valAgora = validadeLida(ctx.atual.validade || null);
                if (!mesmosCampos(agora, conferir)
                    || val.acao !== valAgora.acao || val.data !== valAgora.data) {
                    resumir(ctx);
                    return;
                }
                enviar(ctx, agora, lista, val, linhas);
            });
        }

        async function enviar(ctx, agora, lista, val, linhas) {
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
                // A data e a decisao vao separadas: o servidor precisa saber
                // que "manter" foi escolha, e nao campo que ficou em branco.
                validade:       val ? val.data : null,
                validade_acao:  val ? val.acao : 'nada',
                // O que ESTA tela viu. O servidor compara com o valor de agora
                // antes de gravar: se alguem mexeu nesse meio tempo, a
                // gravacao para em vez de desfazer o trabalho do outro calada.
                visto:      ctx.novo ? null : {
                    preco: ctx.atual.preco, estoque: ctx.atual.estoque,
                    necessaria: ctx.atual.necessaria, critico: ctx.atual.critico,
                    validade: ctx.atual.validade || null,
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

                const resumo = (linhas && linhas.length) ? linhas.join('<br>') : '';
                aviso('ok', '<strong>Salvo.</strong> ' + (resumo || 'Entrou no planograma.'));
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
