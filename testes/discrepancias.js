/*
 * A previa da tela de discrepancias: quanto cada unidade passa a custar ao
 * abrir a caixa, e a margem sobre o preco de venda.
 *
 *   node testes/discrepancias.js
 */
const D = require('../assets/discrepancias.js');

let ok = 0, falhou = 0;
function checar(nome, obtido, esperado) {
    if (JSON.stringify(obtido) === JSON.stringify(esperado)) { ok++; return; }
    falhou++;
    console.log(`FALHOU  ${nome}\n   esperado: ${JSON.stringify(esperado)}\n   obtido:   ${JSON.stringify(obtido)}`);
}

checar('a caixa de lamen em 50 pacotes', D.previa(114.5, 1, 50, 5.7), { unidades: 50, custo: 2.29, margem: 59.8 });
checar('duas caixas multiplicam', D.previa(52.92, 2, 18, 2.36), { unidades: 36, custo: 1.47, margem: 37.7 });
checar('sem preco de venda nao ha margem', D.previa(114.5, 1, 50, ''), { unidades: 50, custo: 2.29, margem: null });
checar('caixa de 1 nao e caixa', D.previa(114.5, 1, 1, 5.7), null);
checar('sem quantidade nao calcula', D.previa(114.5, 1, '', 5.7), null);
checar('dinheiro', D.dinheiro(1234.5), 'R$ 1.234,50');

console.log(`\n${ok} passaram, ${falhou} falharam`);
process.exit(falhou ? 1 : 0);
