/*
 * Roda o Code node "Coletar Vendas" contra uma API do TouchPay falsa.
 * Cobre o que ja mordeu ou pode morder:
 *
 *  - price/paymentAmount do item sao o TOTAL da linha, nao o unitario;
 *  - subtractedItems (o que o cliente devolveu) nao pode virar venda;
 *  - o codigo vem com o prefixo "OM" grudado no codigo de barras;
 *  - item sem productCode existe de verdade e nao pode virar EAN vazio "0";
 *  - a listagem e paginada e quem manda na parada e o totalItems;
 *  - as vendas saem em lotes, para o callback caber nos 30s da hospedagem.
 */
const fs = require('fs');
const path = require('path');

const CODIGO = fs.readFileSync(path.join(__dirname, 'codigo', 'vd-03-coletar-vendas.js'), 'utf8');

let falhas = 0;
function checar(nome, obtido, esperado) {
    const a = JSON.stringify(obtido);
    const b = JSON.stringify(esperado);
    if (a === b) return;
    falhas++;
    console.log(`FALHOU  ${nome}\n   esperado: ${b}\n   obtido:   ${a}`);
}

/** Uma transacao como /api/Transactions devolve. */
function transacao(n, extra = {}) {
    return Object.assign({
        id: 500000 + n,
        uuid: 'uuid-' + n,
        date: '2026-09-0' + ((n % 6) + 1) + 'T11:43:16-03:00',
        pointOfSaleId: n % 2 === 0 ? 628 : 529,
        pointOfSaleLocalName: n % 2 === 0 ? 'Nature' : 'Itália',
        result: 'Ok',
        totalPrice: 10,
        paymentAmount: 10,
        paymentMethod: 'Debit',
        cardBrand: 'MASTERCARD',
        friendlyTransactionCode: 'COD' + n,
        items: [{
            productId: 100 + n,
            productCode: 'OM789600781102' + (n % 10),
            productDescription: 'PRODUTO ' + n,
            productCategoryName: 'BEBIDAS 1',
            quantity: 1,
            price: 10,
            paymentAmount: 10,
        }],
        subtractedItems: [],
    }, extra);
}

// 1500 transacoes: obriga a segunda pagina e tres lotes de 500.
const TRANSACOES = [];
for (let i = 1; i <= 1500; i++) {
    TRANSACOES.push(transacao(i));
}

// Item com quantidade 4: o valor que vem e o das 4, nao o de uma.
TRANSACOES[0] = transacao(1, {
    totalPrice: 15.56,
    paymentAmount: 15.56,
    items: [{
        productId: 777,
        productCode: 'OM7894900027013',
        productDescription: 'REFRIGERANTE LATA',
        productCategoryName: 'BEBIDAS 1',
        quantity: 4,
        price: 15.56,
        paymentAmount: 15.56,
    }],
});

// Transacao com item devolvido: o devolvido nao entra na venda.
TRANSACOES[1] = transacao(2, {
    totalPrice: 11.05,
    paymentAmount: 11.05,
    items: [{
        productId: 888,
        productCode: '7891000315507',
        productDescription: 'PAO DE FORMA',
        productCategoryName: 'PADARIA',
        quantity: 1,
        price: 11.05,
        paymentAmount: 11.05,
    }],
    subtractedItems: [{
        totalPrice: 14.99,
        quantity: 1,
        productCode: '7892961190370',
        productDescription: 'BOLO DE POTE',
        productCategoryName: 'CHOCOLATES DOCES GULOSEIMAS 1',
    }],
});

// Item sem productCode: acontece de verdade ("Triunfo tortini morango 90g").
TRANSACOES[2] = transacao(3, {
    items: [{
        productId: 999,
        productCode: null,
        productDescription: 'TRIUNFO TORTINI MORANGO 90G',
        productCategoryName: 'DOCES',
        quantity: 1,
        price: 3.5,
        paymentAmount: 3.5,
    }],
});

// Venda cancelada: entra gravada, com o resultado, para dar para filtrar.
TRANSACOES[3] = transacao(4, { result: 'Denied' });

const chamadas = [];

/** API falsa: pagina de 1000 em 1000, como a de verdade. */
function apiFalsa(opcoes) {
    const url = opcoes.url;
    chamadas.push(url);

    if (url.includes('/api/Transactions')) {
        const pagina = Number((url.match(/[?&]page=(\d+)/) || [])[1] || 1);
        const inicio = (pagina - 1) * 1000;
        return Promise.resolve({
            totalItems: TRANSACOES.length,
            items: TRANSACOES.slice(inicio, inicio + 1000),
        });
    }
    throw new Error('URL inesperada no teste: ' + url);
}

function rodar(entrada, resposta) {
    const contexto = { helpers: { httpRequest: resposta || apiFalsa } };
    const $input = { first: () => ({ json: entrada }) };
    const $ = () => ({ first: () => ({ json: entrada }) });
    const fn = new Function(
        '$input', '$',
        '"use strict"; return (async () => {' + CODIGO + '\n})();'
    );
    return fn.call(contexto, $input, $);
}

const ENTRADA = {
    token: 'segredo',
    callback_url: 'https://mercadinho.bryanzendron.com.br/api/vendas/callback',
    jwt: 'jwt-falso',
    min_date: '2026-08-07',
    max_date: '2026-09-06',
};

(async () => {
    const saida = await rodar(ENTRADA);

    // ---- a) lotes ----
    checar('1500 vendas viram 3 lotes', saida.length, 3);
    checar('lote cheio tem 500', saida[0].json.payload.vendas.length, 500);
    checar('numero do lote', saida[1].json.payload.lote, 2);
    checar('total de lotes', saida[1].json.payload.lotes, 3);
    checar('callback_url repassado', saida[0].json.callback_url, ENTRADA.callback_url);
    checar('token repassado', saida[0].json.payload.token, 'segredo');
    checar('janela repassada', saida[0].json.payload.janela, { de: '2026-08-07', ate: '2026-09-06' });
    checar('total de transacoes', saida[0].json.payload.total_transacoes, 1500);

    // ---- b) paginacao ----
    checar('pediu duas paginas', chamadas.length, 2);
    checar('pede pagina de 1000', chamadas[0].includes('pageSize=1000'), true);
    checar('manda a janela na URL',
        chamadas[0].includes('minDate=2026-08-07') && chamadas[0].includes('maxDate=2026-09-06'), true);

    const vendas = saida.flatMap((s) => s.json.payload.vendas);
    checar('nenhuma venda perdida na paginacao', vendas.length, 1500);

    // ---- c) o valor do item e o TOTAL da linha ----
    const qtd4 = vendas.find((v) => v.id === 500001);
    checar('quantidade preservada', qtd4.itens[0].quantidade, 4);
    checar('valor do item e o total da linha', qtd4.itens[0].valor_total, 15.56);
    checar('a soma dos itens bate com o pago',
        qtd4.itens.reduce((a, i) => a + i.valor_total, 0), qtd4.valor_pago);

    // ---- d) devolucao nao e venda ----
    const comDevolucao = vendas.find((v) => v.id === 500002);
    checar('so o item cobrado entra', comDevolucao.itens.length, 1);
    checar('o devolvido nao aparece',
        comDevolucao.itens.some((i) => /BOLO DE POTE/.test(i.descricao)), false);
    checar('total bate com o cobrado', comDevolucao.valor_pago, 11.05);

    // ---- e) prefixo OM e codigo ausente ----
    checar('nenhum codigo sai com OM', vendas.filter((v) => v.itens.some((i) => /^OM/i.test(i.codigo))).length, 0);
    checar('ean limpo', qtd4.itens[0].ean, '7894900027013');
    const semCodigo = vendas.find((v) => v.id === 500003);
    checar('sem productCode nao inventa ean', semCodigo.itens[0].ean, '');
    checar('sem productCode nao inventa codigo', semCodigo.itens[0].codigo, '');
    checar('sem productCode ainda guarda o id externo', semCodigo.itens[0].produto_id_externo, 999);

    // ---- f) campos da venda ----
    const uma = vendas.find((v) => v.id === 500002);
    checar('pdv', [uma.pdv_id, uma.pdv_nome], [628, 'Nature']);
    checar('forma de pagamento', uma.forma_pagamento, 'Debit');
    checar('bandeira', uma.bandeira, 'MASTERCARD');
    checar('data crua repassada', uma.data, '2026-09-03T11:43:16-03:00');
    const negada = vendas.find((v) => v.id === 500004);
    checar('venda negada entra com o resultado', negada.resultado, 'Denied');

    // ---- g) janela sem venda nenhuma ----
    chamadas.length = 0;
    const vazio = await rodar(ENTRADA, () => Promise.resolve({ totalItems: 0, items: [] }));
    checar('janela vazia manda um lote so', vazio.length, 1);
    checar('lote vazio nao inventa venda', vazio[0].json.payload.vendas.length, 0);

    // ---- h) sem janela e erro claro ----
    let erro = '';
    try {
        await rodar({ ...ENTRADA, min_date: '', max_date: '' });
    } catch (e) {
        erro = e.message;
    }
    checar('sem janela explica o problema', /janela de datas/i.test(erro), true);


// ---- i) o node que confere a gravacao ----
// Lote perdido some calado (o POST usa neverError): este node existe para
// transformar isso em execucao com erro.
{
    const CONFERIR = fs.readFileSync(path.join(__dirname, 'codigo', 'vd-04-conferir.js'), 'utf8');
    const conferir = (itens) => {
        const $input = { all: () => itens.map((json) => ({ json })) };
        return new Function('$input', '"use strict";' + CONFERIR)($input);
    };
    const ok = (extra = {}) => Object.assign({
        statusCode: 200,
        body: { ok: true, vendas: 500, itens: 800, mensagem: 'gravado' },
    }, extra);

    const bom = conferir([
        ok(),
        ok({ body: { ok: true, vendas: 23, itens: 40, esperado: 523, gravado: 523, completo: true } }),
    ]);
    checar('conferir: soma as vendas dos lotes', bom[0].json.vendas, 523);
    checar('conferir: passa a conferencia adiante', bom[0].json.completo, true);

    let erro = '';
    try { conferir([ok(), ok({ statusCode: 401, body: { erro: 'token invalido' } })]); }
    catch (e) { erro = e.message; }
    checar('conferir: 401 vira erro', /HTTP 401/.test(erro), true, erro);
    checar('conferir: erro ensina o conserto', /idempotente/.test(erro), true);

    erro = '';
    try { conferir([ok({ body: { ok: false, mensagem: 'lote sem venda utilizavel' } })]); }
    catch (e) { erro = e.message; }
    checar('conferir: recusa do app vira erro', /recusou/.test(erro), true, erro);

    erro = '';
    try {
        conferir([ok({ body: { ok: true, vendas: 100, itens: 160, esperado: 523, gravado: 400, completo: false } })]);
    } catch (e) { erro = e.message; }
    checar('conferir: total que nao fecha vira erro', /Faltaram 123/.test(erro), true, erro);

    // Sync incremental sem novidade nao pode virar alarme falso.
    const vazio = conferir([ok({ body: { ok: true, vendas: 0, itens: 0, mensagem: 'nada novo' } })]);
    checar('conferir: janela vazia passa limpo', vazio[0].json.vendas, 0);
}

    console.log(falhas === 0 ? '\nTodos os testes de vendas passaram.' : `\n${falhas} falha(s).`);
    process.exit(falhas ? 1 : 0);
})();
