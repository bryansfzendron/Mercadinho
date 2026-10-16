<?php
/**
 * Importar notas por planilha.
 *
 * Dois estados na mesma rota: o envio (previa === null) e a previa do que foi
 * lido, com os erros por linha. So a confirmacao grava.
 *
 * @var ?array  $previa   notas agrupadas por planilha_agrupar(), ou null
 * @var array   $erros    [{linha, msg}]
 * @var string  $token    liga a confirmacao a esta previa (sessao)
 * @var string  $arquivo  nome do arquivo enviado
 */
$novas = $previa ? array_filter($previa, static fn($n) => $n['existente'] === null) : [];
?>
<a class="voltar" href="/notas">‹ Notas</a>
<h1>Importar planilha</h1>

<?php if ($previa === null): ?>
    <p class="ajuda">Para notas sem QR Code ou lotes de notas: baixe o modelo, preencha uma linha por item e envie. Você confere tudo antes de gravar.</p>

    <div class="cartao">
        <h2>1. Baixe o modelo</h2>
        <div class="acoes-topo">
            <a class="botao botao-grande" href="/importar/modelo.xlsx">⇩ Modelo Excel (.xlsx)</a>
            <a class="botao botao-alt" href="/importar/modelo.csv">⇩ Modelo CSV (;)</a>
        </div>
        <p class="ajuda">A aba "Instruções" do Excel explica cada coluna. Várias linhas com o mesmo número na coluna <strong>nota</strong> formam uma nota; pode haver várias notas no arquivo.</p>
    </div>

    <form class="cartao" method="post" action="/importar" enctype="multipart/form-data">
        <?= csrf_campo() ?>
        <h2>2. Envie preenchida</h2>
        <label>Arquivo .xlsx ou .csv (até 2 MB)
            <input type="file" name="arquivo" accept=".xlsx,.csv,text/csv" required>
        </label>
        <button class="botao botao-grande" type="submit">Ler planilha</button>
    </form>

<?php else: ?>
    <p class="ajuda">Arquivo: <strong><?= e($arquivo) ?></strong></p>

    <?php if ($erros): ?>
        <div class="aviso aviso-erro">
            <strong>Nada foi gravado.</strong> Corrija a planilha e envie de novo:
            <ul>
                <?php foreach (array_slice($erros, 0, 40) as $er): ?>
                    <li>Linha <?= (int) $er['linha'] ?>: <?= e($er['msg']) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($erros) > 40): ?>
                <p>… e mais <?= count($erros) - 40 ?> erro(s).</p>
            <?php endif; ?>
        </div>
        <a class="botao botao-grande" href="/importar">Corrigir e enviar de novo</a>

    <?php else: ?>
        <?php foreach ($previa as $n): ?>
            <div class="cartao">
                <h2><?= e($n['cab']['emitente']) ?></h2>
                <p class="ajuda">
                    <?= e(date('d/m/Y H:i', strtotime($n['cab']['emissao']))) ?>
                    <?php if ($n['cab']['numero_nota'] !== ''): ?> · nº <?= e($n['cab']['numero_nota']) ?><?php endif; ?>
                    · <?= count($n['itens']) ?> item(ns) · <strong><?= e(moeda($n['total'])) ?></strong>
                </p>
                <?php if ($n['existente'] !== null): ?>
                    <div class="aviso aviso-info">Já está no app (<a href="/notas/<?= (int) $n['existente'] ?>">ver nota</a>) — será ignorada.</div>
                <?php endif; ?>
                <details>
                    <summary>Ver itens</summary>
                    <div class="tabela-rolante">
                        <table class="tabela">
                            <thead><tr><th>Item</th><th class="dir">Qtd</th><th class="dir">Unit.</th><th class="dir">Desc.</th><th class="dir">Total</th></tr></thead>
                            <tbody>
                            <?php foreach ($n['itens'] as $it): ?>
                                <tr>
                                    <td><?= e($it['descricao']) ?></td>
                                    <td class="dir"><?= e(rtrim(rtrim(number_format($it['quantidade'], 4, ',', '.'), '0'), ',')) ?></td>
                                    <td class="dir"><?= e(moeda($it['valor_unitario'])) ?></td>
                                    <td class="dir"><?= $it['desconto_item'] > 0 ? e(moeda($it['desconto_item'])) : '—' ?></td>
                                    <td class="dir"><?= e(moeda($it['valor_total_item'] - $it['desconto_item'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        <?php endforeach; ?>

        <?php if ($novas): ?>
            <form method="post" action="/importar/confirmar">
                <?= csrf_campo() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <button class="botao botao-grande" type="submit">Importar <?= count($novas) ?> nota(s)</button>
            </form>
        <?php else: ?>
            <div class="aviso aviso-info">Todas as notas da planilha já estão no app.</div>
        <?php endif; ?>
        <p><a class="botao botao-alt" href="/importar">Cancelar</a></p>
    <?php endif; ?>
<?php endif; ?>
