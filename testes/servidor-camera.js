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
<script src="/assets/sem-teclado.js?v=2" defer></script>
<script src="/assets/puxar-atualizar.js?v=1" defer></script>
<nav class="barra"><a href="/"><span>&#8962;</span>In&iacute;cio</a>
<a href="/notas" class="ativo"><span>&#8801;</span>Notas</a>
<a href="/produtos"><span>&#9776;</span>Produtos</a>
<a href="/loja"><span>R$</span>Loja</a></nav></body></html>`;
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
    // Tela da loja: HTML pre-renderizado pelo renderiza-loja.php (sem MySQL).
    if (url === '/notas') {
        const arq = path.join(__dirname, 'telas', 'notas-render.html');
        if (fs.existsSync(arq)) {
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            return res.end(fs.readFileSync(arq));
        }
    }
    if (url === '/loja') {
        const arq = path.join(__dirname, 'telas', 'loja-render.html');
        if (fs.existsSync(arq)) {
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            return res.end(fs.readFileSync(arq));
        }
    }
    if (url.startsWith('/assets/')) {
        const arq = path.join(RAIZ, url);
        if (fs.existsSync(arq)) {
            res.writeHead(200, { 'Content-Type': TIPOS[path.extname(arq)] || 'text/plain' });
            return res.end(fs.readFileSync(arq));
        }
    }
    // O navegador pede estes dois sozinho; 404 aqui viraria "erro no console".
    if (url === '/favicon.ico' || url === '/manifest.json') {
        return res.writeHead(204).end();
    }
    // API falsa, para o fluxo nao morrer em rede
    if (url === '/api/produto') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        // Vende por 1,94 o que custou 1,00: fator 1,94x, +94%.
        return res.end(JSON.stringify({
            encontrado: true,
            ean: '7896036095461',
            descricao: 'CANELA FADINHA PO C/ACUCAR 20G',
            url: '/produtos/1',
            stats: { ultimo: 1, min: 0.92, max: 1.1, n: 3 },
            ultimas: [{ loja: 'HIGA PRODUTOS', unitario: 1, data: 'ontem' }],
            loja: [{ pdv: 'PDV Portaria', preco: 1.94, estoque: 6, reservado: 0, atualizado: 'hoje 09:12' }],
        }));
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
