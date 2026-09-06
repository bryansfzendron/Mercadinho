/*
 * Monta o JSON do workflow n8n a partir dos arquivos em n8n/codigo/.
 *
 *   node n8n/montar-workflow.js
 *
 * Escrever JavaScript dentro de string JSON na mao e receita de erro de escape;
 * por isso o codigo de cada Code node vive num .js separado e e embutido aqui.
 */
const fs = require('fs');
const path = require('path');

const RAIZ = __dirname;
const CONSULTA_RESUMIDA =
    'https://www.nfce.fazenda.sp.gov.br/NFCeConsultaPublica/Paginas/ConsultaResponsiva/ConsultaResumidaRJFrame_v400.aspx';

const NAVEGADOR =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' +
    'Chrome/126.0.0.0 Safari/537.36';

function codigo(arquivo) {
    return fs.readFileSync(path.join(RAIZ, 'codigo', arquivo), 'utf8');
}

function nodeCode(nome, arquivo, posicao) {
    return {
        parameters: { mode: 'runOnceForAllItems', jsCode: codigo(arquivo) },
        id: nome.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
        name: nome,
        type: 'n8n-nodes-base.code',
        typeVersion: 2,
        position: posicao,
    };
}

/** Resposta completa (status + headers + body) e sem transformar 4xx/5xx em erro. */
const RESPOSTA_COMPLETA = {
    response: { response: { fullResponse: true, neverError: true } },
};

function cabecalhos(lista) {
    return { parameters: lista.map(([name, value]) => ({ name, value })) };
}

const nodes = [
    {
        parameters: {
            httpMethod: 'POST',
            path: 'nfce-sp-mercadinho',
            // Responde na hora: o PHP nao pode ficar bloqueado esperando a raspagem.
            responseMode: 'onReceived',
            options: {},
        },
        id: 'receber-do-mercadinho',
        name: 'Receber do Mercadinho',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2,
        position: [-900, 0],
        webhookId: 'a7f3c1e0-5b2d-4e91-8c6a-3d0f7b1e9a24',
    },

    nodeCode('Normalizar Entrada', '01-normalizar-entrada.js', [-680, 0]),

    {
        parameters: {
            url: '={{ $json.qrcode }}',
            sendHeaders: true,
            headerParameters: cabecalhos([
                ['User-Agent', NAVEGADOR],
                ['Accept-Language', 'pt-BR,pt;q=0.9'],
            ]),
            options: RESPOSTA_COMPLETA,
        },
        id: 'abrir-sessao-na-sefaz',
        name: 'Abrir Sessao na SEFAZ',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.2,
        position: [-460, 0],
    },

    nodeCode('Extrair Cookies', '02-extrair-cookies.js', [-240, 0]),

    {
        parameters: {
            url: CONSULTA_RESUMIDA,
            sendHeaders: true,
            headerParameters: cabecalhos([
                ['Cookie', '={{ $json.cookies }}'],
                ['User-Agent', NAVEGADOR],
                ['Referer', '={{ $json.qrcode }}'],
            ]),
            options: {
                // Responde 302, mas o corpo ja traz o formulario com o __VIEWSTATE.
                redirect: { redirect: { followRedirects: false } },
                ...RESPOSTA_COMPLETA,
            },
        },
        id: 'abrir-consulta-resumida',
        name: 'Abrir Consulta Resumida',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.2,
        position: [-20, 0],
    },

    nodeCode('Extrair ViewState', '03-extrair-viewstate.js', [200, 0]),

    {
        parameters: {
            method: 'POST',
            url: CONSULTA_RESUMIDA,
            sendHeaders: true,
            headerParameters: cabecalhos([
                ['Cookie', '={{ $json.cookies }}'],
                ['User-Agent', NAVEGADOR],
                ['Referer', CONSULTA_RESUMIDA],
            ]),
            sendBody: true,
            contentType: 'form-urlencoded',
            bodyParameters: cabecalhos([
                ['__EVENTTARGET', 'btnVisualizarAbas'],
                ['__EVENTARGUMENT', ''],
                ['__VIEWSTATE', '={{ $json.viewstate }}'],
                ['__VIEWSTATEGENERATOR', '={{ $json.gerador }}'],
                ['__EVENTVALIDATION', '={{ $json.validacao }}'],
            ]),
            options: RESPOSTA_COMPLETA,
        },
        id: 'abrir-abas-detalhadas',
        name: 'Abrir Abas Detalhadas',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.2,
        position: [420, 0],
    },

    nodeCode('Extrair Itens do Cupom', '04-extrair-itens.js', [640, 0]),

    {
        parameters: {
            method: 'POST',
            url: '={{ $json.callback_url }}',
            sendBody: true,
            specifyBody: 'json',
            jsonBody: '={{ JSON.stringify($json.payload) }}',
            options: RESPOSTA_COMPLETA,
        },
        id: 'devolver-ao-mercadinho',
        name: 'Devolver ao Mercadinho',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.2,
        position: [860, 0],
    },
];

// Cadeia linear: sem IF. O node "Extrair Itens do Cupom" ja decide, no
// try/catch, se o payload sai com status "ok" ou "erro" — assim uma nota
// nunca fica presa em "processando" no Mercadinho.
const ordem = nodes.map((n) => n.name);
const connections = {};
for (let i = 0; i < ordem.length - 1; i++) {
    connections[ordem[i]] = {
        main: [[{ node: ordem[i + 1], type: 'main', index: 0 }]],
    };
}

const workflow = {
    name: 'NFC-e SP -> Mercadinho',
    nodes,
    connections,
    settings: { executionOrder: 'v1' },
    pinData: {},
};

const destino = path.join(RAIZ, 'nfce-sp-mercadinho.workflow.json');
fs.writeFileSync(destino, JSON.stringify(workflow, null, 2) + '\n');

console.log('gerado:', destino);
console.log('nodes:', nodes.length, '->', ordem.join(' -> '));
