/*
 * Mercado: conferir a conta antes de passar no caixa.
 *
 * Bipa o produto na gondola, digita o preco da etiqueta, e no fim compara o
 * total da lista com o que o caixa cobrou. E uma lista de rascunho: NAO grava
 * nota, NAO mexe no catalogo e NAO toca no espelho da loja. O unico lugar em
 * que ela existe e o localStorage deste aparelho.
 *
 * Por que no aparelho, e nao no banco: dentro do mercado o sinal cai, e uma
 * lista que depende do servidor para aceitar mais um item e uma lista que
 * falha bipando o oitavo produto no corredor do fundo. Aqui ela funciona
 * offline inteira; o que precisa de rede (o nome do produto) e enfeite e some
 * sozinho quando nao ha.
 *
 * O nome do codigo de barras tem duas memorias. A de ca, neste aparelho, e a
 * que responde no corredor sem sinal; a de la, no servidor, e a que sabe das
 * suas notas e da Open Food Facts. Nome que voce digita vai para as duas: da
 * proxima compra ele aparece sozinho, e num produto que base nenhuma conhece
 * — os de limpeza, os da padaria — essa e a unica forma de ele aparecer.
 *
 * Dinheiro e guardado em CENTAVOS inteiros. Somar float em dinheiro erra por
 * volta do terceiro item, e o erro aparece justamente na hora de dizer se a
 * conta bate — que e a unica coisa que esta tela faz.
 */
(function (global) {
    'use strict';

    const CHAVE = 'mercadinho:mercado';
    const CHAVE_NOMES = 'mercadinho:nomes';

    /*
     * Um centavo de folga na comparacao.
     *
     * Item pesado (0,756 kg a R$ 24,90) e arredondado pela balanca, e a lista
     * arredonda por conta propria: exigir igualdade exata acusaria diferenca
     * onde nao ha. Mais que um centavo ja e diferenca de verdade e precisa
     * aparecer.
     */
    const TOLERANCIA = 1;

    // ---------------------------------------------------------------
    // A conta (pura — e o que os testes cobrem)
    // ---------------------------------------------------------------

    /**
     * O que o dedo digitou, em centavos.
     *
     * Aceita "3,89", "3.89", "R$ 3,89" e "1.234,56". O ponto e separador de
     * milhar quando ha virgula depois dele; sozinho, e decimal — teclado de
     * celular manda os dois, e recusar um deles seria implicancia.
     */
    function centavos(texto) {
        let limpo = String(texto == null ? '' : texto).replace(/[^0-9.,-]/g, '');
        if (limpo.indexOf(',') >= 0) {
            limpo = limpo.replace(/\./g, '').replace(',', '.');
        }

        // A conversao e feita nos DIGITOS, nao em float: parseFloat('1,005')
        // * 100 da 100,49999... e arredonda para baixo, virando R$ 1,00 onde
        // a etiqueta diz R$ 1,01. Num app que existe para dizer se a conta
        // bate, um centavo perdido na leitura e o bastante para mentir.
        const partes = /^(-?)(\d*)(?:\.(\d*))?$/.exec(limpo);
        if (!partes) {
            return 0;
        }
        const casas = (partes[3] || '').padEnd(3, '0').slice(0, 3);
        let c = (partes[2] ? parseInt(partes[2], 10) : 0) * 100
              + parseInt(casas.slice(0, 2), 10);
        if (parseInt(casas[2], 10) >= 5) {
            c += 1;
        }
        return partes[1] === '-' ? -c : c;
    }

    /**
     * A chave de um codigo de barras: so digitos, no maximo 14 — a mesma do
     * servidor, senao a memoria daqui e a de la guardariam o mesmo produto em
     * duas gavetas diferentes.
     */
    function chave(codigo) {
        const d = String(codigo == null ? '' : codigo).replace(/\D/g, '');
        return d.length >= 6 ? d.slice(0, 14) : '';
    }

    /** Centavos -> "R$ 12,34". */
    function moeda(c) {
        const v = (Math.abs(c) / 100).toFixed(2).replace('.', ',');
        return (c < 0 ? '-R$ ' : 'R$ ') + v;
    }

    /** Quantidade do jeito que se le: 2, ou 0,756 para o que vai na balanca. */
    function qtdTexto(q) {
        const n = Number(q) || 0;
        return Number.isInteger(n) ? String(n) : n.toFixed(3).replace(/0+$/, '').replace('.', ',');
    }

    /** Quanto uma linha custa, em centavos. */
    function linha(item) {
        return Math.round((Number(item.centavos) || 0) * (Number(item.qtd) || 0));
    }

    /** O total da lista, em centavos. Funcao pura. */
    function total(itens) {
        return (itens || []).reduce((soma, item) => soma + linha(item), 0);
    }

    /**
     * A conferencia: a lista contra o caixa.
     *
     * `caixa` negativo ou zero quer dizer "ainda nao digitou o total", e ai
     * nao ha veredito nenhum — dizer "faltam R$ 87,40" para quem nao informou
     * nada seria inventar uma diferenca.
     *
     * @param {Array} itens
     * @param {number} caixa em centavos
     */
    function conferir(itens, caixa) {
        const lista = total(itens);
        if (!(caixa > 0)) {
            return { lista, caixa: 0, diferenca: 0, status: 'sem-caixa' };
        }
        const diferenca = caixa - lista;
        return {
            lista,
            caixa,
            diferenca,
            status: Math.abs(diferenca) <= TOLERANCIA
                ? 'bate'
                : (diferenca > 0 ? 'caixa-maior' : 'caixa-menor'),
        };
    }

    const api = { centavos, moeda, qtdTexto, linha, total, conferir, chave, TOLERANCIA, CHAVE };

    // ---------------------------------------------------------------
    // A tela
    // ---------------------------------------------------------------

    function iniciar() {
        const doc = global.document;
        const tela = doc.getElementById('mercado');
        if (!tela) return;

        const campoCodigo = doc.getElementById('m-codigo');
        const campoNome   = doc.getElementById('m-nome');
        const campoPreco  = doc.getElementById('m-preco');
        const campoQtd    = doc.getElementById('m-qtd');
        const formItem    = doc.getElementById('m-form');
        const alvoLista   = doc.getElementById('m-lista');
        const alvoTotal   = doc.getElementById('m-total');
        const alvoConta   = doc.getElementById('m-conta');
        const alvoDica    = doc.getElementById('m-dica-produto');
        const campoCaixa  = doc.getElementById('m-caixa');
        const btnLimpar   = doc.getElementById('m-limpar');

        let estado = ler();
        // O nome que veio pronto (memoria ou servidor). Se o que esta no campo
        // for diferente disto na hora de adicionar, foi a pessoa que escreveu.
        let nomeDoServidor = '';

        function ler() {
            try {
                const cru = global.localStorage.getItem(CHAVE);
                const d = cru ? JSON.parse(cru) : null;
                return {
                    itens: (d && Array.isArray(d.itens)) ? d.itens : [],
                    caixa: (d && Number(d.caixa)) || 0,
                };
            } catch (e) {
                return { itens: [], caixa: 0 };
            }
        }

        function gravar() {
            try {
                global.localStorage.setItem(CHAVE, JSON.stringify(estado));
            } catch (e) { /* modo privado: a lista vive so nesta tela */ }
        }

        /*
         * A memoria de nomes deste aparelho. Separada da lista de propósito:
         * limpar a compra nao pode esquecer o que se aprendeu sobre os
         * produtos — na proxima ida ao mercado eles sao os mesmos.
         */
        function lerNomes() {
            try {
                return JSON.parse(global.localStorage.getItem(CHAVE_NOMES) || '{}') || {};
            } catch (e) {
                return {};
            }
        }

        function lembrarNome(codigo, nome) {
            const k = chave(codigo);
            if (!k || !nome) return;
            try {
                const nomes = lerNomes();
                nomes[k] = nome;
                global.localStorage.setItem(CHAVE_NOMES, JSON.stringify(nomes));
            } catch (e) { /* modo privado, ou memoria cheia */ }
        }

        function esc(t) {
            const d = doc.createElement('div');
            d.textContent = t == null ? '' : String(t);
            return d.innerHTML;
        }

        // ---- desenho ----

        function desenhar() {
            const soma = total(estado.itens);
            alvoTotal.textContent = moeda(soma);
            alvoTotal.nextElementSibling.textContent =
                estado.itens.length + (estado.itens.length === 1 ? ' item' : ' itens');

            alvoLista.innerHTML = estado.itens.length
                ? estado.itens.map(desenharItem).join('')
                : '<p class="vazio">Nada bipado ainda. Aponte para o código de barras e digite o preço da etiqueta.</p>';

            desenharConta();
        }

        function desenharItem(item, i) {
            const detalhe = Number(item.qtd) === 1
                ? moeda(item.centavos)
                : qtdTexto(item.qtd) + ' × ' + moeda(item.centavos);

            return '<li><div class="linha-cartao">' +
                '<div class="linha-topo">' +
                  '<span class="forte">' + esc(item.nome || item.codigo || 'sem código') + '</span>' +
                  '<span class="valor">' + moeda(linha(item)) + '</span>' +
                '</div>' +
                '<div class="linha-baixo">' +
                  '<span>' + detalhe + (item.nome && item.codigo ? ' · ' + esc(item.codigo) : '') + '</span>' +
                  '<button type="button" class="link-perigo" data-remover="' + i + '">remover</button>' +
                '</div>' +
                '</div></li>';
        }

        function desenharConta() {
            const r = conferir(estado.itens, estado.caixa);
            if (r.status === 'sem-caixa') {
                alvoConta.hidden = true;
                return;
            }
            alvoConta.hidden = false;

            const dif = Math.abs(r.diferenca);
            if (r.status === 'bate') {
                alvoConta.className = 'aviso aviso-ok';
                alvoConta.innerHTML = '<strong>Bateu.</strong> O caixa cobrou o mesmo que a sua lista, '
                    + moeda(r.lista) + '.';
                return;
            }
            if (r.status === 'caixa-maior') {
                alvoConta.className = 'aviso aviso-erro';
                alvoConta.innerHTML = '<strong>' + moeda(dif) + ' a mais no caixa.</strong> '
                    + 'Sua lista deu ' + moeda(r.lista) + ' e o caixa cobrou ' + moeda(r.caixa)
                    + '. Confira o cupom item a item antes de sair.';
                return;
            }
            alvoConta.className = 'aviso aviso-info';
            alvoConta.innerHTML = '<strong>' + moeda(dif) + ' a menos no caixa.</strong> '
                + 'Sua lista deu ' + moeda(r.lista) + ' e o caixa cobrou ' + moeda(r.caixa)
                + '. Costuma ser promoção que a etiqueta não mostrava — ou item que ficou de fora da lista.';
        }

        // ---- acoes ----

        function adicionar() {
            const preco = centavos(campoPreco.value);
            if (preco <= 0) {
                campoPreco.focus();
                return false;
            }
            const qtd = Number(String(campoQtd.value).replace(',', '.')) || 1;
            const codigo = campoCodigo.value.trim();
            const nome = campoNome.value.trim();

            // Nome que voce escreveu (ou corrigiu) vale mais do que qualquer
            // base: fica guardado aqui e la, para este codigo, para sempre.
            if (codigo && nome && nome !== nomeDoServidor) {
                lembrarNome(codigo, nome);
                guardarNoServidor(codigo, nome);
            }

            estado.itens.push({
                codigo: codigo,
                nome: nome,
                centavos: preco,
                qtd: qtd > 0 ? qtd : 1,
            });
            gravar();
            desenhar();

            campoCodigo.value = '';
            campoNome.value = '';
            campoPreco.value = '';
            campoQtd.value = '1';
            alvoDica.textContent = '';
            nomeDoServidor = '';
            jaProcurado = '';
            return true;
        }

        formItem.addEventListener('submit', (ev) => {
            ev.preventDefault();
            adicionar();
        });

        alvoLista.addEventListener('click', (ev) => {
            const botao = ev.target.closest ? ev.target.closest('[data-remover]') : null;
            if (!botao) return;
            estado.itens.splice(Number(botao.dataset.remover), 1);
            gravar();
            desenhar();
        });

        campoCaixa.addEventListener('input', () => {
            estado.caixa = centavos(campoCaixa.value);
            gravar();
            desenharConta();
        });

        btnLimpar.addEventListener('click', () => {
            if (estado.itens.length && !global.confirm('Limpar a lista e começar outra compra?')) {
                return;
            }
            estado = { itens: [], caixa: 0 };
            campoCaixa.value = '';
            gravar();
            desenhar();
        });

        /** Manda o nome digitado para o servidor. Fire-and-forget. */
        function guardarNoServidor(codigo, nome) {
            const meta = doc.querySelector('meta[name="csrf"]');
            if (!meta) return;
            fetch('/api/ean', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': meta.getAttribute('content') },
                body: JSON.stringify({ codigo: codigo, nome: nome }),
            }).catch(() => { /* sem sinal: a memoria deste aparelho ja guardou */ });
        }

        /**
         * O nome do produto, quando da.
         *
         * A memoria do aparelho responde primeiro e sem rede — no corredor do
         * fundo e a unica que responde. O servidor vem depois e por cima, que
         * e ele quem sabe das suas notas, do que a Open Food Facts respondeu e
         * de quanto voce pagou da ultima vez. Se a rede nao vier em 4s, fica o
         * que ja esta na tela: o preco e digitado do mesmo jeito.
         */
        async function buscarNome(codigo) {
            const k = chave(codigo);
            const guardado = k ? lerNomes()[k] : '';
            if (guardado) {
                campoNome.value = guardado;
                nomeDoServidor = guardado;
                alvoDica.textContent = '';
            } else {
                alvoDica.textContent = 'Procurando o nome...';
            }

            try {
                const corta = new AbortController();
                const relogio = setTimeout(() => corta.abort(), 4000);
                const r = await fetch('/api/ean?codigo=' + encodeURIComponent(codigo), { signal: corta.signal });
                clearTimeout(relogio);
                const d = await r.json();

                // Nao atropela o que a pessoa ja comecou a escrever.
                const intocado = campoNome.value === '' || campoNome.value === guardado;
                if (d.nome && intocado) {
                    campoNome.value = d.nome;
                    nomeDoServidor = d.nome;
                    lembrarNome(codigo, d.nome);
                }

                const ultimo = Number(d.ultimo);
                alvoDica.textContent = ultimo > 0
                    ? 'Você pagou ' + moeda(Math.round(ultimo * 100)) + ' na última nota.'
                    : (campoNome.value ? '' : 'Produto que ninguém conhece ainda — escreva o nome e ele fica.');
            } catch (e) {
                alvoDica.textContent = guardado ? '' : '';
            }
        }

        /*
         * Qual codigo ja foi procurado. Sem isto, sair do campo tres vezes
         * seguidas faria tres consultas para o mesmo produto — e uma delas
         * chegando fora de ordem sobrescreveria o nome com o anterior.
         */
        let jaProcurado = '';

        function talvezBuscar() {
            const k = chave(campoCodigo.value);
            if (!k || k === jaProcurado) {
                return;
            }
            jaProcurado = k;
            buscarNome(campoCodigo.value.trim());
        }

        // Codigo digitado na mao merece o mesmo nome do codigo bipado: o campo
        // diz "bipe ou digite", e quem digita costuma ser justamente quem esta
        // com o codigo de barras amassado que a camera nao pegou.
        campoCodigo.addEventListener('change', talvezBuscar);
        campoCodigo.addEventListener('blur', talvezBuscar);

        // O scanner entrega o codigo aqui; o resto da tela cuida do preco.
        tela.addEventListener('mercado:codigo', (ev) => {
            campoCodigo.value = ev.detail;
            campoNome.value = '';
            campoPreco.value = '';
            campoPreco.focus();
            jaProcurado = chave(ev.detail);
            buscarNome(ev.detail);
        });

        campoCaixa.value = estado.caixa > 0 ? (estado.caixa / 100).toFixed(2).replace('.', ',') : '';
        desenhar();
    }

    api.iniciar = iniciar;
    global.Mercado = api;

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
