/*
 * Monta o JSON do workflow "TouchPay -> Mercadinho" a partir dos arquivos
 * n8n/codigo/tp-*.js.
 *
 *   node n8n/montar-touchpay.js
 *
 * Mesmo motivo do montar-workflow.js: JavaScript dentro de string JSON escrito
 * na mao e fonte garantida de erro de escape.
 */
const fs = require('fs');
const path = require('path');

const RAIZ = __dirname;
const BASE = 'https://touchpay.market';

const NAVEGADOR =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' +
    'Chrome/152.0.0.0 Safari/537.36';

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
            path: 'touchpay-mercadinho',
            responseMode: 'onReceived',
            options: {},
        },
        id: 'receber-do-mercadinho',
        name: 'Receber do Mercadinho',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2,
        position: [0, 0],
        webhookId: '8993f260-be1b-49dc-86c9-ec16ad760e35',
    },

    nodeCode('Normalizar Entrada', 'tp-01-normalizar-entrada.js', [224, 0]),

    {
        // O login responde 200 com corpo VAZIO: o JWT vem no header
        // "authorization", por isso fullResponse e obrigatorio aqui.
        parameters: {
            method: 'POST',
            url: BASE + '/account/login',
            sendHeaders: true,
            headerParameters: cabecalhos([
                ['accept', 'application/json, text/plain, */*'],
                ['origin', BASE],
                ['referer', BASE + '/'],
                ['user-agent', NAVEGADOR],
            ]),
            sendBody: true,
            specifyBody: 'json',
            jsonBody: '={{ JSON.stringify({ email: $json.email, password: $json.senha }) }}',
            options: RESPOSTA_COMPLETA,
        },
        id: 'entrar-no-touchpay',
        name: 'Entrar no TouchPay',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.2,
        position: [448, 0],
    },

    nodeCode('Pegar Token', 'tp-02-pegar-token.js', [672, 0]),

    {
        parameters: {
            url: BASE + '/api/PointsOfSale?hasInventory=true&hideSecondary=true&hasActivePlanogram=false',
            sendHeaders: true,
            headerParameters: cabecalhos([
                ['accept', 'application/json, text/plain, */*'],
                ['authorization', '={{ "Bearer " + $json.jwt }}'],
                ['referer', BASE + '/'],
                ['user-agent', NAVEGADOR],
            ]),
            options: RESPOSTA_COMPLETA,
        },
        id: 'listar-pdvs',
        name: 'Listar PDVs',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.2,
        position: [896, 0],
    },

    nodeCode('Coletar Precos e Estoque', 'tp-03-coletar.js', [1120, 0]),

    {
        // Um POST por ponto de venda: o node anterior devolve um item por PDV.
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
        position: [1344, 0],
    },
];

const ordem = nodes.map((n) => n.name);
const connections = {};
for (let i = 0; i < ordem.length - 1; i++) {
    connections[ordem[i]] = {
        main: [[{ node: ordem[i + 1], type: 'main', index: 0 }]],
    };
}

const workflow = {
    name: 'TouchPay -> Mercadinho',
    nodes,
    connections,
    settings: {
        executionOrder: 'v1',
        // Sem isto a instancia descarta a execucao e nao da para depurar.
        saveDataSuccessExecution: 'all',
        saveDataErrorExecution: 'all',
        executionTimeout: 300,
    },
    pinData: {},
};

const destino = path.join(RAIZ, 'touchpay-mercadinho.workflow.json');
fs.writeFileSync(destino, JSON.stringify(workflow, null, 2) + '\n');

console.log('gerado:', destino);
console.log('nodes:', nodes.length, '->', ordem.join(' -> '));
