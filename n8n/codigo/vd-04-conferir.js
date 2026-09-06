// Confere o que o Mercadinho respondeu em cada lote.
//
// Sem este node, lote perdido some calado: o "Devolver ao Mercadinho" usa
// neverError, entao um 401 (token errado) ou um 500 no meio da carga passa
// como se tivesse dado certo, e o relatorio nasce faltando venda sem ninguem
// perceber. Aqui a falha vira execucao com erro, que fica guardada.
const lotes = $input.all();

const problemas = [];
let vendas = 0;
let itens = 0;
let conferencia = null;

for (const lote of lotes) {
    const r = lote.json || {};
    const status = r.statusCode || 0;
    const corpo = r.body || {};

    if (status < 200 || status >= 300) {
        problemas.push(
            'lote ' + (problemas.length + 1) + ' respondeu HTTP ' + status +
            ' (' + JSON.stringify(corpo).slice(0, 200) + ')'
        );
        continue;
    }
    if (corpo.ok === false) {
        problemas.push('o Mercadinho recusou um lote: ' + (corpo.mensagem || corpo.erro || '?'));
        continue;
    }

    vendas += Number(corpo.vendas || 0);
    itens += Number(corpo.itens || 0);

    // O ultimo lote traz a conferencia do total da janela.
    if (corpo.esperado) {
        conferencia = corpo;
    }
}

if (problemas.length) {
    throw new Error(
        'A carga de vendas nao chegou inteira: ' + problemas.join(' | ') +
        '. Reenvie o periodo — a gravacao e idempotente, nao duplica.'
    );
}

if (conferencia && conferencia.completo === false) {
    throw new Error(
        'O TouchPay tinha ' + conferencia.esperado + ' transacoes na janela e o banco ficou com ' +
        conferencia.gravado + '. Faltaram ' + (conferencia.esperado - conferencia.gravado) +
        '. Reenvie o periodo — a gravacao e idempotente, nao duplica.'
    );
}

return [{
    json: {
        lotes: lotes.length,
        vendas,
        itens,
        esperado: conferencia ? conferencia.esperado : null,
        gravado: conferencia ? conferencia.gravado : null,
        completo: conferencia ? conferencia.completo : null,
    },
}];
