<?php
/**
 * Repor a gondola.
 *
 * A unica tela do app que ESCREVE na loja de verdade. Tudo aqui vai parar no
 * TouchPay: o preco digitado e o preco que o cliente paga no caixa. Por isso
 * nada e salvo no primeiro toque — o botao mostra de/para e pede confirmacao,
 * e o servidor rele o valor antes de gravar.
 *
 * A camera e a mesma do Mercado: fica ligada enquanto se digita, porque
 * repor sao trinta bipes seguidos e soltar o aparelho a cada um faria o
 * iPhone perguntar da permissao de novo.
 *
 * @var array  $pdvs
 * @var ?string $erro
 * @var array  $log
 */
?>
<h1>Repor</h1>
<?= abas_loja('/planograma') ?>

<?php if ($erro): ?>
    <div class="aviso aviso-erro"><strong>TouchPay:</strong> <?= e($erro) ?></div>
<?php endif; ?>

<?php if (!$pdvs): ?>
    <p class="vazio">
        Nenhum ponto de venda sincronizado ainda.<br>
        Puxe o espelho da loja em <a href="/config">Configurações</a> primeiro.
    </p>
<?php else: ?>

<p class="ajuda">
    Bipe o produto na gôndola: se ele estiver no planograma, dá para mudar preço,
    quantidade necessária, crítico e estoque. Se não estiver, mas existir no cadastro,
    ele entra no planograma na hora. O preço pode sair da conta
    <strong>custo × taxa</strong>, com o custo da nota do atacado, e a validade
    mostra a que já está no estoque antes de você trocar.
</p>

<div id="planograma"
     data-pdvs="<?= e(json_encode(array_map(static fn ($p) => [
         'id'    => (int) $p['id'],
         'nome'  => $p['nome'],
         'plano' => (int) ($p['planograma_id'] ?? 0),
     ], $pdvs), JSON_UNESCAPED_UNICODE)) ?>">

    <div class="cartao">
        <label>Ponto de venda
            <select id="pg-pdv">
                <?php foreach ($pdvs as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= (int) ($p['planograma_id'] ?? 0) === 0 ? ' disabled' : '' ?>>
                        <?= e($p['nome']) ?><?= (int) ($p['planograma_id'] ?? 0) === 0 ? ' — sem planograma' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="ajuda" id="pg-plano"></p>
    </div>

    <div class="camera-caixa larga" id="camera">
        <video id="video" muted playsinline></video>
        <div class="camera-espera" id="espera">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 9a2 2 0 0 1 2-2h1.6l1.2-2h6.4l1.2 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <circle cx="12" cy="13" r="3.4"/>
            </svg>
            <span id="espera-texto">Câmera desligada</span>
        </div>
        <div class="mira mira-larga"><b></b><i></i></div>
        <p class="camera-dica" id="dica"></p>
    </div>

    <div class="acoes camera-acoes">
        <button id="btn-camera" class="botao">Ligar câmera</button>
        <button id="btn-luz" class="botao botao-alt botao-icone" hidden>Lanterna</button>
    </div>

    <form id="pg-form" class="linha-form">
        <input id="pg-codigo" type="text" inputmode="numeric" pattern="[0-9]*"
               placeholder="bipe ou digite o código" autocomplete="off">
        <button type="submit" class="botao botao-alt">Buscar</button>
    </form>

    <div id="pg-resultado"></div>

    <?php if ($log): ?>
        <h2>Últimas alterações</h2>
        <ul class="lista" id="pg-log">
            <?php foreach ($log as $l): ?>
                <li><div class="linha-cartao">
                    <div class="linha-topo">
                        <span class="forte"><?= e($l['descricao'] ?: ('produto ' . $l['produto_externo_id'])) ?></span>
                        <span class="valor<?= $l['ok'] ? '' : ' negativo' ?>">
                            <?php if ($l['campo'] === null): ?>
                                entrou no planograma
                            <?php else: ?>
                                <?= e($l['de'] ?? '—') ?> → <?= e($l['para'] ?? '—') ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="linha-baixo">
                        <span>
                            <?= e($l['campo'] ?? $l['acao']) ?> ·
                            <?= e($l['pdv'] ?? '—') ?> ·
                            <?= e(data_fmt($l['criado_em'], true)) ?>
                            <?= $l['usuario'] ? ' · ' . e($l['usuario']) : '' ?>
                        </span>
                    </div>
                    <?php if (!$l['ok'] && $l['erro']): ?>
                        <p class="ajuda"><?= e($l['erro']) ?></p>
                    <?php endif; ?>
                </div></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<script src="/assets/scanner.js?v=3"></script>
<script src="/assets/planograma.js?v=3"></script>
<script>
(function () {
    /*
     * A camera desta tela. Mesmas regras do Mercado e do "bipar produto" —
     * inclusive a de nao chamar getUserMedia sozinha na primeira vez da
     * sessao, que e o que evita o iPhone perguntar da permissao em toda tela
     * que tem camera.
     */
    const AUTO   = 'mercadinho:camera-auto';
    const SESSAO = 'mercadinho:camera-sessao';

    const tela   = document.getElementById('planograma');
    if (!tela) return;
    const video  = document.getElementById('video');
    const caixa  = document.getElementById('camera');
    const espera = document.getElementById('espera-texto');
    const dica   = document.getElementById('dica');
    const btnCam = document.getElementById('btn-camera');
    const btnLuz = document.getElementById('btn-luz');
    let leitor = null;
    let luzAcesa = false;

    function jaAbriuNestaSessao() {
        try { return sessionStorage.getItem(SESSAO) === '1'; } catch (e) { return false; }
    }
    function marcarSessao() {
        try { sessionStorage.setItem(SESSAO, '1'); } catch (e) { /* modo privado */ }
    }
    function lembrar(ligada) {
        try { localStorage.setItem(AUTO, ligada ? '1' : '0'); } catch (e) { /* modo privado */ }
    }
    function querAutomatico() {
        try { return localStorage.getItem(AUTO) !== '0'; } catch (e) { return false; }
    }

    async function ligar(automatico) {
        if (leitor) return;
        dica.textContent = 'Abrindo a câmera...';
        try {
            leitor = await Scanner.iniciar(video, Scanner.BARRAS, aoBipar);
            caixa.classList.add('ligada');
            btnCam.textContent = 'Desligar câmera';
            btnCam.classList.add('botao-alt');
            dica.textContent = 'Procurando o código de barras...';
            btnLuz.hidden = !leitor.lanternaDisponivel();
            lembrar(true);
            marcarSessao();
        } catch (e) {
            leitor = null;
            dica.textContent = '';
            if (automatico) { espera.textContent = 'Toque em "Ligar câmera"'; return; }
            espera.textContent = e.semPermissao ? 'Sem permissão de câmera' : 'Câmera indisponível';
        }
    }

    function desligar() {
        if (leitor) { leitor.parar(); leitor = null; }
        caixa.classList.remove('ligada');
        btnCam.textContent = 'Ligar câmera';
        btnCam.classList.remove('botao-alt');
        btnLuz.hidden = true;
        luzAcesa = false;
        btnLuz.classList.remove('botao-ligado');
        espera.textContent = 'Câmera desligada';
        dica.textContent = '';
    }

    btnCam.addEventListener('click', () => {
        if (leitor) { lembrar(false); desligar(); return; }
        ligar(false);
    });

    btnLuz.addEventListener('click', async () => {
        if (!leitor) return;
        luzAcesa = !luzAcesa;
        const ok = await leitor.lanterna(luzAcesa);
        if (!ok) { luzAcesa = false; btnLuz.hidden = true; return; }
        btnLuz.classList.toggle('botao-ligado', luzAcesa);
    });

    let ultimo = '';
    let quando = 0;

    function aoBipar(codigo) {
        // O leitor repete o mesmo codigo enquanto o produto esta na mira. Sem
        // esta trava, o produto parado na frente da camera recarregaria a
        // ficha por cima do que ja estava sendo digitado.
        const agora = Date.now();
        if (codigo === ultimo && agora - quando < 3000) return;
        ultimo = codigo;
        quando = agora;

        if (navigator.vibrate) navigator.vibrate(60);
        caixa.classList.add('leu');
        setTimeout(() => caixa.classList.remove('leu'), 500);
        dica.textContent = 'Procurando no planograma...';

        tela.dispatchEvent(new CustomEvent('planograma:codigo', { detail: codigo }));
    }

    document.addEventListener('visibilitychange', () => {
        if (!leitor) return;
        if (document.hidden) { leitor.pausar(); } else { leitor.retomar(); }
    });

    (async function abrirSozinha() {
        if (!querAutomatico()) return;
        const estadoPerm = await Scanner.permissao();
        if (estadoPerm === 'denied') { espera.textContent = 'Sem permissão de câmera'; return; }
        if (estadoPerm === 'granted' || jaAbriuNestaSessao()) { ligar(true); return; }
        espera.textContent = 'Toque em "Ligar câmera"';
    })();
})();
</script>

<?php endif; ?>
