/*
 * Testa o "puxar para atualizar" num Chromium com toque, simulando o arrasto.
 *
 * Precisa do servidor de teste ligado:
 *   node testes/servidor-camera.js &
 *   node testes/puxar.js
 */
const { chromium } = require('playwright');

const BASE = 'http://localhost:8099';

let falhas = 0;
function checar(nome, ok, detalhe) {
    if (ok) { console.log('ok    ' + nome); return; }
    falhas++;
    console.log('FALHOU ' + nome + (detalhe ? '  -> ' + detalhe : ''));
}

/** Abre a pagina; standalone liga o modo "app instalado". */
async function abrir(navegador, { standalone }) {
    const ctx = await navegador.newContext({
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
        permissions: ['camera'],
        baseURL: BASE,
    });
    if (standalone) {
        // E assim que o iPhone sinaliza que o app foi aberto pela tela de inicio.
        await ctx.addInitScript('Object.defineProperty(navigator, "standalone", { value: true });');
    }
    const pag = await ctx.newPage();
    const erros = [];
    pag.on('pageerror', (e) => erros.push(e.message));
    await pag.goto(BASE + '/bipar');
    await pag.waitForFunction('!!window.PuxarAtualizar', { timeout: 5000 }).catch(() => {});
    // Troca o recarregamento por um contador, para observar sem sair da pagina.
    await pag.evaluate(`
        window.__recarregou = 0;
        if (window.PuxarAtualizar) {
            window.PuxarAtualizar.recarregar = () => { window.__recarregou++; };
        }
    `);
    return { ctx, pag, erros };
}

/** Arrasta o dedo de cima para baixo, em passos, e solta. */
async function puxar(pag, pixels, passos = 6) {
    await pag.evaluate(({ pixels, passos }) => {
        const alvo = document.body;
        const toque = (tipo, y, comDedo) => {
            const t = new Touch({ identifier: 1, target: alvo, clientX: 195, clientY: y });
            alvo.dispatchEvent(new TouchEvent(tipo, {
                touches: comDedo ? [t] : [],
                targetTouches: comDedo ? [t] : [],
                changedTouches: [t],
                bubbles: true,
                cancelable: true,
            }));
        };
        const inicio = 100;
        toque('touchstart', inicio, true);
        for (let i = 1; i <= passos; i++) {
            toque('touchmove', inicio + (pixels * i) / passos, true);
        }
        toque('touchend', inicio + pixels, false);
    }, { pixels, passos });
    await pag.waitForTimeout(120);
}

(async () => {
    const navegador = await chromium.launch({
        args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'],
    });

    // ---- 1. app instalado: puxao longo recarrega ----
    {
        const { ctx, pag, erros } = await abrir(navegador, { standalone: true });
        checar('standalone: modulo carregou', await pag.evaluate('!!window.PuxarAtualizar'));
        checar('standalone: reconhece o modo app',
            await pag.evaluate('window.PuxarAtualizar.ehStandalone()'));
        checar('standalone: indicador foi criado', await pag.locator('.puxar').count() === 1);

        // O gatilho e em pixels ja com a resistencia de 50%, entao o dedo
        // precisa andar o dobro.
        await puxar(pag, 200);
        checar('standalone: puxao longo recarrega',
            (await pag.evaluate('window.__recarregou')) === 1);

        checar('standalone: sem erro de JS', erros.length === 0, erros.join(' | '));
        await ctx.close();
    }

    // ---- 2. puxao curto nao recarrega ----
    {
        const { ctx, pag } = await abrir(navegador, { standalone: true });
        await puxar(pag, 40);
        checar('puxao curto nao recarrega', (await pag.evaluate('window.__recarregou')) === 0);
        checar('indicador volta ao repouso',
            (await pag.locator('.puxar').evaluate((el) => el.style.transform)) === '');
        await ctx.close();
    }

    // ---- 3. rolar para cima (dedo subindo) nao recarrega ----
    {
        const { ctx, pag } = await abrir(navegador, { standalone: true });
        await puxar(pag, -200);
        checar('dedo subindo nao recarrega', (await pag.evaluate('window.__recarregou')) === 0);
        await ctx.close();
    }

    // ---- 4. pagina fora do topo nao aciona ----
    {
        const { ctx, pag } = await abrir(navegador, { standalone: true });
        await pag.evaluate(`
            const alto = document.createElement('div');
            alto.style.height = '3000px';
            document.body.appendChild(alto);
            window.scrollTo(0, 500);
        `);
        await pag.waitForTimeout(80);
        await puxar(pag, 200);
        checar('fora do topo nao recarrega', (await pag.evaluate('window.__recarregou')) === 0);
        await ctx.close();
    }

    // ---- 5. no navegador comum nem instala o gesto ----
    {
        const { ctx, pag } = await abrir(navegador, { standalone: false });
        checar('navegador comum: nao cria indicador', await pag.locator('.puxar').count() === 0);
        await puxar(pag, 200);
        checar('navegador comum: nao recarrega', (await pag.evaluate('window.__recarregou')) === 0);
        await ctx.close();
    }

    await navegador.close();
    console.log(falhas ? '\n' + falhas + ' falha(s).' : '\nTodos os testes do puxar-para-atualizar passaram.');
    process.exit(falhas ? 1 : 0);
})();
