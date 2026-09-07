<?php
/**
 * Uma tabela de breakdown diário. Incluída duas vezes pela tela de metas
 * (faturamento e lucro) com $b e $titulo definidos antes do include — era o
 * mesmo bloco copiado, e copiado erra em um lado só.
 *
 * @var array  $b       saída de metas_breakdown_diario()
 * @var string $titulo
 * @var float  $alvo    a meta do mês; zero significa "sem meta definida"
 */
?>
<h2>Breakdown diário — <?= e($titulo) ?></h2>

<?php if ($alvo <= 0): ?>
    <p class="ajuda">
        Defina a meta de <?= e(mb_strtolower($titulo)) ?> em
        <a href="/config/metas">Configurações → Metas</a> para ver o breakdown.
    </p>
<?php else: ?>
    <div class="cartao tabela-rolante">
        <table class="tabela">
            <thead>
                <tr>
                    <th>Dia</th>
                    <th class="dir">Meta do dia</th>
                    <th class="dir">Realizado</th>
                    <th class="dir">Meta acum.</th>
                    <th class="dir">Real acum.</th>
                    <th class="dir">Diferença</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($b['diario'] as $d): ?>
                    <tr class="<?= $d['hoje'] ? 'hoje' : ($d['futuro'] ? 'futuro' : '') ?>">
                        <td><?= (int) $d['dia'] ?><?= $d['hoje'] ? ' · hoje' : '' ?></td>
                        <td class="dir"><?= moeda($d['meta_dia']) ?></td>
                        <td class="dir"><?= $d['futuro'] ? '—' : moeda($d['realizado_dia']) ?></td>
                        <td class="dir"><?= moeda($d['acum_meta']) ?></td>
                        <td class="dir"><?= $d['futuro'] ? '—' : moeda($d['acum_real']) ?></td>
                        <td class="dir">
                            <?php if ($d['futuro']): ?>
                                <?php // Dia que nao chegou nao esta atrasado. ?>
                                —
                            <?php else: ?>
                                <span class="<?= $d['diff_acum'] >= 0 ? 'lucro-bom' : 'lucro-ruim' ?>">
                                    <?= $d['diff_acum'] >= 0 ? '+' : '−' ?><?= moeda(abs($d['diff_acum'])) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="ajuda">
        A <strong>meta acumulada</strong> é a linha reta do mês
        (<?= moeda($b['resumo']['meta_diaria_base']) ?> por dia) — é contra ela que a
        diferença compara. A <strong>meta do dia</strong> é o plano recalculado: o que
        falta dividido pelos dias que restam, então ela sobe quando você fica para trás e
        cai quando adianta.
        <?php if ((int) $b['resumo']['dias_restantes'] > 0 && $b['resumo']['falta'] > 0): ?>
            Daqui para frente são <strong><?= moeda($b['resumo']['precisa_por_dia']) ?> por dia</strong>
            nos <?= (int) $b['resumo']['dias_restantes'] ?> dia(s) que restam.
        <?php endif; ?>
    </p>
<?php endif; ?>
