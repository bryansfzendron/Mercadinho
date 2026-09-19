/*
 * A conta da tela Mercado: o que o dedo digita, o total da lista e o veredito
 * contra o caixa. Roda no Node, sem navegador — o arquivo separa a conta do
 * desenho justamente para isto.
 *
 *   node testes/mercado.js
 */
const M = require('../assets/mercado.js');

let ok = 0, falhou = 0;
function checar(nome, obtido, esperado) {
    if (obtido === esperado) { ok++; return; }
    falhou++;
    console.log(`FALHOU  ${nome}\n   esperado: ${JSON.stringify(esperado)}\n   obtido:   ${JSON.stringify(obtido)}`);
}

// ------------------------------------------------- o que o dedo digita
checar('virgula decimal', M.centavos('3,89'), 389);
checar('ponto decimal (teclado manda os dois)', M.centavos('3.89'), 389);
checar('com R$ junto', M.centavos('R$ 12,90'), 1290);
checar('ponto de milhar com virgula decimal', M.centavos('1.234,56'), 123456);
checar('inteiro sem decimal', M.centavos('7'), 700);
checar('campo vazio nao vira preco', M.centavos(''), 0);
checar('texto sem numero nao vira preco', M.centavos('abc'), 0);
// Meio centavo existe em etiqueta de granel; a lista trabalha em centavos.
checar('arredonda para o centavo', M.centavos('1,005'), 101);

// ------------------------------------------------------------ o total
const lista = [
    { codigo: '789', centavos: 389, qtd: 1 },
    { codigo: '790', centavos: 199, qtd: 3 },
];
checar('soma preco por quantidade', M.total(lista), 389 + 597);
checar('lista vazia soma zero', M.total([]), 0);
checar('lista ausente nao quebra', M.total(undefined), 0);

// O motivo de guardar centavos: 0,10 + 0,20 em float nao da 0,30 exato, e o
// erro apareceria justamente na hora de dizer se a conta bate.
const centavinhos = [
    { centavos: 10, qtd: 1 }, { centavos: 20, qtd: 1 }, { centavos: 30, qtd: 1 },
];
checar('centavos somam sem sobra de float', M.total(centavinhos), 60);

// Produto pesado: 0,756 kg a R$ 24,90 o quilo.
checar('item por peso arredonda no centavo',
    M.total([{ centavos: 2490, qtd: 0.756 }]), 1882);

// ------------------------------------------------------- a conferencia
checar('sem total do caixa nao ha veredito',
    M.conferir(lista, 0).status, 'sem-caixa');
checar('sem total do caixa a diferenca e zero, nao a lista inteira',
    M.conferir(lista, 0).diferenca, 0);

checar('igual bate', M.conferir(lista, 986).status, 'bate');
checar('um centavo de diferenca ainda bate (arredondamento da balanca)',
    M.conferir(lista, 987).status, 'bate');
checar('dois centavos ja e diferenca',
    M.conferir(lista, 988).status, 'caixa-maior');
checar('caixa cobrando mais aparece como caixa-maior',
    M.conferir(lista, 1500).status, 'caixa-maior');
checar('caixa cobrando menos aparece como caixa-menor',
    M.conferir(lista, 500).status, 'caixa-menor');
checar('a diferenca e o caixa menos a lista',
    M.conferir(lista, 1500).diferenca, 1500 - 986);
checar('promocao deixa a diferenca negativa',
    M.conferir(lista, 900).diferenca, 900 - 986);

// -------------------------------------------------------- formatacao
checar('centavos viram real', M.moeda(1882), 'R$ 18,82');
checar('zero tem os dois decimais', M.moeda(0), 'R$ 0,00');
checar('negativo leva o sinal antes do simbolo', M.moeda(-250), '-R$ 2,50');
checar('quantidade inteira sai sem casas', M.qtdTexto(2), '2');
checar('quantidade quebrada sai com virgula', M.qtdTexto(0.756), '0,756');

console.log(`\n${ok} passaram, ${falhou} falharam`);
process.exit(falhou > 0 ? 1 : 0);
