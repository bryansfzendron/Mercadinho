<?php /** @var array $notas */ ?>
<h1>Minhas notas</h1>

<?php if (!$notas): ?>
    <p class="vazio">Nenhuma nota ainda.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($notas as $n): ?>
            <li>
                <a href="/notas/<?= (int) $n['id'] ?>">
                    <div class="linha-topo">
                        <span class="forte"><?= e($n['loja'] ?? 'Sem loja identificada') ?></span>
                        <span class="valor"><?= $n['valor_total'] !== null ? moeda($n['valor_total']) : '-' ?></span>
                    </div>
                    <div class="linha-baixo">
                        <span><?= data_fmt($n['emissao']) ?> · <?= (int) $n['qtd_itens'] ?> itens</span>
                        <span class="selo selo-<?= e($n['status']) ?>">
                            <?= $n['origem'] === 'manual' ? 'manual' : e($n['status']) ?>
                        </span>
                    </div>
                    <?php if ($n['status'] === 'erro' && $n['erro_msg']): ?>
                        <div class="erro-msg"><?= e($n['erro_msg']) ?></div>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
