/*
 * Servidor de teste: monta as telas da camera fora do PHP/MySQL, para rodar
 * num Chromium com camera falsa. So troca as tags PHP por valores fixos.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');

const RAIZ = path.join(__dirname, '..');

function vista(arquivo) {
    let html = fs.readFileSync(path.join(RAIZ, 'app/views', arquivo), 'utf8');
    html = html.replace(/<\?=\s*json_encode\(csrf_token\(\)\)\s*\?>/g, '"csrf-de-teste"');
    html = html.replace(/<\?=([\s\S]*?)\?>/g, 'teste');
    html = html.replace(/<\?php[\s\S]*?\?>/g, '');
    return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Teste</title><link rel="stylesheet" href="/assets/app.css?v=2"></head>
<body><main class="conteudo">${html}</main>
<nav class="barra"><a href="/escanear" class="ativo"><span>&#9635;</span>Nota</a>
<a href="/bipar"><span>|||</span>Bipar</a></nav></body></html>`;
}

const TIPOS = { '.css': 'text/css', '.js': 'application/javascript', '.png': 'image/png' };

http.createServer((req, res) => {
    const url = req.url.split('?')[0];

    if (url === '/escanear' || url === '/') {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        return res.end(vista('escanear.php'));
    }
    if (url === '/bipar') {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        return res.end(vista('bipar.php'));
    }
    if (url.startsWith('/assets/')) {
        const arq = path.join(RAIZ, url);
        if (fs.existsSync(arq)) {
            res.writeHead(200, { 'Content-Type': TIPOS[path.extname(arq)] || 'text/plain' });
            return res.end(fs.readFileSync(arq));
        }
    }
    // API falsa, para o fluxo nao morrer em rede
    if (url === '/api/produto') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ encontrado: false, ean: '7891000315507', mensagem: 'Nunca comprado (teste).' }));
    }
    if (url === '/api/notas') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ nota_id: 1 }));
    }
    if (url.startsWith('/api/notas/')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ status: 'ok', itens: 109, loja: 'HIGA PRODUTOS' }));
    }
    res.writeHead(404).end('nao achei');
}).listen(8099, () => console.log('servidor de teste em http://localhost:8099'));
