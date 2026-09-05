<?php /** @var array $resumo @var int $itens_total @var int $pendentes @var array $ultimas */ ?>
<div class="acoes-topo">
    <a class="botao botao-grande" href="/escanear">▣ Escanear nota</a>
    <a class="botao botao-grande botao-alt" href="/bipar">||| Bipar produto</a>
</div>

<?php if ($pendentes > 0): ?>
    <div class="aviso aviso-info">
        <?= (int) $pendentes ?> nota(s) ainda em processamento.
        <a href="/notas">Ver</a>
    </div>
<?php endif; ?>

<div class="numeros">
    <div class="numero">
        <strong><?= (int) $resumo['notas'] ?></strong>
        <span>notas</span>
    </div>
    <div class="numero">
        <strong><?= (int) $itens_total ?></strong>
        <span>itens</span>
    </div>
    <div class="numero">
        <strong><?= moeda($resumo['total']) ?></strong>
        <span>total gasto</span>
    </div>
</div>

<h2>Últimas notas</h2>
<?php if (!$ultimas): ?>
    <p class="vazio">Nenhuma nota ainda. Escaneie o QR Code de um cupom para começar.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($ultimas as $n): ?>
            <li>
                <a href="/notas/<?= (int) $n['id'] ?>">
                    <div class="linha-topo">
                        <span class="forte"><?= e($n['loja'] ?? 'Sem loja') ?></span>
                        <span class="valor"><?= $n['valor_total'] !== null ? moeda($n['valor_total']) : '-' ?></span>
                    </div>
                    <div class="linha-baixo">
                        <span><?= data_fmt($n['emissao']) ?></span>
                        <span class="selo selo-<?= e($n['status']) ?>"><?= e($n['status']) ?></span>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p class="centro"><a href="/manual">+ Lançar nota manualmente</a></p>
