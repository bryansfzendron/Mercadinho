<?php /** @var string $conteudo @var string $titulo */
$usuario = usuario_atual();
$rota    = rota_atual();
$flashes = flash_pegar();
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#071c3d">
<title><?= e($titulo) ?> · Alpha Market</title>
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/assets/favicon.png">
<?php // No iPhone, "Adicionar a Tela de Inicio" usa este icone, nao o do manifest. ?>
<link rel="apple-touch-icon" href="/assets/icone-apple.png">
<meta name="apple-mobile-web-app-title" content="Alpha Market">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="stylesheet" href="/assets/app.css?v=24">
</head>
<body>

<?php if ($usuario): ?>
<header class="topo">
    <a class="marca" href="/"><img src="/assets/logo/simplificado.png" alt="" width="28" height="22">Alpha Market</a>
    <div class="topo-dir">
        <span class="quem"><?= e($usuario['nome']) ?></span>
        <?php // Engrenagem no topo em vez de um quinto item na barra de baixo:
              // configuracao nao e destino frequente, e a barra ja tem quatro. ?>
        <a class="engrenagem" href="/config" aria-label="Configurações" title="Configurações">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="3.2"/>
                <path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>
            </svg>
        </a>
        <a class="sair" href="/logout">Sair</a>
    </div>
</header>
<?php endif; ?>

<main class="conteudo">
    <?php foreach ($flashes as $f): ?>
        <div class="aviso aviso-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?= $conteudo ?>
</main>

<?php if ($usuario): ?>
<nav class="barra">
    <a href="/"          class="<?= $rota === '/' ? 'ativo' : '' ?>"><span>⌂</span>Início</a>
    <a href="/dashboard" class="<?= $rota === '/dashboard' ? 'ativo' : '' ?>"><span>📊</span>Dashboard</a>
    <?php // Escanear, bipar e vendas sao telas de dentro: acendem o item a que pertencem. ?>
    <a href="/notas"     class="<?= str_starts_with($rota, '/notas') || in_array($rota, ['/escanear', '/manual'], true) ? 'ativo' : '' ?>"><span>≡</span>Notas</a>
    <a href="/produtos"  class="<?= str_starts_with($rota, '/produtos') || $rota === '/bipar' ? 'ativo' : '' ?>"><span>☰</span>Produtos</a>
    <a href="/loja"      class="<?= in_array($rota, ['/loja', '/margens', '/metas'], true) || str_starts_with($rota, '/vendas') ? 'ativo' : '' ?>"><span>R$</span>Loja</a>
</nav>
<?php endif; ?>

<script src="/assets/sem-teclado.js?v=2" defer></script>
<script src="/assets/puxar-atualizar.js?v=1" defer></script>
</body>
</html>
