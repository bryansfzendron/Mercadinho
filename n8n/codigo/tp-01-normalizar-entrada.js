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

return [{
    json: {
        token: String(corpo.token || ''),
        callback_url,
        email,
        senha,
        // Quando vem vazio, sincroniza todos os pontos de venda.
        pos_ids: Array.isArray(corpo.pos_ids) ? corpo.pos_ids.map(Number) : [],
    },
}];
