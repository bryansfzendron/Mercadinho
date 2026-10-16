/*
 * Repor itens da nota: as contas da tela, em funcoes puras (testes/repor-nota.js),
 * e o fio que liga taxa, entrada, preco e escolha aos cartoes.
 *
 * Nada aqui fala com o servidor: o formulario so e enviado no "Continuar", e o
 * que ele cria e um lote que ainda precisa de conferencia e de um clique para
 * gravar.
 */
(function (global) {
    'use strict';

    /** "9,50", "9.50", "R$ 1.234,56" -> numero; vazio ou lixo -> null. */
    function numero(v) {
        if (v === null || v === undefined || String(v).trim() === '') return null;
        let limpo = String(v).replace(/[^0-9,.\-]/g, '');
        if (limpo.indexOf(',') >= 0) limpo = limpo.replace(/\./g, '').replace(',', '.');
        const n = parseFloat(limpo);
        return isNaN(n) ? null : n;
    }

    /** Custo x taxa em centavos. Mesma conta de precoSugerido() do planograma.js. */
    function sugerido(custo, taxa) {
        const c = Number(custo), t = Number(taxa);
        if (!(c > 0) || !(t > 0)) return null;
        return Math.round(c * t * 100) / 100;
    }

    /** Quanto o preco novo muda em relacao ao atual, em %. */
    function variacao(atual, novo) {
        if (!(atual > 0) || !(novo > 0)) return null;
        return Math.round((novo - atual) / atual * 1000) / 10;
    }

    /** Margem sobre o preco de venda, em %. */
    function margem(preco, custo) {
        if (!(preco > 0) || !(custo > 0)) return null;
        return Math.round((preco - custo) / preco * 1000) / 10;
    }

    /** Estoque depois da entrada; sem estoque conhecido, so a entrada. */
    function estoqueDepois(atual, entrada) {
        const e = entrada === null || entrada === undefined ? 0 : entrada;
        return (atual === null || atual === undefined ? 0 : atual) + e;
    }

    function dinheiro(n) {
        return n === null || n === undefined ? '—'
            : 'R$ ' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function pct(n, comSinal) {
        if (n === null || n === undefined) return '';
        const t = String(n).replace('.', ',');
        return (comSinal && n > 0 ? '+' : '') + t + '%';
    }

    /** Quantos cartoes estao marcados, de quantos que podem ser marcados. */
    function contar(cartoes) {
        const pode = cartoes.filter(function (c) { return !c.travado; });
        return { marcados: pode.filter(function (c) { return c.marcado; }).length, total: pode.length };
    }

    const api = { numero: numero, sugerido: sugerido, variacao: variacao, margem: margem,
                  estoqueDepois: estoqueDepois, dinheiro: dinheiro, pct: pct, contar: contar };

    // ----------------------------------------------------------------- tela
    function iniciar() {
        const doc = global.document;
        const form = doc.getElementById('rn');
        if (!form) return;

        const campoTaxa = doc.getElementById('rn-taxa');
        const CHAVE = 'mercadinho:planograma-taxa';

        // A taxa e a mesma do Repor: o que a pessoa usa la vale aqui, e vice-versa.
        // So aplica quando a pagina nao veio com uma taxa explicita (reenvio, troca de PDV).
        try {
            const guardada = global.localStorage.getItem(CHAVE);
            if (guardada && campoTaxa.value === '1,90' && !/[?&]taxa=/.test(global.location.search)) {
                campoTaxa.value = guardada;
            }
        } catch (e) { /* sem localStorage: segue com a taxa da tela */ }

        const cartoes = Array.prototype.slice.call(doc.querySelectorAll('.rn-item'));

        function leitura(c) {
            return {
                custo: parseFloat(c.getAttribute('data-custo')) || 0,
                atual: c.getAttribute('data-atual') === '' ? null : parseFloat(c.getAttribute('data-atual')),
                estoque: c.getAttribute('data-estoque') === '' ? null : parseFloat(c.getAttribute('data-estoque')),
            };
        }

        function atualizar(c) {
            if (c.getAttribute('data-travado') === '1') return;
            const d = leitura(c);
            const taxa = numero(campoTaxa.value);
            const campoSug = c.querySelector('.rn-sug');

            // O sugerido acompanha a taxa, mas so enquanto a pessoa nao digitou o dele.
            if (c.getAttribute('data-editado') !== '1') {
                const s = sugerido(d.custo, taxa);
                campoSug.value = s === null ? '' : s.toFixed(2).replace('.', ',');
            }
            const preco = numero(campoSug.value);
            const v = variacao(d.atual, preco);
            const m = margem(preco, d.custo);
            c.querySelector('.rn-var').textContent = v === null ? '' : 'vs. atual ' + pct(v, true);
            c.querySelector('.rn-margem').textContent = m === null ? '' : 'margem ' + pct(m, false);

            const entrada = numero(c.querySelector('.rn-entrada').value);
            const depois = estoqueDepois(d.estoque, entrada);
            c.querySelector('.rn-estoque-txt').textContent =
                (d.estoque === null ? '?' : String(d.estoque).replace('.', ',')) + ' → ' + String(depois).replace('.', ',');
        }

        function contagem() {
            const lista = cartoes.map(function (c) {
                const sel = c.querySelector('.rn-sel');
                return { travado: c.getAttribute('data-travado') === '1', marcado: !!(sel && sel.checked) };
            });
            const r = contar(lista);
            doc.getElementById('rn-conta').textContent = r.marcados + ' de ' + r.total + ' itens marcados.';
            doc.getElementById('rn-continuar').disabled = r.marcados === 0;
        }

        function todos(fn) { cartoes.forEach(function (c) { if (c.getAttribute('data-travado') !== '1') fn(c); }); }

        campoTaxa.addEventListener('input', function () {
            try { global.localStorage.setItem(CHAVE, campoTaxa.value); } catch (e) { /* tudo bem */ }
            cartoes.forEach(atualizar);
        });
        cartoes.forEach(function (c) {
            const sug = c.querySelector('.rn-sug');
            if (sug) {
                sug.addEventListener('input', function () {
                    c.setAttribute('data-editado', '1');
                    // Mexer no preco e dizer "quero este": marca "trocar" sozinho.
                    const t = c.querySelector('.rn-esc[value="trocar"]');
                    if (t) t.checked = true;
                    atualizar(c);
                });
            }
            const ent = c.querySelector('.rn-entrada');
            if (ent) ent.addEventListener('input', function () { atualizar(c); });
            const sel = c.querySelector('.rn-sel');
            if (sel) sel.addEventListener('change', contagem);
            atualizar(c);
        });

        doc.getElementById('rn-pdv').addEventListener('change', function (ev) {
            global.location.href = '/notas/' + form.getAttribute('data-id') + '/repor?pdv='
                + encodeURIComponent(ev.target.value) + '&taxa=' + encodeURIComponent(campoTaxa.value);
        });

        Array.prototype.forEach.call(doc.querySelectorAll('[data-acao]'), function (b) {
            b.addEventListener('click', function () {
                const acao = b.getAttribute('data-acao');
                todos(function (c) {
                    if (acao === 'marcar' || acao === 'desmarcar') {
                        const s = c.querySelector('.rn-sel');
                        if (s) s.checked = acao === 'marcar';
                    } else {
                        const r = c.querySelector('.rn-esc[value="' + acao + '"]');
                        if (r) r.checked = true;
                        if (acao === 'trocar') {
                            // "Trocar todos" volta ao sugerido da taxa, descartando preco digitado.
                            c.setAttribute('data-editado', '0');
                            atualizar(c);
                        }
                    }
                });
                contagem();
            });
        });
        contagem();
    }

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
