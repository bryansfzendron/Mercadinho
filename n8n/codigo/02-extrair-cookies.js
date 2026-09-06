// O n8n nao compartilha cookie jar entre nodes HTTP Request, entao a sessao
// ASP.NET da SEFAZ (ASP.NET_SessionId + __AntiXsrfToken) e montada aqui na mao.
const resposta = $input.first().json;
const entrada = $('Normalizar Entrada').first().json;

const cabecalhos = resposta.headers || {};
const bruto = cabecalhos['set-cookie'] || cabecalhos['Set-Cookie'] || [];
const lista = Array.isArray(bruto) ? bruto : [bruto];

const jar = {};
for (const linha of lista) {
    const par = String(linha).split(';')[0];
    const i = par.indexOf('=');
    if (i > 0) {
        jar[par.slice(0, i).trim()] = par.slice(i + 1).trim();
    }
}

const cookies = Object.entries(jar).map(([k, v]) => k + '=' + v).join('; ');

if (!cookies) {
    throw new Error(
        'A SEFAZ nao devolveu cookies de sessao (HTTP ' + (resposta.statusCode || '?') + '). ' +
        'O QR Code pode estar com hash invalido ou o portal esta fora do ar.'
    );
}

return [{ json: { ...entrada, cookies } }];
