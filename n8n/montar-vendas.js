/*
 * Monta o JSON do workflow "TouchPay Vendas -> Mercadinho".
 *
 *   node n8n/montar-vendas.js
 *
 * Fluxo separado do espelho de preco/estoque de proposito: cadencia
 * diferente, janela de datas propria e payload proprio. O que os dois tem
 * igual (normalizar entrada e pegar o token) sai dos mesmos arquivos de
 * codigo, entao corrigir o login conserta os dois.
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

/**
 * O mesmo, mas mandando UM item por vez.
 *
 * O padrao do node HTTP e batchSize 50, ou seja: os 25 lotes de uma carga
 * saem praticamente juntos, e os callbacks se atropelam gravando na mesma
 * tabela — foi o que produziu "1213 Deadlock found when trying to get lock"
 * em producao. Um de cada vez, sem intervalo entre eles.
 */
const RESPOSTA_EM_FILA = {
    response: { response: { fullResponse: true, neverError: true } },
    batching: { batch: { batchSize: 1, batchInterval: 0 } },
};

function cabecalhos(lista) {
    return { parameters: lista.map(([name, value]) => ({ name, value })) };
}

const nodes = [
    {
        parameters: {
            httpMethod: 'POST',
            path: 'touchpay-vendas',
            responseMode: 'onReceived',
            options: {},
        },
        id: 'receber-do-mercadinho',
        name: 'Receber do Mercadinho',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2,
        position: [0, 0],
        webhookId: 'b1f0a0d2-7c64-4a5f-9f0e-6a3c1d2e4b70',
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
    nodeCode('Coletar Vendas', 'vd-03-coletar-vendas.js', [896, 0]),

    {
        // Um POST por lote: o node anterior ja fatia as vendas em lotes que
        // cabem nos 30s da hospedagem.
        parameters: {
            method: 'POST',
            url: '={{ $json.callback_url }}',
            sendBody: true,
            specifyBody: 'json',
            jsonBody: '={{ JSON.stringify($json.payload) }}',
            options: RESPOSTA_EM_FILA,
        },
        id: 'devolver-ao-mercadinho',
        name: 'Devolver ao Mercadinho',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.2,
        position: [1120, 0],
    },

    nodeCode('Conferir Gravacao', 'vd-04-conferir.js', [1344, 0]),
];

const ordem = nodes.map((n) => n.name);
const connections = {};
for (let i = 0; i < ordem.length - 1; i++) {
    connections[ordem[i]] = {
        main: [[{ node: ordem[i + 1], type: 'main', index: 0 }]],
    };
}

const workflow = {
    name: 'TouchPay Vendas -> Mercadinho',
    nodes,
    connections,
    settings: {
        executionOrder: 'v1',
        // Mesmo motivo do outro fluxo: o corpo do webhook carrega a senha do
        // TouchPay, e historico de execucao guarda isso em texto puro.
        saveDataSuccessExecution: 'none',
        saveDataErrorExecution: 'all',
        // A carga inicial de 12 meses sao 13 paginas mais 25 POSTs de volta.
        executionTimeout: 600,
    },
    pinData: {},
};

const destino = path.join(RAIZ, 'touchpay-vendas.workflow.json');
fs.writeFileSync(destino, JSON.stringify(workflow, null, 2) + '\n');

console.log('gerado:', destino);
console.log('nodes:', nodes.length, '->', ordem.join(' -> '));
