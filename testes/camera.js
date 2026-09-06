/*
 * Roda as telas de camera num Chromium com camera falsa e confere:
 *  - a camera abre sozinha quando a permissao ja esta dada;
 *  - getUserMedia e chamado UMA vez (nada de pedir permissao a cada troca);
 *  - o caminho ZXing (sem BarcodeDetector, como no iOS) tambem sobe;
 *  - o teclado nao sobe sozinho: foco em campo sem toque e desfeito;
 *  - nenhum erro no console.
 * No fim tira as fotos das telas.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
fs.mkdirSync(path.join(__dirname, 'telas'), { recursive: true });

const BASE = 'http://localhost:8099';

// Conta as chamadas de getUserMedia antes de qualquer script da pagina rodar.
const ESPIAO = `
  window.__gum = 0;
  const orig = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
  navigator.mediaDevices.getUserMedia = function (c) { window.__gum++; return orig(c); };
`;

let falhas = 0;
function checar(nome, ok, detalhe) {
    if (ok) { console.log('ok    ' + nome); return; }
    falhas++;
    console.log('FALHOU ' + nome + (detalhe ? '  -> ' + detalhe : ''));
}

async function abrir(navegador, rota, opcoes = {}) {
    const ctx = await navegador.newContext({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2,
        isMobile: true,
        hasTouch: true,
        permissions: ['camera'],
        colorScheme: opcoes.tema || 'light',
        baseURL: BASE,
    });
    await ctx.grantPermissions(['camera'], { origin: BASE });
    const erros = [];
    await ctx.addInitScript(ESPIAO);
    if (opcoes.semBarcodeDetector) {
        await ctx.addInitScript('delete window.BarcodeDetector;');
    }
    const pag = await ctx.newPage();
    pag.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
    pag.on('pageerror', (e) => erros.push('pageerror: ' + e.message));
    await pag.goto(BASE + rota);
    return { ctx, pag, erros };
}

(async () => {
    const navegador = await chromium.launch({
        // Sem o Chromium do playwright baixado, da para usar o Chrome/Edge da
        // maquina: NAVEGADOR=chrome node testes/camera.js
        channel: process.env.NAVEGADOR || undefined,
        args: [
            '--use-fake-device-for-media-stream',
            '--use-fake-ui-for-media-stream',
            '--allow-file-access-from-files',
        ],
    });

    // ---- 1. /escanear com BarcodeDetector (Chrome/Android) ----
    {
        const { ctx, pag, erros } = await abrir(navegador, '/escanear');
        await pag.waitForSelector('.camera-caixa.ligada', { timeout: 8000 }).catch(() => {});

        const ligada = await pag.locator('.camera-caixa').evaluate(el => el.classList.contains('ligada'));
        checar('escanear: camera abre sozinha com permissao dada', ligada);

        const tocando = await pag.locator('#video').evaluate(
            v => !!v.srcObject && v.readyState >= 2 && !v.paused
        );
        checar('escanear: video tocando', tocando);

        checar('escanear: botao vira "Desligar camera"',
            (await pag.locator('#btn-camera').textContent()).trim() === 'Desligar câmera');

        checar('escanear: dica visivel na camera',
            (await pag.locator('#dica').textContent()).includes('Procurando'));

        const perm = await pag.evaluate(() => window.Scanner.permissao());
        checar('escanear: Scanner.permissao() = granted', perm === 'granted', perm);

        // Ida e volta de aba nao pode reabrir a camera.
        await pag.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await pag.waitForTimeout(300);
        const chamadas = await pag.evaluate(() => window.__gum);
        checar('escanear: getUserMedia chamado 1 vez', chamadas === 1, 'chamadas=' + chamadas);

        await pag.screenshot({ path: require('path').join(__dirname, 'telas', 'tela-escanear.png') });

        // Desligar na mao guarda a preferencia e solta a camera.
        await pag.locator('#btn-camera').click();
        await pag.waitForTimeout(200);
        const solta = await pag.locator('#video').evaluate(v => v.srcObject === null);
        checar('escanear: desligar solta a camera', solta);
        checar('escanear: preferencia gravada',
            (await pag.evaluate(() => localStorage.getItem('mercadinho:camera-auto'))) === '0');

        await pag.reload();
        await pag.waitForTimeout(600);
        checar('escanear: nao abre sozinha depois de desligar na mao',
            !(await pag.locator('.camera-caixa').evaluate(el => el.classList.contains('ligada'))));

        checar('escanear: sem erro no console', erros.length === 0, erros.join(' | '));
        await ctx.close();
    }

    // ---- 2. /escanear sem BarcodeDetector (caminho ZXing, iOS/Firefox) ----
    {
        const { ctx, pag, erros } = await abrir(navegador, '/escanear', { semBarcodeDetector: true });
        await pag.waitForSelector('.camera-caixa.ligada', { timeout: 15000 }).catch(() => {});
        const ligada = await pag.locator('.camera-caixa').evaluate(el => el.classList.contains('ligada'));
        checar('zxing: camera abre sem BarcodeDetector', ligada);
        const chamadas = await pag.evaluate(() => window.__gum);
        checar('zxing: getUserMedia chamado 1 vez', chamadas === 1, 'chamadas=' + chamadas);
        checar('zxing: sem erro no console', erros.length === 0, erros.join(' | '));
        await ctx.close();
    }

    // ---- 3. /bipar ----
    {
        const { ctx, pag, erros } = await abrir(navegador, '/bipar');
        await pag.waitForSelector('.camera-caixa.ligada', { timeout: 8000 }).catch(() => {});
        checar('bipar: camera abre sozinha',
            await pag.locator('.camera-caixa').evaluate(el => el.classList.contains('ligada')));
        checar('bipar: mira larga',
            await pag.locator('.mira').evaluate(el => el.classList.contains('mira-larga')));
        // Resultado da busca: o cartao da margem mostra o fator, nao so o %.
        await pag.locator('#ean').fill('7896036095461');
        await pag.locator('#form-manual button').click();
        await pag.waitForSelector('.margem', { timeout: 5000 });
        const margem = (await pag.locator('.margem').innerText()).replace(/\s+/g, ' ');
        checar('bipar: margem em fator', margem.includes('1,94') && margem.includes('x'), margem);
        checar('bipar: margem tambem em %', margem.includes('+94%'), margem);
        checar('bipar: margem com o lucro', margem.includes('+R$ 0,94'), margem);
        checar('bipar: margem no verde',
            await pag.locator('.margem').evaluate(el => el.classList.contains('margem-boa')));

        // "Vale a pena comprar por X?": a conta tem que descontar os 11,68%
        // que saem de toda venda, senao diz que vale quando nao vale.
        await pag.locator('#custo-agora').fill('1,00');
        await pag.waitForTimeout(120);
        const vale = (await pag.locator('#vale-resposta').innerText()).replace(/\s+/g, ' ');
        checar('vale: veredito positivo', /Vale a pena/.test(vale), vale);
        checar('vale: fator', /1,94x/.test(vale), vale);
        // 1,94 - 1,00 - (1,94 x 11,68%) = 0,71
        checar('vale: sobra ja sem os percentuais', /R\$ 0,71/.test(vale), vale);
        checar('vale: compara com o ultimo pago', /vs\. os R\$ 1,00/.test(vale), vale);

        // Custo que come toda a margem tem que dizer que nao vale.
        await pag.locator('#custo-agora').fill('1,80');
        await pag.waitForTimeout(120);
        const ruim = (await pag.locator('#vale-resposta').innerText()).replace(/\s+/g, ' ');
        checar('vale: custo alto nao vale', /Não vale/.test(ruim), ruim);
        checar('vale: prejuizo se diz perde, nao sobra negativo',
            /perde R\$ 0,09/.test(ruim) && !/-0,09/.test(ruim), ruim);
        checar('vale: fica vermelho',
            await pag.locator('#vale-resposta .margem').evaluate(el => el.classList.contains('margem-ruim')));

        // Campo vazio nao pode mostrar resposta nenhuma.
        await pag.locator('#custo-agora').fill('');
        await pag.waitForTimeout(120);
        checar('vale: sem valor nao responde',
            (await pag.locator('#vale-resposta').innerText()).trim(), '');

        // O teclado nao pode subir sozinho por causa do campo novo.
        checar('vale: campo novo nao rouba o foco',
            await pag.evaluate(() => document.activeElement !== document.getElementById('custo-agora')));

        await pag.screenshot({ path: require('path').join(__dirname, 'telas', 'tela-bipar.png') });
        checar('bipar: sem erro no console', erros.length === 0, erros.join(' | '));
        await ctx.close();
    }

    // ---- 3b. teclado: so sobe se o dedo pediu ----
    {
        const { ctx, pag, erros } = await abrir(navegador, '/bipar');
        await pag.waitForSelector('.camera-caixa.ligada', { timeout: 8000 }).catch(() => {});

        checar('teclado: campo nao nasce focado',
            await pag.evaluate(() => document.activeElement !== document.getElementById('ean')));

        // O que o iOS faz sozinho quando o video entra e o layout muda.
        await pag.evaluate(() => document.getElementById('ean').focus());
        await pag.waitForTimeout(60);
        checar('teclado: foco sem toque e desfeito',
            await pag.evaluate(() => document.activeElement !== document.getElementById('ean')));

        // Ligar/desligar a camera nao pode deixar foco em campo.
        await pag.locator('#btn-camera').click();
        await pag.waitForTimeout(150);
        await pag.locator('#btn-camera').click();
        await pag.waitForTimeout(400);
        checar('teclado: ligar a camera nao foca o campo',
            await pag.evaluate(() => document.activeElement !== document.getElementById('ean')));

        // Quem toca no campo continua digitando normalmente.
        await pag.locator('#ean').tap();
        await pag.waitForTimeout(150);
        checar('teclado: toque no campo mantem o foco',
            await pag.evaluate(() => document.activeElement === document.getElementById('ean')));

        checar('teclado: sem erro no console', erros.length === 0, erros.join(' | '));
        await ctx.close();
    }

    // ---- 4. tema escuro, so para a foto ----
    {
        const { ctx, pag } = await abrir(navegador, '/escanear', { tema: 'dark' });
        await pag.waitForSelector('.camera-caixa.ligada', { timeout: 8000 }).catch(() => {});
        await pag.screenshot({ path: require('path').join(__dirname, 'telas', 'tela-escanear-escuro.png') });
        await ctx.close();
    }

    // ---- 5. permissao negada ----
    {
        const ctx = await navegador.newContext({
            viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true,
            permissions: [], baseURL: BASE,
        });
        const pag = await ctx.newPage();
        await pag.addInitScript(`
            navigator.mediaDevices.getUserMedia = () => {
                const e = new Error('denied'); e.name = 'NotAllowedError'; return Promise.reject(e);
            };
            navigator.permissions.query = () => Promise.resolve({ state: 'denied' });
        `);
        await pag.goto(BASE + '/escanear');
        await pag.waitForTimeout(500);
        const texto = await pag.locator('#estado').textContent();
        checar('negada: mostra como liberar', /Permitir/i.test(texto), texto.slice(0, 80));
        checar('negada: aviso no lugar da camera',
            (await pag.locator('#espera-texto').textContent()).includes('Sem permissão'));
        await pag.screenshot({ path: require('path').join(__dirname, 'telas', 'tela-negada.png') });
        await ctx.close();
    }

    await navegador.close();
    console.log(falhas ? '\n' + falhas + ' falha(s).' : '\nTodos os testes de camera passaram.');
    process.exit(falhas ? 1 : 0);
})();
