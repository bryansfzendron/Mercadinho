/*
 * Discrepancias das notas: a previa de quanto cada unidade passa a custar e o
 * leitor de codigo para "outro codigo". A conta fica numa funcao pura
 * (testes/discrepancias.js); o resto e o fio que liga os campos ao cartao.
 */
(function (global) {
    'use strict';

    /**
     * Quanto cada unidade passa a custar ao abrir a caixa, e a margem sobre o
     * preco de venda. O total pago e a ancora, como no "Corrigir item".
     */
    function previa(total, caixas, porCaixa, venda) {
        const t = Number(total), c = Number(caixas), n = Number(porCaixa), v = Number(venda);
        if (!(t > 0) || !(c > 0) || !(n >= 2)) return null;
        const unidades = Math.round(c * n * 1000) / 1000;
        const custo = Math.round(t / unidades * 100) / 100;
        return {
            unidades: unidades,
            custo: custo,
            margem: v > 0 ? Math.round((v - custo) / v * 1000) / 10 : null,
        };
    }

    function dinheiro(n) {
        return 'R$ ' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    const api = { previa: previa, dinheiro: dinheiro };

    // ----------------------------------------------------------------- tela
    function iniciar() {
        const doc = global.document;

        doc.querySelectorAll('.disc-item').forEach(function (cartao) {
            const form = cartao.querySelector('.disc-form');
            const por = form.querySelector('.disc-por');
            const outroRadio = form.querySelector('.disc-outro-radio');
            const outroEan = form.querySelector('.disc-outro-ean');
            const saida = form.querySelector('.disc-previa');

            function atualizar() {
                // O "outro codigo" vai no proprio radio, que e o que o form envia.
                outroRadio.value = outroEan.value.replace(/\D/g, '');
                const marcado = form.querySelector('input[name="ean_unidade"]:checked');
                const venda = marcado ? marcado.getAttribute('data-venda') : '';
                const p = previa(cartao.getAttribute('data-total'), cartao.getAttribute('data-qtd'),
                    String(por.value).replace(',', '.'), venda);
                if (!p) { saida.textContent = 'Informe quantas unidades vêm na caixa (2 ou mais).'; return; }
                saida.textContent = 'Fica ' + String(p.unidades).replace('.', ',') + ' unidades a '
                    + dinheiro(p.custo) + ' cada'
                    + (p.margem === null ? '.' : ' · margem de ' + String(p.margem).replace('.', ',')
                        + '% sobre o preço de venda.');
            }

            form.addEventListener('input', atualizar);
            form.addEventListener('change', atualizar);
            outroEan.addEventListener('focus', function () { outroRadio.checked = true; atualizar(); });
            form.querySelectorAll('.disc-palpite').forEach(function (b) {
                b.addEventListener('click', function () { por.value = b.getAttribute('data-n'); atualizar(); });
            });
            // Enter num campo escolheria o primeiro botao do form: abrir a caixa sem querer.
            form.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' && ev.target.tagName === 'INPUT') { ev.preventDefault(); ev.target.blur(); }
            });

            let leitor = null;
            form.querySelector('.disc-bipar').addEventListener('click', async function () {
                if (leitor) { leitor.parar(); leitor = null; cartao.querySelector('.camera-caixa').remove(); return; }
                const cx = doc.createElement('div');
                cx.className = 'camera-caixa larga';
                cx.innerHTML = '<video playsinline muted></video><div class="mira mira-larga"></div>';
                form.querySelector('.disc-unidades').after(cx);
                try {
                    leitor = await global.Scanner.iniciar(cx.querySelector('video'), global.Scanner.BARRAS, function (codigo) {
                        outroEan.value = codigo;
                        outroRadio.checked = true;
                        if (global.navigator.vibrate) global.navigator.vibrate(60);
                        leitor.parar();
                        leitor = null;
                        cx.remove();
                        atualizar();
                    });
                    cx.classList.add('ligada');
                } catch (e) {
                    cx.remove();
                    leitor = null;
                    global.alert(e.message || 'Não consegui abrir a câmera.');
                }
            });

            atualizar();
        });
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
