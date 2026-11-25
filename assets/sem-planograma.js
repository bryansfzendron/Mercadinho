/*
 * Sem planograma: fator 2 como padrao, preco = custo x fator, quantidade da nota, botao Repor.
 * Os campos de cada mercado so ganham vida quando o bloco e aberto (sao centenas de linhas).
 */
(function () {
    'use strict';

    function numero(v) {
        if (v === null || v === undefined || String(v).trim() === '') return null;
        let t = String(v).replace(/[^0-9,.\-]/g, '');
        if (t.indexOf(',') >= 0) t = t.replace(/\./g, '').replace(',', '.');
        const n = parseFloat(t);
        return isNaN(n) ? null : n;
    }
    function br(n) { return n.toFixed(2).replace('.', ','); }

    function ligar(bloco) {
        const custo = parseFloat(bloco.dataset.custo);
        const fator = bloco.querySelector('.mg-fator');
        const preco = bloco.querySelector('.mg-preco');
        const qtd = bloco.querySelector('.sp-qtd');
        const botao = bloco.querySelector('.mg-salvar');
        const msg = bloco.querySelector('.mg-msg');

        function marcar() { botao.disabled = !(numero(preco.value) > 0); }
        fator.addEventListener('input', function () {
            const f = numero(fator.value);
            if (f > 0 && custo > 0) preco.value = br(Math.round(custo * f * 100) / 100);
            msg.textContent = ''; marcar();
        });
        preco.addEventListener('input', function () {
            const p = numero(preco.value);
            if (p > 0 && custo > 0) fator.value = br(p / custo);
            msg.textContent = ''; marcar();
        });
        // Ja vende em outro mercado: mesmo preco de la. Primeira vez em todos: custo x 2.
        const ref = parseFloat(bloco.dataset.ref);
        if (ref > 0) {
            preco.value = br(ref);
            preco.dispatchEvent(new Event('input'));
        } else if (custo > 0) {
            fator.value = '2';
            fator.dispatchEvent(new Event('input'));
        }
        marcar();

        botao.addEventListener('click', async function () {
            const p = numero(preco.value);
            if (!(p > 0)) return;
            botao.disabled = true;
            msg.textContent = 'Mandando para o TouchPay...';
            const meta = document.querySelector('meta[name="csrf"]');
            try {
                const r = await fetch('/api/sem-planograma/repor', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': meta ? meta.getAttribute('content') : '' },
                    body: JSON.stringify({
                        pdv: parseInt(bloco.dataset.pdv, 10),
                        codigo: bloco.dataset.codigo,
                        preco: Math.round(p * 100) / 100,
                        entrada: numero(qtd.value) || 0,
                    }),
                });
                const d = await Resposta.ler(r);
                if (r.ok && d.ok) {
                    msg.textContent = 'Reposto: R$ ' + br(p) + ' · ' + (numero(qtd.value) || 0) + ' un.';
                    [fator, preco, qtd].forEach(function (c) { c.disabled = true; });
                    botao.textContent = 'Feito';
                } else {
                    msg.textContent = d.msg || d.erro || 'O TouchPay recusou.';
                    botao.disabled = false;
                }
            } catch (e) {
                msg.textContent = 'Sem resposta do TouchPay. Pode ter salvo: recarregue para conferir.';
                botao.disabled = false;
            }
        });
    }

    document.querySelectorAll('details.sp-mercado').forEach(function (det) {
        let pronto = false;
        function iniciar() {
            if (pronto || !det.open) return;
            pronto = true;
            det.querySelectorAll('.sp-troca').forEach(ligar);
        }
        det.addEventListener('toggle', iniciar);
        iniciar();
    });
})();
