// A Consulta Resumida responde 302, mas o corpo ja traz o formulario com os
// tokens do ASP.NET. Por isso o node anterior nao segue o redirecionamento.
const resposta = $input.first().json;
const anterior = $('Extrair Cookies').first().json;
const html = String(resposta.body || '');

function decodificarAtributo(valor) {
    return String(valor)
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
}

function campoOculto(nome) {
    const tag = html.match(new RegExp('<input[^>]*(?:name|id)="' + nome + '"[^>]*>', 'i'));
    if (!tag) {
        return '';
    }
    const valor = tag[0].match(/value="([^"]*)"/i);
    return valor ? decodificarAtributo(valor[1]) : '';
}

const viewstate = campoOculto('__VIEWSTATE');
const gerador = campoOculto('__VIEWSTATEGENERATOR');
const validacao = campoOculto('__EVENTVALIDATION');

if (!viewstate) {
    const erro = html.match(/Erro\(s\)[\s\S]{0,200}/i);
    throw new Error(
        'Nao achei o __VIEWSTATE na Consulta Resumida' +
        (erro ? ' — a SEFAZ respondeu: ' + erro[0].replace(/<[^>]*>/g, ' ').trim() : '') + '.'
    );
}

return [{
    json: {
        ...anterior,
        viewstate,
        // 2CB5E186 e o gerador observado nessa pagina; serve de reserva.
        gerador: gerador || '2CB5E186',
        validacao,
    },
}];
