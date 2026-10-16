<?php
/**
 * Repor em lote: conferir e gravar, uma linha por vez.
 *
 * O servidor corta a requisicao em ~30s e o TouchPay so aceita uma chamada
 * por item, entao quem conduz e o navegador (assets/repor-lote.js): confere
 * cada linha (le o TouchPay, nao escreve), mostra o de/para e so grava depois
 * do clique. O estado de cada linha mora no banco, entao fechar a aba ou
 * apertar F5 nao repete nem perde nada.
 *
 * @var array   $lote
 * @var array   $linhas  repor_linha_tela()
 * @var ?string $erro
 */
?>
<?php if (!empty($lote['nota_id'])): ?>
    <a class="voltar" href="/notas/<?= (int) $lote['nota_id'] ?>">‹ Nota</a>
<?php else: ?>
    <a class="voltar" href="/planograma/importar">‹ Repor em lote</a>
<?php endif; ?>
<h1>Lote <?= (int) $lote['id'] ?></h1>
<?= abas_loja('/planograma') ?>
<p class="ajuda"><?= e($lote['arquivo'] ?: '') ?> · <?= e(data_fmt($lote['criado_em'], true)) ?></p>

<?php if ($erro): ?>
    <div class="aviso aviso-erro"><strong>TouchPay:</strong> <?= e($erro) ?></div>
<?php endif; ?>

<div id="lote" data-lote="<?= (int) $lote['id'] ?>"
     data-linhas="<?= e(json_encode($linhas, JSON_UNESCAPED_UNICODE)) ?>">

    <div class="cartao">
        <div class="progresso" id="lt-progresso">
            <div class="progresso-barra"><span id="lt-barra"></span></div>
            <p class="ajuda progresso-texto" id="lt-texto"></p>
        </div>
        <div class="acoes-topo">
            <button id="lt-gravar" class="botao botao-grande" type="button" disabled>Gravar</button>
            <button id="lt-repetir" class="botao botao-alt" type="button" hidden>Tentar de novo as que falharam</button>
        </div>
        <p class="ajuda" id="lt-aviso">Conferindo no TouchPay… nada é gravado nesta etapa.</p>
        <?php if (!empty($lote['nota_id'])): ?>
            <p><a class="botao botao-alt" href="/notas/<?= (int) $lote['nota_id'] ?>/repor">Repor o resto da nota em outro PDV</a></p>
        <?php endif; ?>
    </div>

    <div class="cartao" id="lt-sem" hidden>
        <h2>Sem cadastro no TouchPay</h2>
        <p class="ajuda">Estes produtos não existem nem no planograma nem no cadastro. Peça o cadastro e depois use “Conferir de novo”.</p>
        <ul class="lista" id="lt-sem-lista"></ul>
        <div class="acoes-topo">
            <a class="botao botao-alt" href="/planograma/lote/<?= (int) $lote['id'] ?>/sem-cadastro.csv">⇩ Baixar lista (CSV)</a>
            <button id="lt-copiar" class="botao botao-alt" type="button">Copiar texto</button>
            <button id="lt-reconferir" class="botao botao-alt" type="button">Conferir de novo</button>
        </div>
    </div>

    <div class="tabela-rolante">
        <table class="tabela">
            <thead><tr><th>#</th><th>Produto</th><th>PDV</th><th>O que muda</th><th>Situação</th></tr></thead>
            <tbody id="lt-corpo"></tbody>
        </table>
    </div>
</div>

<script src="/assets/repor-lote.js?v=1"></script>
