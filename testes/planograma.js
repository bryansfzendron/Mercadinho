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

// ----------------------------------------------- o preco que a conta da
// Custo e taxa sao os dois campos que nao vao para o TouchPay: o que sai
// deles e o preco, e so o preco viaja.
checar('custo vezes taxa', P.precoSugerido(9.5, 1.9), 18.05);
checar('taxa 1 devolve o proprio custo', P.precoSugerido(7.3, 1), 7.3);
checar('sobra de float nao vira meio centavo', P.precoSugerido(0.1, 3), 0.3);
checar('meio centavo sobe', P.precoSugerido(1.11, 1.5), 1.67);
checar('o que ja e exato fica', P.precoSugerido(2.5, 1.5), 3.75);
// A tela recebe texto: e o mesmo caminho que o dedo percorre.
checar('digitado com virgula', P.precoSugerido(P.numero('12,90'), P.numero('1,9')), 24.51);

// Faltando um dos dois, null: "nao mexe no preco". Zero aqui seria o produto
// saindo de graca no caixa.
checar('sem custo nao calcula', P.precoSugerido(null, 1.9), null);
checar('sem taxa nao calcula', P.precoSugerido(9.5, null), null);
checar('custo em branco nao zera o preco', P.precoSugerido(P.numero(''), 1.9), null);
checar('custo zero nao vira etiqueta de graca', P.precoSugerido(0, 1.9), null);
checar('taxa zero nao vira etiqueta de graca', P.precoSugerido(9.5, 0), null);
checar('taxa negativa nao passa', P.precoSugerido(9.5, -1), null);

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

// -------------------------------------------------------- a validade
// A data chega de tres lugares: do TouchPay, do <input type="date"> e do
// dedo quando o campo de data nao existe no aparelho.
checar('do jeito que o TouchPay manda', P.dataIso('2027-02-14T00:00:00Z'), '2027-02-14');
checar('do campo de data', P.dataIso('2027-02-14'), '2027-02-14');
checar('do dedo, em portugues', P.dataIso('14/02/2027'), '2027-02-14');
checar('dia que nao existe e null', P.dataIso('2027-02-31'), null);
checar('bissexto de verdade passa', P.dataIso('2028-02-29'), '2028-02-29');
checar('bissexto falso nao passa', P.dataIso('2027-02-29'), null);
// Vazio e "nao encostei nesta validade", nunca "apague a validade".
checar('vazio e null', P.dataIso(''), null);
checar('texto solto e null', P.dataIso('amanha'), null);
checar('data em portugues', P.dataBr('2027-02-14'), '14/02/2027');
checar('sem data, travessao', P.dataBr(null), '—');

// O ano 5027 existe de verdade no inventario deles.
const HOJE = '2026-09-22';
checar('daqui a um ano passa', P.validadePlausivel('2027-09-22', HOJE), true);
checar('ontem passa: cadastrar o que venceu e uso legitimo',
    P.validadePlausivel('2026-09-21', HOJE), true);
checar('o ano 5027 nao passa', P.validadePlausivel('5027-02-18', HOJE), false);
checar('onze anos nao passa', P.validadePlausivel('2037-10-01', HOJE), false);
checar('tres anos atras nao passa', P.validadePlausivel('2023-01-01', HOJE), false);

// A regra do corredor: vale a que vence PRIMEIRO. Repor com lote novo nao
// pode empurrar a data para a frente e esconder o pacote velho la atras.
checar('sem nada cadastrado, grava',
    P.validadeDecidir(null, '2027-06-30').acao, 'gravar');
checar('a nova vence antes: grava',
    P.validadeDecidir('2027-06-30', '2027-02-14').acao, 'gravar');
checar('a do estoque vence antes: mantem',
    P.validadeDecidir('2027-02-14', '2027-06-30').acao, 'manter');
checar('mantendo, a data que fica e a antiga',
    P.validadeDecidir('2027-02-14', '2027-06-30').data, '2027-02-14');
checar('data igual nao e alteracao',
    P.validadeDecidir('2027-02-14', '2027-02-14').acao, 'nada');
checar('sem digitar nada, nada acontece',
    P.validadeDecidir('2027-02-14', null).acao, 'nada');
// A recomendada e a que vem marcada na tela — mas so marcada.
checar('a recomendacao acompanha a acao',
    P.validadeDecidir('2027-02-14', '2027-06-30').recomendado, 'manter');

checar('a frase da validade', P.fraseValidade('2027-02-14', '2027-06-30'),
    'Validade 14/02/2027 → 30/06/2027');
checar('sem validade antes, o travessao aparece',
    P.fraseValidade(null, '2027-06-30'), 'Validade — → 30/06/2027');

// ------------------------------- o resumo nao pode descrever o passado
// O bug: com o resumo na tela os campos continuam editaveis, e Confirmar
// mandava a fotografia tirada la no Salvar. Agora o resumo cai quando o
// campo muda, e esta funcao e quem sabe dizer se mudou.
const foto = { preco: 9.5, estoque: 4, necessaria: 12, critico: 3 };
checar('nada mexido: o resumo continua valendo',
    P.mesmosCampos(foto, { preco: 9.5, estoque: 4, necessaria: 12, critico: 3 }), true);
checar('um centavo ja derruba o resumo',
    P.mesmosCampos(foto, { preco: 9.51, estoque: 4, necessaria: 12, critico: 3 }), false);
checar('estoque mexido derruba',
    P.mesmosCampos(foto, { preco: 9.5, estoque: 10, necessaria: 12, critico: 3 }), false);
// Folga de float: reabrir a conferencia a cada bit seria a tela piscando.
checar('sobra de float nao derruba',
    P.mesmosCampos({ preco: 9.1 }, { preco: 9.1000001 }), true);
// Apagar o campo depois de pedir o resumo e mudar de ideia, nao "deixa como esta".
checar('apagar o preco derruba',
    P.mesmosCampos({ preco: 9.5 }, { preco: null }), false);
checar('preencher o que estava vazio derruba',
    P.mesmosCampos({ preco: null }, { preco: 9.5 }), false);
checar('vazio dos dois lados continua igual',
    P.mesmosCampos({ preco: null }, { preco: null }), true);
// Zero digitado e um valor, nao ausencia — a regra vale aqui tambem.
checar('zero nao se confunde com vazio',
    P.mesmosCampos({ estoque: 0 }, { estoque: null }), false);

console.log(`\n${ok} passaram, ${falhou} falharam`);
process.exit(falhou > 0 ? 1 : 0);
