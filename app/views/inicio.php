<?php /** @var array $resumo @var int $itens_total @var int $pendentes @var array $ultimas @var array $loja */ ?>
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

<h2>Loja (TouchPay)</h2>
<?php if (!$loja): ?>
    <p class="vazio">Nenhum ponto de venda sincronizado ainda.</p>
<?php else: ?>
    <ul class="lista">
        <?php foreach ($loja as $p): ?>
            <li><div style="padding:.7rem .85rem">
                <div class="linha-topo">
                    <span class="forte"><?= e($p['nome']) ?></span>
                    <span class="valor"><?= (int) $p['itens'] ?> itens</span>
                </div>
                <div class="linha-baixo">
                    <span><?= (int) $p['vinculados'] ?> ligados ao seu histórico</span>
                    <span><?= data_fmt($p['atualizado_em'], true) ?></span>
                </div>
            </div></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<div class="acoes">
    <button type="button" id="btn-sync-loja" class="botao botao-alt">Atualizar preços e estoque</button>
    <p class="ajuda" id="sync-estado"></p>
</div>

<script>
(function () {
    const btn = document.getElementById('btn-sync-loja');
    const estado = document.getElementById('sync-estado');
    const CSRF = <?= json_encode(csrf_token()) ?>;

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        estado.textContent = 'Pedindo os dados ao TouchPay...';
        try {
            const r = await fetch('/api/loja/sincronizar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            });
            const d = await r.json();
            estado.textContent = d.ok
                ? 'Sincronização disparada. Leva alguns segundos; recarregue a página para ver.'
                : ('Não deu: ' + (d.erro || 'erro desconhecido'));
        } catch (e) {
            estado.textContent = 'Falha de rede: ' + e.message;
        }
        btn.disabled = false;
    });
})();
</script>

<p class="centro"><a href="/manual">+ Lançar nota manualmente</a></p>
