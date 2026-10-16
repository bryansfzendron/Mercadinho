/*
 * As contas da tela "Repor itens da nota": preco sugerido, variacao, margem e
 * estoque depois da entrada. O sugerido TEM que bater com o do Repor
 * (assets/planograma.js) e com o do PHP (repor_preco): e o mesmo numero que
 * vira preco no caixa.
 *
 *   node testes/repor-nota.js
 */
const R = require('../assets/repor-nota.js');
const P = require('../assets/planograma.js');

let ok = 0, falhou = 0;
function checar(nome, obtido, esperado) {
    if (JSON.stringify(obtido) === JSON.stringify(esperado)) { ok++; return; }
    falhou++;
    console.log(`FALHOU  ${nome}\n   esperado: ${JSON.stringify(esperado)}\n   obtido:   ${JSON.stringify(obtido)}`);
}

checar('numero BR', R.numero('1.234,56'), 1234.56);
checar('numero com ponto', R.numero('9.5'), 9.5);
checar('numero vazio', R.numero(' '), null);

checar('sugerido: custo x taxa', R.sugerido(1.8, 2), 3.6);
checar('sugerido arredonda em centavos', R.sugerido(3.33, 1.9), 6.33);
checar('sugerido sem custo', R.sugerido(0, 1.9), null);
checar('sugerido sem taxa', R.sugerido(5, null), null);

// paridade com o Repor: mesmas entradas, mesmo preco
[[1.8, 2], [3.33, 1.9], [12.345, 1.9], [0.7, 3], [0, 2], [4, 0]].forEach(([c, t]) => {
    checar(`paridade com planograma.js (${c} x ${t})`, R.sugerido(c, t), P.precoSugerido(c, t));
});

checar('variacao +8%', R.variacao(20, 21.6), 8);
checar('variacao -10%', R.variacao(20, 18), -10);
checar('variacao sem preco atual', R.variacao(null, 18), null);
checar('margem 40%', R.margem(10, 6), 40);
checar('margem sem custo', R.margem(10, 0), null);

checar('estoque depois', R.estoqueDepois(5, 12), 17);
checar('estoque desconhecido soma so a entrada', R.estoqueDepois(null, 12), 12);
checar('sem entrada nao muda', R.estoqueDepois(5, null), 5);

checar('dinheiro', R.dinheiro(1234.5), 'R$ 1.234,50');
checar('dinheiro vazio', R.dinheiro(null), '—');
checar('percentual com sinal', R.pct(8, true), '+8%');
checar('percentual negativo', R.pct(-10, true), '-10%');
checar('percentual decimal com virgula', R.pct(7.5, false), '7,5%');

checar('contagem ignora travados', R.contar([
    { travado: false, marcado: true }, { travado: false, marcado: false }, { travado: true, marcado: false },
]), { marcados: 1, total: 2 });

console.log(`\n${ok} passaram, ${falhou} falharam`);
process.exit(falhou ? 1 : 0);
