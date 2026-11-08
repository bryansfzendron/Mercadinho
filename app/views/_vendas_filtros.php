<?php
/**
 * Os filtros das duas telas de Vendas (Resumo e Transacoes), no mesmo cartao:
 * os atalhos de periodo, o campo de datas que abre o De/Ate, o botao
 * "Filtros (N)" que abre PDV, pagamento, categoria (e o agrupamento, no
 * Resumo), e a busca. Sem botao "Filtrar": cada campo aplica ao ser
 * escolhido (assets/filtro-direto.js), e a gaveta de onde veio a mudanca
 * reabre depois da recarga. Fechados, os campos continuam no formulario e
 * vao junto.
 *
 * @var string $acao          '/vendas' ou '/vendas/transacoes'
 * @var array  $f             vendas_filtros_da_url()
 * @var array  $pdvs
 * @var array  $formas
 * @var array  $categorias
 * @var ?string $agrupar_atual so no Resumo: a chave de agrupamento valendo
 * @var string $busca_dica    o placeholder da busca
 */
$com_agrupar = isset($agrupar_atual);
$base = array_filter([
    'pdv_id'    => (int) $f['pdv_id'] ?: null,
    'forma'     => $f['forma'] !== '' ? $f['forma'] : null,
    'categoria' => $f['categoria'] !== '' ? $f['categoria'] : null,
    'agrupar'   => $com_agrupar && $agrupar_atual !== 'produto' ? $agrupar_atual : null,
    'q'         => $f['busca'] !== '' ? $f['busca'] : null,
], static fn ($v): bool => $v !== null);
$ativos = count(array_intersect_key($base, array_flip(['pdv_id', 'forma', 'categoria', 'agrupar'])));
?>
<div class="cartao filtros-cartao">
    <div class="chips vf-periodos">
        <?php foreach (vendas_periodos() as [$rotulo, $de, $ate]): ?>
            <a class="chip <?= $f['de'] === $de && $f['ate'] === $ate ? 'ativo' : '' ?>"
               href="<?= e($acao) ?>?<?= e(http_build_query(['de' => $de, 'ate' => $ate] + $base)) ?>"><?= e($rotulo) ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= e($acao) ?>" data-filtro-direto>
        <div class="vf-linha">
            <details class="vf-gaveta vf-datas">
                <summary><span class="vf-campo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
                    <span class="vf-texto"><?= e(periodo_texto($f['de'], $f['ate'])) ?></span>
                    <svg class="vf-seta" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span></summary>
                <div class="vf-painel filtros">
                    <label>De <input type="date" name="de" value="<?= e($f['de']) ?>"></label>
                    <label>Até <input type="date" name="ate" value="<?= e($f['ate']) ?>"></label>
                </div>
            </details>

            <details class="vf-gaveta vf-mais">
                <summary><span class="vf-botao">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg>
                    Filtros<?= $ativos > 0 ? ' (' . $ativos . ')' : '' ?>
                </span></summary>
                <div class="vf-painel filtros">
                    <label>Ponto de venda
                        <select name="pdv_id">
                            <option value="">todos</option>
                            <?php foreach ($pdvs as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) $f['pdv_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Pagamento
                        <select name="forma">
                            <option value="">todas</option>
                            <?php foreach ($formas as $nome => $rotulo): ?>
                                <option value="<?= e($nome) ?>" <?= $f['forma'] === $nome ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="<?= $com_agrupar ? '' : 'col-cheia' ?>">Categoria
                        <select name="categoria">
                            <option value="">todas</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= e($cat) ?>" <?= $f['categoria'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($com_agrupar): ?>
                        <label>Agrupar por
                            <select name="agrupar">
                                <?php foreach (vendas_agrupamentos() as $chave => [$rot, $_]): ?>
                                    <option value="<?= e($chave) ?>" <?= $agrupar_atual === $chave ? 'selected' : '' ?>><?= e($rot) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <div class="vf-linha">
            <label class="vf-busca">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
                <input type="text" name="q" value="<?= e($f['busca']) ?>" placeholder="<?= e($busca_dica) ?>"
                       aria-label="Buscar" enterkeyhint="search"
                       autocomplete="off" autocorrect="off" spellcheck="false">
            </label>
        </div>
    </form>
</div>
