// Clica em "Visualizar em Abas" (POST do btnVisualizarAbas) e devolve a
// Consulta Completa no mesmo formato do HTTP Request com fullResponse:
// { data, headers, statusCode }. Assim o Extrair Itens do Cupom nao muda.
//
// Por que Code e nao HTTP Request: desde 2026-10-24 o firewall da SEFAZ (F5)
// responde "Request Rejected" a este POST quando ele sai do node HTTP Request,
// em 100% das execucoes. O mesmo POST feito aqui com this.helpers.httpRequest,
// do mesmo servidor e com os mesmos cookies, passa. Testado em 2026-10-26 com
// um workflow de diagnostico: nao e o IP nem o corpo do formulario.
const URL_CONSULTA =
    'https://www.nfce.fazenda.sp.gov.br/NFCeConsultaPublica/Paginas/ConsultaResponsiva/ConsultaResumidaRJFrame_v400.aspx';

const dados = $input.first().json;

// Cabecalhos que passaram no teste, letra por letra. Nao acrescentar Origin,
// Cache-Control nem Sec-Fetch de iframe: nada disso foi preciso.
const cabecalhos = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' +
        'Chrome/126.0.0.0 Safari/537.36',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
    'Accept-Language': 'pt-BR,pt;q=0.9',
    'Sec-Fetch-Dest': 'document',
    'Sec-Fetch-Mode': 'navigate',
    'Sec-Fetch-Site': 'none',
    'Upgrade-Insecure-Requests': '1',
    'Cookie': dados.cookies,
    'Referer': URL_CONSULTA,
    'Content-Type': 'application/x-www-form-urlencoded',
};

const corpo =
    '__EVENTTARGET=btnVisualizarAbas' +
    '&__EVENTARGUMENT=' +
    '&__VIEWSTATE=' + encodeURIComponent(dados.viewstate) +
    '&__VIEWSTATEGENERATOR=' + encodeURIComponent(dados.gerador) +
    '&__EVENTVALIDATION=' + encodeURIComponent(dados.validacao);

// O POST responde 302 para ConsultaPublica.aspx; o helper segue sozinho e
// devolve a pagina final (~350 KB).
try {
    const resposta = await this.helpers.httpRequest({
        method: 'POST',
        url: URL_CONSULTA,
        headers: cabecalhos,
        body: corpo,
        json: false,
        encoding: 'text',
        returnFullResponse: true,
        ignoreHttpStatusErrors: true,
    });
    return [{
        json: {
            data: typeof resposta.body === 'string' ? resposta.body : String(resposta.body || ''),
            headers: resposta.headers || {},
            statusCode: resposta.statusCode,
        },
    }];
} catch (e) {
    // Falha de rede vira texto para o Extrair Itens do Cupom transformar em
    // callback de erro: a nota nunca fica presa em "processando".
    return [{ json: { data: '', headers: {}, statusCode: 0, erro: String(e.message || e) } }];
}
