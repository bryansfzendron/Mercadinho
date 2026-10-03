/*
 * Criar promocao no TouchPay a partir da sugestao de vencimento.
 *
 * Mesma ideia do Repor: NADA vai embora no primeiro toque. "Criar promocao"
 * abre o resumo de/por com as datas, e so o segundo toque manda. Mexer em
 * qualquer campo com o resumo aberto derruba o resumo.
 *
 * A conta mora em funcoes puras, testadas no Node (testes/promocao.js).
 */
(function (global) {
    'use strict';

    /** "20", "20%", "20,0" -> 20. Vazio ou lixo -> null. */
    function percentual(v) {
        const t = String(v === null || v === undefined ? '' : v).replace('%', '').replace(',', '.').trim();
        if (t === '') return null;
        const n = Number(t);
        return isFinite(n) ? n : null;
    }

    /** O preco no caixa com o desconto. Mesmo arredondamento do PHP. */
    function precoCom(preco, pct) {
        return Math.round(preco * (1 - pct / 100) * 100) / 100;
    }

    /**
     * O que este desconto faz com o produto. Funcao pura.
     *
     * Devolve o preco, o fator sobre o custo e o que sobra por unidade depois
     * do que sai de toda venda — o numero que diz se a promocao paga a conta.
     * Sem custo, so o preco: o resto seria chute.
     */
    function avaliar(preco, custo, piso, pctVariavel, pct) {
        if (!(preco > 0) || pct === null || !(pct >= 1) || pct > 90) return null;
        const p = precoCom(preco, pct);
        const r = { preco: p, fator: null, sobra: null, abaixoPiso: false };
        if (custo > 0) {
            r.fator = p / custo;
            r.sobra = p - custo - p * (pctVariavel || 0) / 100;
            r.abaixoPiso = piso > 0 && r.fator < piso - 1e-9;
        }
        return r;
    }

    function moeda(n) {
        return 'R$ ' + (Number(n) || 0).toFixed(2).replace('.', ',');
    }

    function dataBr(iso) {
        return iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '—';
    }

    const api = { percentual, precoCom, avaliar, moeda, dataBr };

    // ---------------------------------------------------------------
    // A tela
    // ---------------------------------------------------------------

    function iniciar() {
        const doc = global.document;
        const caixa = doc.getElementById('promo-form');
        if (!caixa) return;

        const preco = Number(caixa.dataset.preco) || 0;
        const custo = Number(caixa.dataset.custo) || 0;
        const piso  = Number(caixa.dataset.piso) || 0;
        const pctV  = Number(caixa.dataset.pctVariavel) || 0;

        const elPct   = caixa.querySelector('[data-promo="percentual"]');
        const elIni   = caixa.querySelector('[data-promo="inicio"]');
        const elFim   = caixa.querySelector('[data-promo="fim"]');
        const elSaida = doc.getElementById('promo-saida');
        const elConta = doc.getElementById('promo-conta');
        const elAcao  = doc.getElementById('promo-acao');

        function esc(t) {
            const d = doc.createElement('div');
            d.textContent = t === null || t === undefined ? '' : String(t);
            return d.innerHTML;
        }

        function refazer() {
            const a = avaliar(preco, custo, piso, pctV, percentual(elPct.value));
            caixa.querySelectorAll('.promo-atalhos .chip').forEach((b) => {
                b.classList.toggle('ativo', Number(b.dataset.pct) === percentual(elPct.value));
            });
            if (!a) {
                elSaida.textContent = '—';
                elConta.textContent = 'Desconto de 1% a 90%, em número inteiro.';
                elConta.className = 'ajuda';
                return;
            }
            elSaida.textContent = moeda(a.preco);
            if (a.fator === null) {
                elConta.textContent = 'De ' + moeda(preco) + ' por ' + moeda(a.preco) + '. Sem custo de nota para saber a margem.';
                elConta.className = 'ajuda';
                return;
            }
            // Prejuizo se diz "perde", nao "sobra R$ -0,09".
            elConta.textContent = 'De ' + moeda(preco) + ' por ' + moeda(a.preco)
                + ' · ' + a.fator.toFixed(2).replace('.', ',') + '× o custo · '
                + (a.sobra >= 0 ? 'sobram ' : 'perde ') + moeda(Math.abs(a.sobra))
                + ' por unidade depois das taxas'
                + (a.abaixoPiso ? '. Abaixo do piso: cada venda dá prejuízo.' : '.');
            elConta.className = a.abaixoPiso ? 'aviso aviso-erro' : 'ajuda';
        }

        caixa.querySelectorAll('.promo-atalhos .chip').forEach((b) => {
            b.addEventListener('click', () => {
                elPct.value = b.dataset.pct;
                elPct.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });

        // ---- o segundo toque ----

        function lidos() {
            return { pct: percentual(elPct.value), inicio: elIni.value, fim: elFim.value };
        }

        function voltarAoBotao() {
            elAcao.innerHTML = '<button type="button" class="botao" id="promo-criar">Criar promoção</button>';
            doc.getElementById('promo-criar').addEventListener('click', resumir);
        }

        function resumir() {
            const v = lidos();
            const a = avaliar(preco, custo, piso, pctV, v.pct);
            let queixa = '';
            if (!a) queixa = 'Digite um desconto de 1% a 90%.';
            else if (Math.round(v.pct) !== v.pct) queixa = 'O desconto vai em número inteiro (ex.: 25%).';
            else if (!v.inicio || !v.fim) queixa = 'Faltou a data de começo ou de fim.';
            else if (v.fim < v.inicio) queixa = 'O fim vem antes do começo.';
            if (queixa) {
                voltarAoBotao();
                elAcao.insertAdjacentHTML('afterbegin', '<p class="aviso aviso-erro">' + esc(queixa) + '</p>');
                return;
            }

            elAcao.innerHTML =
                '<div class="aviso aviso-info">' +
                  '<strong>−' + v.pct + '%: ' + esc(moeda(preco)) + ' → ' + esc(moeda(a.preco)) + '</strong><br>' +
                  'de ' + esc(dataBr(v.inicio)) + ' a ' + esc(dataBr(v.fim)) +
                  (a.abaixoPiso ? '<br><strong>Abaixo do piso: cada venda dá prejuízo.</strong>' : '') +
                '</div>' +
                '<div class="duas">' +
                  '<button type="button" class="botao" id="promo-confirmar">Confirmar</button>' +
                  '<button type="button" class="botao botao-alt" id="promo-voltar">Voltar</button>' +
                '</div>';

            doc.getElementById('promo-voltar').addEventListener('click', voltarAoBotao);

            // Mexer em qualquer campo com o resumo aberto derruba o resumo: ele
            // passaria a descrever um desconto que nao e o que esta na tela.
            let vivo = true;
            const derrubar = () => { if (vivo) { vivo = false; voltarAoBotao(); } };
            [elPct, elIni, elFim].forEach((el) => {
                el.addEventListener('input', derrubar, { once: true });
                el.addEventListener('change', derrubar, { once: true });
            });

            doc.getElementById('promo-confirmar').addEventListener('click', () => {
                const agora = lidos();
                if (agora.pct !== v.pct || agora.inicio !== v.inicio || agora.fim !== v.fim) {
                    resumir();
                    return;
                }
                vivo = false;
                enviar(v);
            });
        }

        async function enviar(v) {
            elAcao.innerHTML = '<p class="ajuda">Criando no TouchPay...</p>';
            const meta = doc.querySelector('meta[name="csrf"]');
            try {
                const r = await fetch('/api/promocao/criar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': meta ? meta.getAttribute('content') : '',
                    },
                    body: JSON.stringify({
                        item: Number(caixa.dataset.item),
                        percentual: v.pct,
                        inicio: v.inicio,
                        fim: v.fim,
                        // O preco que ESTA tela mostrou. Se mudou la, o
                        // servidor para em vez de criar um "de/por" que mente.
                        visto_preco: preco,
                    }),
                });
                const d = await global.Resposta.ler(r);
                if (!r.ok || !d.ok) {
                    elAcao.innerHTML = '<div class="aviso aviso-erro">'
                        + esc(d.erro || 'O TouchPay recusou a promoção.') + '</div>';
                    return;
                }
                const p = d.promocao || {};
                elAcao.innerHTML = '<div class="aviso aviso-ok"><strong>Promoção criada.</strong> −'
                    + esc(p.percentual) + '%, ' + esc(moeda(p.preco_promo)) + ', de '
                    + esc(dataBr(p.inicio)) + ' a ' + esc(dataBr(p.fim)) + '.</div>'
                    + '<a class="botao botao-alt" href="/promocoes">Ver promoções</a>';
            } catch (e) {
                // Sem resposta nao quer dizer que nao criou: pode ter ido e a
                // volta se perdeu. Mandar de novo criaria duas promocoes.
                elAcao.innerHTML = '<div class="aviso aviso-erro"><strong>Sem resposta do TouchPay.</strong> '
                    + 'Pode ter criado ou não. Confira no painel antes de tentar de novo.</div>';
            }
        }

        elPct.addEventListener('input', refazer);
        doc.getElementById('promo-criar').addEventListener('click', resumir);
        refazer();
    }

    api.iniciar = iniciar;
    global.Promocao = api;

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
