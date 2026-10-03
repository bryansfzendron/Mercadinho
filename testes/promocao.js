/*
 * A conta da tela de promocao: o desconto digitado, o preco que ele da e se
 * a promocao paga a conta. E o que aparece entre o "Criar promocao" e o
 * envio — a unica chance de ver que 70% era 7%.
 *
 *   node testes/promocao.js
 */
const P = require('../assets/promocao.js');

let ok = 0, falhou = 0;
function checar(nome, obtido, esperado) {
    if (obtido === esperado) { ok++; return; }
    falhou++;
    console.log(`FALHOU  ${nome}\n   esperado: ${JSON.stringify(esperado)}\n   obtido:   ${JSON.stringify(obtido)}`);
}

// ------------------------------------------------- o que o dedo digitou
checar('numero puro', P.percentual('20'), 20);
checar('com o simbolo', P.percentual('20%'), 20);
checar('virgula', P.percentual('12,5'), 12.5);
checar('vazio e null', P.percentual(''), null);
checar('lixo e null', P.percentual('abc'), null);

// ------------------------------------------------------------ o preco
// Mesmo arredondamento do PHP: R$ 4,29 com 27% sai a R$ 3,13.
checar('preco com desconto', P.precoCom(4.29, 27), 3.13);
checar('10% de 10', P.precoCom(10, 10), 9);

// ---------------------------------------------------------- a avaliacao
const a = P.avaliar(4.29, 2.25, 1.15, 11.68, 27);
checar('avaliar da o preco', a.preco, 3.13);
checar('e o fator sobre o custo', a.fator.toFixed(3), '1.391');
checar('e a sobra depois das taxas', a.sobra.toFixed(2), '0.51');
checar('acima do piso', a.abaixoPiso, false);

const fundo = P.avaliar(4.29, 2.25, 1.15, 11.68, 45);
checar('45% fura o piso', fundo.abaixoPiso, true);
checar('e a sobra fica negativa', fundo.sobra < 0, true);

const semCusto = P.avaliar(4.29, 0, 1.15, 11.68, 20);
checar('sem custo: so o preco', [semCusto.preco, semCusto.fator, semCusto.abaixoPiso].join('|'), '3.43||false');

checar('0% nao e promocao', P.avaliar(4.29, 2.25, 1.15, 11.68, 0), null);
checar('91% e dedo escorregando', P.avaliar(4.29, 2.25, 1.15, 11.68, 91), null);
checar('sem preco nao ha desconto', P.avaliar(0, 2.25, 1.15, 11.68, 20), null);

console.log(`\n${ok} passaram, ${falhou} falharam`);
process.exit(falhou > 0 ? 1 : 0);
