// O Mercadinho dispara este fluxo mandando as credenciais do TouchPay no
// corpo. Elas moram no config.php (fora do git), nunca aqui no workflow.
const bruto = $input.first().json;
const corpo = bruto.body && typeof bruto.body === 'object' ? bruto.body : bruto;

const email = String(corpo.email || '').trim();
const senha = String(corpo.senha || '');
const callback_url = String(corpo.callback_url || '').trim();

if (!email || !senha) {
    throw new Error('Faltou email ou senha do TouchPay no corpo da requisicao.');
}
if (!callback_url) {
    throw new Error('Faltou callback_url no corpo da requisicao.');
}

// Janela de datas: so o fluxo de vendas usa. O do espelho manda vazio e
// segue a vida, por isso nao e obrigatoria aqui.
const data = (valor) => (/^\d{4}-\d{2}-\d{2}$/.test(String(valor || '')) ? String(valor) : '');
const min_date = data(corpo.min_date);
const max_date = data(corpo.max_date);

if ((corpo.min_date || corpo.max_date) && (!min_date || !max_date)) {
    throw new Error('min_date e max_date precisam vir as duas no formato AAAA-MM-DD.');
}

return [{
    json: {
        token: String(corpo.token || ''),
        callback_url,
        email,
        senha,
        // Quando vem vazio, sincroniza todos os pontos de venda.
        pos_ids: Array.isArray(corpo.pos_ids) ? corpo.pos_ids.map(Number) : [],
        min_date,
        max_date,
    },
}];
