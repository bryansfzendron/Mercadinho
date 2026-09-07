// Para cada ponto de venda junta duas fontes:
//  - /api/Planograms/{id}      -> preco de venda por produto (entries.items[].price)
//  - /api/web/inventory/items  -> estoque atual e codigo de barras (paginado)
// A chave do casamento e o productId; o EAN so existe do lado do estoque.
//
// Sai UM item por ponto de venda, entao o node seguinte posta um lote por PDV
// e o PHP troca as linhas daquele PDV numa transacao so.
const resposta = $input.first().json;
const entrada = $('Pegar Token').first().json;

const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
const base = 'https://touchpay.market';

// Conferido contra a API: com 10000 o PDV maior (1211 itens) volta inteiro
// numa requisicao so, em ~400ms. O laco de paginacao continua abaixo para o
// caso de o servidor passar a limitar a pagina.
const POR_PAGINA = 10000;
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

// Parte dos codigos do TouchPay vem com o prefixo "OM" grudado no codigo de
// barras ("OM7896007811021"). Tirar as duas letras deixa o EAN exato, e e o
// que permite casar o planograma (que so tem productCode) com o estoque.
function semPrefixo(valor) {
    const texto = String(valor == null ? '' : valor).trim();
    const m = texto.match(/^OM(\d{6,14})$/i);
    return m ? m[1] : texto;
}

function ehCodigoDeBarras(valor) {
    return /^\d{8}$|^\d{12,14}$/.test(String(valor || ''));
}

const pdvsBrutos = resposta.data || resposta.body || [];
if (!Array.isArray(pdvsBrutos) || pdvsBrutos.length === 0) {
    throw new Error('O TouchPay nao devolveu nenhum ponto de venda.');
}

const filtro = Array.isArray(entrada.pos_ids) ? entrada.pos_ids : [];
const pdvs = filtro.length ? pdvsBrutos.filter((p) => filtro.includes(p.id)) : pdvsBrutos;

// O inventario e pedido para um instante. Antes ia a meia-noite do dia UTC
// ("2026-09-07T00:00:00.000Z"), o que trazia a foto do estoque no comeco do
// dia: o numero nao mexia conforme o pessoal comprava. E depois das 21h de
// Brasilia o dia UTC ja e o seguinte, entao pedia um dia que nem comecou.
// Agora vai o instante de agora, que e o que a tela do TouchPay mostra.
const agora = new Date().toISOString();
const saida = [];

for (const pdv of pdvs) {
    // ---- precos do planograma ativo ----
    // Indexado por productId e tambem pelo codigo sem o "OM": o planograma nao
    // traz o EAN, entao o codigo limpo e a segunda via de casamento.
    const precos = new Map();
    const precosPorCodigo = new Map();
    const extras = new Map();
    if (pdv.currentPlanogramId) {
        const plano = await pegar.call(this, '/api/Planograms/' + pdv.currentPlanogramId);
        const linhas = (plano && plano.entries && plano.entries.items) || [];
        for (const linha of linhas) {
            const dados = {
                preco: linha.price,
                minimo: linha.minimumQuantity,
                capacidade: linha.capacity,
                imagem: linha.productImageUrl || null,
            };
            precos.set(linha.productId, dados);
            const codigo = semPrefixo(linha.productCode);
            if (codigo) {
                precosPorCodigo.set(codigo, dados);
            }
            extras.set(linha.productId, dados);
        }
    }

    // ---- estoque do PDV ----
    // O TouchPay aceita pageSize alto: o PDV maior (1211 itens) vem inteiro
    // numa requisicao so. A paginacao continua aqui de proposito, para o caso
    // de o servidor decidir limitar a pagina por conta propria.
    const inventarioId = pdv.inventoryId || pdv.id;
    const itens = [];
    let pagina = 1;
    let total = null;
    while (pagina <= 40) {
        const url =
            '/api/web/inventory/items?page=' + pagina + '&pageSize=' + POR_PAGINA +
            '&sortOrder=quantity&descending=false&search=&inventoryIds=' + inventarioId +
            '&productId=&inventoryTypes=pointOfSale&date=' + encodeURIComponent(agora) +
            '&timezoneOffset=180&showTotals=false';
        const pag = await pegar.call(this, url);
        const lista = (pag && pag.items) || [];
        total = pag && typeof pag.totalItems === 'number' ? pag.totalItems : total;
        itens.push(...lista);

        // Quem manda na parada e o totalItems (vem mesmo com showTotals=false).
        // Contar pelo tamanho da pagina pararia cedo demais justamente no caso
        // que importa: o servidor devolver menos do que o pageSize pedido.
        if (lista.length === 0) {
            break;
        }
        if (total !== null ? itens.length >= total : lista.length < POR_PAGINA) {
            break;
        }
        pagina++;
    }

    let semPreco = 0;
    const linhas = itens.map((item) => {
        const codigo = semPrefixo(item.productCode);
        // productBarCode e a fonte boa do EAN; quando falta, o codigo sem "OM"
        // ja e o proprio codigo de barras.
        const eanBruto = String(item.productBarCode || '').trim();
        const ean = eanBruto || (ehCodigoDeBarras(codigo) ? codigo : '');

        const dados = precos.get(item.productId) || precosPorCodigo.get(codigo) || null;
        if (!dados) {
            semPreco++;
        }

        return {
            produto_id_externo: item.productId,
            ean,
            codigo,
            descricao: String(item.productDescription || '').trim(),
            categoria: String(item.productCategoryName || '').trim(),
            // O preco de venda vem do planograma; sem planograma fica nulo.
            preco: dados ? dados.preco : null,
            estoque: item.quantity,
            reservado: item.reservedQuantity,
            custo_medio: item.averageCost,
            unidade: item.productConversionUnitName || null,
            minimo: dados && dados.minimo != null ? dados.minimo : null,
            capacidade: dados && dados.capacidade != null ? dados.capacidade : null,
            imagem: (dados && dados.imagem) || null,
            validade: item.productExpirationDate || null,
        };
    });

    saida.push({
        json: {
            callback_url: entrada.callback_url,
            payload: {
                token: entrada.token,
                status: 'ok',
                fonte: 'touchpay',
                // Numera o POST para o app poder desenhar barra de progresso.
                // O total so e conhecido depois do laco; preenchido abaixo.
                lote: saida.length + 1,
                pos: {
                    id: pdv.id,
                    nome: pdv.localName || pdv.localCustomerName || ('PDV ' + pdv.id),
                    tipo: pdv.posType || null,
                    inventario_id: inventarioId,
                    planograma_id: pdv.currentPlanogramId || null,
                },
                consultado_em: new Date().toISOString(),
                // Diagnostico: quantos itens do estoque nao acharam preco.
                sem_preco: semPreco,
                sem_ean: linhas.filter((l) => !l.ean).length,
                itens: linhas,
            },
        },
    });
}

if (saida.length === 0) {
    throw new Error('Nenhum ponto de venda restou depois do filtro pos_ids.');
}

// So aqui se sabe quantos PDVs sobraram no total.
for (const item of saida) {
    item.json.payload.lotes = saida.length;
}

return saida;
