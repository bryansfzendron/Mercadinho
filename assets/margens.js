/*
 * Margens: trocar o preco na propria lista.
 * Digitar o fator (x) calcula o preco = custo x fator; digitar o preco recalcula o fator.
 * Salva pelo mesmo endpoint do Repor (/api/planograma/salvar), com o preco visto como guarda.
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
    function br(n, casas) { return n.toFixed(casas).replace('.', ','); }

    document.querySelectorAll('.mg-troca').forEach(function (bloco) {
        const custo = parseFloat(bloco.dataset.custo);
        const venda = parseFloat(bloco.dataset.venda);
        const fator = bloco.querySelector('.mg-fator');
        const preco = bloco.querySelector('.mg-preco');
        const botao = bloco.querySelector('.mg-salvar');
        const msg = bloco.querySelector('.mg-msg');
        let atual = venda;

        function marcar() {
            const p = numero(preco.value);
            botao.disabled = !(p > 0) || Math.abs(p - atual) < 0.005;
        }
        fator.addEventListener('input', function () {
            const f = numero(fator.value);
            if (f > 0 && custo > 0) preco.value = br(Math.round(custo * f * 100) / 100, 2);
            msg.textContent = '';
            marcar();
        });
        preco.addEventListener('input', function () {
            const p = numero(preco.value);
            if (p > 0 && custo > 0) fator.value = br(p / custo, 2);
            msg.textContent = '';
            marcar();
        });

        // Ja abre com fator 2: so conferir o preco e clicar em salvar.
        if (custo > 0) {
            fator.value = '2';
            fator.dispatchEvent(new Event('input'));
        }

        botao.addEventListener('click', async function () {
            const p = numero(preco.value);
            if (!(p > 0)) return;
            botao.disabled = true;
            msg.textContent = 'Mandando para o TouchPay...';
            const meta = document.querySelector('meta[name="csrf"]');
            try {
                const r = await fetch('/api/planograma/salvar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': meta ? meta.getAttribute('content') : '',
                    },
                    body: JSON.stringify({
                        pdv: parseInt(bloco.dataset.pdv, 10),
                        produto_id: parseInt(bloco.dataset.produto, 10),
                        codigo: bloco.dataset.codigo,
                        descricao: bloco.dataset.descricao,
                        preco: Math.round(p * 100) / 100,
                        visto: { preco: atual },
                    }),
                });
                const d = await Resposta.ler(r);
                if (r.status === 409 && d.conflito) {
                    msg.textContent = 'Alguém mexeu neste preço. Nada foi salvo; recarregue a página.';
                } else if (!r.ok || d.ok === false) {
                    msg.textContent = d.erro || 'O TouchPay recusou a alteração.';
                    botao.disabled = false;
                } else {
                    atual = p;
                    msg.textContent = 'Salvo: R$ ' + br(p, 2) + '.';
                    marcar();
                }
            } catch (e) {
                msg.textContent = 'Sem resposta do TouchPay. Pode ter salvo ou não; recarregue para conferir.';
                botao.disabled = false;
            }
        });
    });
})();
