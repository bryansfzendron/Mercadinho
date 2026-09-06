// O login do TouchPay responde 200 com CORPO VAZIO: o JWT vem no header
// "authorization". Procurar no body devolve nada.
const resposta = $input.first().json;
const entrada = $('Normalizar Entrada').first().json;

const cabecalhos = resposta.headers || {};
const bruto = cabecalhos.authorization || cabecalhos.Authorization || '';
const jwt = String(bruto).replace(/^Bearer\s+/i, '').trim();

if (!jwt) {
    throw new Error(
        'O TouchPay nao devolveu o header authorization (HTTP ' +
        (resposta.statusCode || '?') + '). Confira email e senha no config.php.'
    );
}

return [{ json: { ...entrada, jwt } }];
