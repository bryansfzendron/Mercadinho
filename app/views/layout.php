<?php /** @var string $conteudo @var string $titulo */
$usuario = usuario_atual();
$rota    = rota_atual();
$flashes = flash_pegar();
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#14532d">
<title><?= e($titulo) ?> · Mercadinho</title>
<link rel="manifest" href="/manifest.json">
<link rel="stylesheet" href="/assets/app.css?v=6">
</head>
<body>

<?php if ($usuario): ?>
<header class="topo">
    <a class="marca" href="/">Mercadinho</a>
    <div class="topo-dir">
        <span class="quem"><?= e($usuario['nome']) ?></span>
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
    <?php // Escanear e bipar sao telas de dentro: acendem o item a que pertencem. ?>
    <a href="/notas"     class="<?= str_starts_with($rota, '/notas') || in_array($rota, ['/escanear', '/manual'], true) ? 'ativo' : '' ?>"><span>≡</span>Notas</a>
    <a href="/produtos"  class="<?= str_starts_with($rota, '/produtos') || $rota === '/bipar' ? 'ativo' : '' ?>"><span>☰</span>Produtos</a>
    <a href="/loja"      class="<?= $rota === '/loja' ? 'ativo' : '' ?>"><span>R$</span>Loja</a>
</nav>
<?php endif; ?>

<script src="/assets/sem-teclado.js?v=2" defer></script>
<script src="/assets/puxar-atualizar.js?v=1" defer></script>
</body>
</html>
