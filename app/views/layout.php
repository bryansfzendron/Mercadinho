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
<?php // O puxar-para-atualizar pede a sincronizacao por POST, e toda rota que
      // grava exige o token. So sai para quem esta logado. ?>
<?php if ($usuario): ?><meta name="csrf" content="<?= e(csrf_token()) ?>">
<?php endif; ?>
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/assets/favicon.png">
<?php // No iPhone, "Adicionar a Tela de Inicio" usa este icone, nao o do manifest. ?>
<link rel="apple-touch-icon" href="/assets/icone-apple.png">
<meta name="apple-mobile-web-app-title" content="Alpha Market">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="stylesheet" href="/assets/app.css?v=43">
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
    <?php // /transacoes e tela de dentro do inicio: acende "Inicio", nao "Loja". ?>
    <a href="/"          class="<?= in_array($rota, ['/', '/transacoes'], true) ? 'ativo' : '' ?>">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 11.5 12 4l9 7.5"/>
            <path d="M5.5 10v8.5a1 1 0 0 0 1 1H9a1 1 0 0 0 1-1v-4h4v4a1 1 0 0 0 1 1h2.5a1 1 0 0 0 1-1V10"/>
        </svg></span>Início
    </a>
    <a href="/dashboard" class="<?= $rota === '/dashboard' ? 'ativo' : '' ?>">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="4" y="12" width="4" height="8" rx="1"/>
            <rect x="10" y="8" width="4" height="12" rx="1"/>
            <rect x="16" y="4" width="4" height="16" rx="1"/>
        </svg></span>Dashboard
    </a>
    <?php // Escanear, bipar e vendas sao telas de dentro: acendem o item a que pertencem. ?>
    <a href="/notas"     class="<?= str_starts_with($rota, '/notas') || in_array($rota, ['/escanear', '/manual'], true) ? 'ativo' : '' ?>">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6.5 3h7l4 4v13a1 1 0 0 1-1 1h-10a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/>
            <path d="M13.5 3v4h4"/>
            <path d="M8.5 12.5h7M8.5 16h5"/>
        </svg></span>Notas
    </a>
    <?php // Mercado e a compra em andamento: mora ao lado das notas, que sao as
          // compras que ja aconteceram — e longe da Loja, que e o outro lado do balcao. ?>
    <a href="/mercado"   class="<?= $rota === '/mercado' ? 'ativo' : '' ?>">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 4h2.2l2 10.5a1.5 1.5 0 0 0 1.5 1.2h7.9a1.5 1.5 0 0 0 1.5-1.2L19.5 8H6"/>
            <circle cx="9.5" cy="19.5" r="1.3"/>
            <circle cx="16.5" cy="19.5" r="1.3"/>
        </svg></span>Mercado
    </a>
    <a href="/produtos"  class="<?= str_starts_with($rota, '/produtos') || $rota === '/bipar' ? 'ativo' : '' ?>">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="4" y="4" width="7" height="7" rx="1.5"/>
            <rect x="13" y="4" width="7" height="7" rx="1.5"/>
            <rect x="4" y="13" width="7" height="7" rx="1.5"/>
            <rect x="13" y="13" width="7" height="7" rx="1.5"/>
        </svg></span>Produtos
    </a>
    <a href="/loja"      class="<?= in_array($rota, ['/loja', '/planograma', '/margens', '/metas'], true) || str_starts_with($rota, '/vendas') ? 'ativo' : '' ?>">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 8h12l-1 12a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1L6 8Z"/>
            <path d="M9 8V6a3 3 0 0 1 6 0v2"/>
        </svg></span>Loja
    </a>
</nav>
<?php endif; ?>

<script src="/assets/sem-teclado.js?v=2" defer></script>
<?php // movimento.js vem antes do puxar-atualizar.js de proposito: o puxao usa
      // a mola dele pra recolher o indicador. Com defer, a ordem de execucao e
      // a ordem em que estao aqui. ?>
<script src="/assets/movimento.js?v=1" defer></script>
<?php // inicio.js so faz algo onde existe o cartao de vendas; nas outras telas sai fora. ?>
<script src="/assets/inicio.js?v=3" defer></script>
<script src="/assets/puxar-atualizar.js?v=3" defer></script>
</body>
</html>
