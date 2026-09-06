/*
 * Roda o Code node "Coletar Precos e Estoque" contra uma API do TouchPay
 * falsa. Cobre o que ja mordeu ou pode morder:
 *
 *  - o codigo vem com o prefixo "OM" grudado no codigo de barras;
 *  - o planograma nao tem EAN, so productCode: o casamento precisa da via
 *    do productId E da via do codigo limpo;
 *  - o inventario e paginado de 500 em 500;
 *  - a lista de PDVs chega em "body" (JSON) e nao em "data";
 *  - sai um item por ponto de venda, nao um por produto.
 */
const fs = require('fs');
const path = require('path');

const CODIGO = fs.readFileSync(path.join(__dirname, 'codigo', 'tp-03-coletar.js'), 'utf8');

let falhas = 0;
function checar(nome, obtido, esperado) {
    const a = JSON.stringify(obtido);
    const b = JSON.stringify(esperado);
    if (a === b) return;
    falhas++;
    console.log(`FALHOU  ${nome}\n   esperado: ${b}\n   obtido:   ${a}`);
}

/** Item do inventario, como /api/web/inventory/items devolve. */
function itemEstoque(n, extra = {}) {
    return Object.assign({
        id: 1000 + n,
        productId: n,
        productDescription: 'PRODUTO ' + n,
        productCode: 'OM789000000000' + (n % 10),
        productBarCode: '789000000000' + (n % 10),
        productCategoryName: 'CATEGORIA',
        quantity: n,
        reservedQuantity: 0,
        averageCost: 1.5,
        productConversionUnitName: 'Unit',
        productExpirationDate: null,
    }, extra);
}

/** Entrada do planograma, como /api/Planograms/{id} devolve. */
function entradaPlano(n, extra = {}) {
    return Object.assign({
        planogramId: 900,
        productId: n,
        productCode: 'OM789000000000' + (n % 10),
        productDescription: 'PRODUTO ' + n,
        price: 10 + n,
        minimumQuantity: 2,
        capacity: 6,
        productImageUrl: 'https://exemplo/img/' + n + '.jpg',
    }, extra);
}

// 501 itens no PDV 1: obriga a paginacao a buscar a segunda pagina.
const ESTOQUE_1 = [];
for (let i = 1; i <= 501; i++) {
    ESTOQUE_1.push(itemEstoque(i));
}
// Um item sem codigo de barras: o EAN tem que sair do codigo sem "OM".
ESTOQUE_1[0] = itemEstoque(1, { productBarCode: '', productCode: 'OM7891000315507' });
// Um item cujo productId nao existe no planograma: so casa pelo codigo limpo.
ESTOQUE_1[1] = itemEstoque(2, { productId: 9999, productCode: 'OM7896007811021', productBarCode: '7896007811021' });
// Um item que nao esta no planograma de jeito nenhum: fica sem preco.
ESTOQUE_1[2] = itemEstoque(3, { productId: 8888, productCode: '1112223334445', productBarCode: '1112223334445' });

const PLANO_1 = [];
for (let i = 1; i <= 501; i++) {
    PLANO_1.push(entradaPlano(i));
}
PLANO_1[1] = entradaPlano(2, { productId: 7777, productCode: 'OM7896007811021', price: 42 });
PLANO_1[2] = entradaPlano(3, { productId: 7776, productCode: 'OUTRO-CODIGO', price: 99 });

const PDVS = [
    { id: 529, localName: 'Itália', posType: 'MicroMarket', inventoryId: 529, currentPlanogramId: 96658 },
    { id: 628, localName: 'Nature', posType: 'MicroMarket', inventoryId: 628, currentPlanogramId: 95701 },
];

const chamadas = [];

/** API falsa: responde planograma e inventario paginado. */
function apiFalsa(opcoes) {
    const url = opcoes.url;
    chamadas.push(url);

    if (url.includes('/api/Planograms/96658')) {
        return Promise.resolve({ id: 96658, status: 'Active', entries: { items: PLANO_1, totalItems: PLANO_1.length } });
    }
    if (url.includes('/api/Planograms/95701')) {
        return Promise.resolve({ id: 95701, status: 'Active', entries: { items: [entradaPlano(1)], totalItems: 1 } });
    }
    if (url.includes('inventoryIds=529')) {
        const pagina = Number((url.match(/[?&]page=(\d+)/) || [])[1] || 1);
        const inicio = (pagina - 1) * 500;
        return Promise.resolve({ totalItems: ESTOQUE_1.length, items: ESTOQUE_1.slice(inicio, inicio + 500) });
    }
    if (url.includes('inventoryIds=628')) {
        return Promise.resolve({ totalItems: 1, items: [itemEstoque(1)] });
    }
    throw new Error('URL inesperada no teste: ' + url);
}

function rodar(entrada, respostaPdvs) {
    const contexto = { helpers: { httpRequest: apiFalsa } };
    const $input = { first: () => ({ json: respostaPdvs }) };
    const $ = () => ({ first: () => ({ json: entrada }) });
    const fn = new Function(
        '$input', '$',
        '"use strict"; return (async () => {' + CODIGO + '\n})();'
    );
    return fn.call(contexto, $input, $);
}

const ENTRADA = {
    token: 'segredo',
    callback_url: 'https://mercadinho.bryanzendron.com.br/api/loja/callback',
    jwt: 'jwt-falso',
    pos_ids: [],
};

(async () => {
    // ---- a) os dois PDVs, lista chegando em "body" ----
    const saida = await rodar(ENTRADA, { body: PDVS, statusCode: 200 });
    checar('um item por ponto de venda', saida.length, 2);

    const p1 = saida[0].json.payload;
    checar('callback_url repassado', saida[0].json.callback_url, ENTRADA.callback_url);
    checar('token repassado', p1.token, 'segredo');
    checar('nome do PDV', p1.pos.nome, 'Itália');
    checar('planograma do PDV', p1.pos.planograma_id, 96658);
    checar('pede a pagina grande', chamadas.some((u) => u.includes('pageSize=10000')), true);
    // A API falsa devolve no maximo 500 por pagina, de proposito: e o caso do
    // servidor limitar a pagina por conta propria. O laco tem que continuar
    // paginando pelo totalItems em vez de parar na primeira pagina curta.
    checar('servidor limitando a pagina nao perde item', p1.itens.length, 501);

    // ---- b) prefixo OM ----
    checar('nenhum codigo sai com OM', p1.itens.filter((i) => /^OM/i.test(i.codigo)).length, 0);
    checar('nenhum ean sai com OM', p1.itens.filter((i) => /^OM/i.test(i.ean)).length, 0);

    const semBarra = p1.itens.find((i) => i.produto_id_externo === 1);
    checar('ean vem do codigo limpo quando falta productBarCode', semBarra.ean, '7891000315507');
    checar('codigo limpo', semBarra.codigo, '7891000315507');

    // ---- c) as duas vias de casamento do preco ----
    const porId = p1.itens.find((i) => i.produto_id_externo === 4);
    checar('preco casado por productId', porId.preco, 14);

    const porCodigo = p1.itens.find((i) => i.produto_id_externo === 9999);
    checar('preco casado pelo codigo sem OM', porCodigo.preco, 42);
    checar('ean do casado por codigo', porCodigo.ean, '7896007811021');

    const orfao = p1.itens.find((i) => i.produto_id_externo === 8888);
    checar('sem planograma fica sem preco', orfao.preco, null);
    checar('contador de sem preco', p1.sem_preco, 1);
    checar('contador de sem ean', p1.sem_ean, 0);

    // ---- d) campos que a tela de bipar usa ----
    checar('estoque', porId.estoque, 4);
    checar('custo medio', porId.custo_medio, 1.5);
    checar('unidade', porId.unidade, 'Unit');
    checar('minimo do planograma', porId.minimo, 2);

    // ---- e) o segundo PDV nao herda os dados do primeiro ----
    const p2 = saida[1].json.payload;
    checar('PDV 2 tem os seus itens', p2.itens.length, 1);
    checar('PDV 2 nome', p2.pos.nome, 'Nature');

    // ---- f) filtro de pos_ids ----
    chamadas.length = 0;
    const soUm = await rodar({ ...ENTRADA, pos_ids: [628] }, { body: PDVS, statusCode: 200 });
    checar('filtro pos_ids deixa so um PDV', soUm.length, 1);
    checar('filtro pegou o PDV certo', soUm[0].json.payload.pos.id, 628);
    checar('nao consultou o PDV filtrado fora',
        chamadas.filter((u) => u.includes('529')).length, 0);

    // ---- g) lista de PDVs vazia vira erro claro ----
    let erro = '';
    try {
        await rodar(ENTRADA, { body: [], statusCode: 200 });
    } catch (e) {
        erro = e.message;
    }
    checar('lista vazia explica o problema', /nenhum ponto de venda/i.test(erro), true);

    console.log(falhas === 0 ? '\nTodos os testes do TouchPay passaram.' : `\n${falhas} falha(s).`);
    process.exit(falhas ? 1 : 0);
})();
