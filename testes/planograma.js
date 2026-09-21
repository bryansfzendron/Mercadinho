/*
 * O resumo de/para da tela de repor: o que o dedo digitou, o que mudou e como
 * a frase sai. E o que aparece entre o "Salvar" e o envio — a unica chance de
 * ver que o preco foi de 9,50 para 950 porque a virgula nao entrou.
 *
 *   node testes/planograma.js
 */
const P = require('../assets/planograma.js');

let ok = 0, falhou = 0;
function checar(nome, obtido, esperado) {
    if (obtido === esperado) { ok++; return; }
    falhou++;
    console.log(`FALHOU  ${nome}\n   esperado: ${JSON.stringify(esperado)}\n   obtido:   ${JSON.stringify(obtido)}`);
}

// ------------------------------------------------- o que o dedo digitou
checar('virgula decimal', P.numero('9,50'), 9.5);
checar('ponto decimal (o teclado manda os dois)', P.numero('9.50'), 9.5);
checar('com R$ junto', P.numero('R$ 12,90'), 12.9);
checar('ponto de milhar com virgula decimal', P.numero('1.234,56'), 1234.56);
checar('inteiro', P.numero('3'), 3);
checar('zero digitado e zero de verdade', P.numero('0'), 0);
// Vazio e null de proposito: quer dizer "nao encostei neste campo". Virar
// zero faria apagar o preco sem querer deixar o produto de graca.
checar('campo vazio e null, nao zero', P.numero(''), null);
checar('so espaco tambem e null', P.numero('   '), null);
checar('undefined nao quebra', P.numero(undefined), null);
checar('texto sem numero e null', P.numero('abc'), null);

// ------------------------------------------------------- formatacao
checar('preco sai com dois decimais', P.moeda(9.5), 'R$ 9,50');
checar('zero tem os dois decimais', P.moeda(0), 'R$ 0,00');
checar('quantidade inteira sai sem casas', P.qtdTexto(3), '3');
checar('quantidade quebrada sai com virgula', P.qtdTexto(0.756), '0,756');
checar('preco usa moeda', P.valorTexto('preco', 9.5), 'R$ 9,50');
checar('quantidade nao usa moeda', P.valorTexto('estoque', 3), '3');

// ---------------------------------------------------------- o que mudou
const antes = { preco: 9, estoque: 0, necessaria: 3, critico: 1 };

checar('nada digitado, nada muda', P.mudancas(antes, {}).length, 0);
checar('mesmo valor nao e alteracao',
    P.mudancas(antes, { preco: 9, estoque: 0 }).length, 0);
checar('folga de float nao vira alteracao',
    P.mudancas({ preco: 9.1 }, { preco: 9.1000001 }).length, 0);
checar('um centavo ja e alteracao',
    P.mudancas({ preco: 9.1 }, { preco: 9.11 }).length, 1);

const m = P.mudancas(antes, { preco: 9.5, estoque: 3 });
checar('duas alteracoes', m.length, 2);
// A ordem e a dos campos na tela: preco e estoque em cima.
checar('preco vem primeiro', m[0].campo, 'preco');
checar('estoque vem depois', m[1].campo, 'estoque');
checar('de', m[0].de, 9);
checar('para', m[0].para, 9.5);

// Campo nao digitado nao pode virar zero: seria zerar o estoque de quem so
// queria mexer no preco.
checar('campo null fica de fora',
    P.mudancas(antes, { preco: 9.5, estoque: null }).length, 1);
// Zerar estoque e alteracao de verdade e precisa passar.
checar('zerar estoque e alteracao',
    P.mudancas({ estoque: 5 }, { estoque: 0 })[0].para, 0);
// Produto novo entra com tudo em zero: o preco digitado tem de aparecer.
checar('produto novo mostra o preco',
    P.mudancas({ preco: null, estoque: 0, necessaria: 0, critico: 0 },
        { preco: 9.9, estoque: 2, necessaria: 0, critico: 0 }).length, 2);

// ------------------------------------------------------------- a frase
checar('a frase do preco', P.frase({ campo: 'preco', de: 9, para: 9.5 }),
    'Preço R$ 9,00 → R$ 9,50');
checar('a frase do estoque', P.frase({ campo: 'estoque', de: 0, para: 3 }),
    'Estoque 0 → 3');
// O erro que o resumo existe para pegar: virgula que nao entrou.
checar('virgula perdida fica visivel', P.frase({ campo: 'preco', de: 9.5, para: 950 }),
    'Preço R$ 9,50 → R$ 950,00');

console.log(`\n${ok} passaram, ${falhou} falharam`);
process.exit(falhou > 0 ? 1 : 0);
