// Vendas do TouchPay: pagina o GET /api/Transactions na janela de datas
// pedida e devolve lotes prontos para o /api/vendas/callback do Mercadinho.
//
// Cada transacao ja vem com os itens dentro, entao uma passada resolve venda
// e item. Nao precisa de /api/PointsOfSale: o PDV vem na propria transacao.
const entrada = $('Pegar Token').first().json;

const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
const base = 'https://touchpay.market';

// Medido contra a conta real: 1000 transacoes em ~600ms, e um ano inteiro
// (12,5 mil) sai em 13 requisicoes.
const POR_PAGINA = 1000;
// Teto de seguranca: 200 paginas cheias sao 200 mil transacoes, muito acima
// de qualquer janela que o app peca.
const MAX_PAGINAS = 200;
// Transacoes por POST de volta. Com 1,6 itens por venda da ~800 linhas por
// lote, que a hospedagem grava bem dentro dos 30s dela.
const POR_LOTE = 500;

const cabecalhos = {
    accept: 'application/json, text/plain, */*',
    authorization: 'Bearer ' + entrada.jwt,
    referer: base + '/',
    'user-agent': NAVEGADOR,
};

async function pegar(caminho) {
    return this.helpers.httpRequest({
        method: 'GET',
        url: base + caminho,
        headers: cabecalhos,
        json: true,
    });
}

// Igual ao fluxo do espelho: parte dos codigos vem com "OM" grudado no
// codigo de barras ("OM7896007811021").
function semPrefixo(valor) {
    const texto = String(valor == null ? '' : valor).trim();
    const m = texto.match(/^OM(\d{6,14})$/i);
    return m ? m[1] : texto;
}

function ehCodigoDeBarras(valor) {
    return /^\d{8}$|^\d{12,14}$/.test(String(valor || ''));
}

const minData = entrada.min_date;
const maxData = entrada.max_date;
if (!minData || !maxData) {
    throw new Error('Faltou a janela de datas (min_date/max_date) para buscar as vendas.');
}

const transacoes = [];
let pagina = 1;
let total = null;

while (pagina <= MAX_PAGINAS) {
    const url =
        '/api/Transactions?customerId=&localId=&pointOfSaleId=&paymentMethod=' +
        '&minAmount=&maxAmount=&cardHolder=&cpf=&minTime=&maxTime=&productId=' +
        '&onlyWithCpf=false&timezoneOffset=180&sortOrder=date&descending=false' +
        '&minDate=' + minData + '&maxDate=' + maxData +
        '&page=' + pagina + '&pageSize=' + POR_PAGINA;

    const resposta = await pegar.call(this, url);
    const lista = (resposta && resposta.items) || [];
    total = resposta && typeof resposta.totalItems === 'number' ? resposta.totalItems : total;
    transacoes.push(...lista);

    // Quem manda na parada e o totalItems. Contar pelo tamanho da pagina
    // pararia cedo se o servidor devolvesse menos do que o pageSize pedido.
    if (lista.length === 0) {
        break;
    }
    if (total !== null ? transacoes.length >= total : lista.length < POR_PAGINA) {
        break;
    }
    pagina++;
}

function linhaDoItem(item) {
    const codigo = semPrefixo(item.productCode);
    return {
        produto_id_externo: item.productId,
        ean: ehCodigoDeBarras(codigo) ? codigo : '',
        codigo,
        descricao: String(item.productDescription || '').trim(),
        categoria: String(item.productCategoryName || '').trim(),
        quantidade: item.quantity == null ? 1 : item.quantity,
        // ATENCAO: price e paymentAmount do item sao o TOTAL da linha, nao o
        // unitario — item com quantidade 4 veio com price 15,56 (3,89 cada).
        // Somar paymentAmount das linhas bate com o total da transacao em
        // 1000 de 1000 casos; multiplicar por quantidade erra em 213.
        valor_total: item.paymentAmount == null ? item.price : item.paymentAmount,
    };
}

const vendas = transacoes.map((t) => ({
    id: t.id,
    uuid: t.uuid || null,
    data: t.date,
    pdv_id: t.pointOfSaleId,
    pdv_nome: t.pointOfSaleLocalName || t.pointOfSaleLocalCustomerName || ('PDV ' + t.pointOfSaleId),
    resultado: t.result || null,
    forma_pagamento: t.paymentMethod || null,
    bandeira: t.cardBrand || null,
    valor_total: t.totalPrice == null ? 0 : t.totalPrice,
    valor_pago: t.paymentAmount == null ? t.totalPrice : t.paymentAmount,
    codigo: t.friendlyTransactionCode || null,
    // subtractedItems ficam de fora de proposito: sao os produtos que o
    // cliente pegou e devolveu, nao entram em items nem no total pago.
    itens: (t.items || []).map(linhaDoItem),
}));

const lotes = [];
for (let i = 0; i < vendas.length; i += POR_LOTE) {
    lotes.push(vendas.slice(i, i + POR_LOTE));
}
// Janela sem venda nenhuma ainda manda um lote vazio: e o que faz o
// Mercadinho responder "nada novo" em vez de o fluxo morrer calado.
if (lotes.length === 0) {
    lotes.push([]);
}

return lotes.map((lote, i) => ({
    json: {
        callback_url: entrada.callback_url,
        payload: {
            token: entrada.token,
            status: 'ok',
            fonte: 'touchpay',
            lote: i + 1,
            lotes: lotes.length,
            janela: { de: minData, ate: maxData },
            consultado_em: new Date().toISOString(),
            total_transacoes: vendas.length,
            vendas: lote,
        },
    },
}));
