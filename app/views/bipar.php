<h1>Bipar produto</h1>
<p class="ajuda">Aponte para o <strong>código de barras</strong> do produto na prateleira.</p>

<div class="camera-caixa">
    <video id="video" muted playsinline></video>
    <div class="mira mira-larga"></div>
</div>

<div class="acoes">
    <button id="btn-camera" class="botao">Ligar câmera</button>
</div>

<form id="form-manual" class="linha-form">
    <input id="ean" type="text" inputmode="numeric" pattern="[0-9]*"
           placeholder="ou digite o código" autocomplete="off">
    <button type="submit" class="botao botao-alt">Buscar</button>
</form>

<div id="resultado"></div>

<script src="/assets/scanner.js?v=1"></script>
<script>
(function () {
    const video  = document.getElementById('video');
    const btnCam = document.getElementById('btn-camera');
    const campo  = document.getElementById('ean');
    const alvo   = document.getElementById('resultado');
    let leitor = null;

    function moeda(v) {
        return 'R$ ' + Number(v).toFixed(2).replace('.', ',');
    }

    btnCam.addEventListener('click', async () => {
        if (leitor) { leitor.parar(); leitor = null; btnCam.textContent = 'Ligar câmera'; return; }
        try {
            leitor = await Scanner.iniciar(video, Scanner.BARRAS, aoBipar);
            btnCam.textContent = 'Desligar câmera';
            alvo.innerHTML = '<p class="ajuda">Procurando o código...</p>';
        } catch (e) {
            alvo.innerHTML = '<div class="aviso aviso-erro">' + (e.message || 'Erro na câmera') + '</div>';
        }
    });

    document.getElementById('form-manual').addEventListener('submit', (ev) => {
        ev.preventDefault();
        if (campo.value.trim()) buscar(campo.value.trim());
    });

    function aoBipar(codigo) {
        campo.value = codigo;
        if (navigator.vibrate) navigator.vibrate(60);
        buscar(codigo);
    }

    async function buscar(ean) {
        alvo.innerHTML = '<p class="ajuda">Buscando ' + ean + '...</p>';
        try {
            const r = await fetch('/api/produto?ean=' + encodeURIComponent(ean));
            const d = await r.json();

            if (!d.encontrado) {
                alvo.innerHTML =
                    '<div class="cartao centro">' +
                    '<p class="grande">—</p>' +
                    '<p>' + (d.mensagem || 'Você nunca comprou esse produto.') + '</p>' +
                    '<p class="mono">' + (d.ean || ean) + '</p>' +
                    '<a class="botao" href="/manual?ean=' + encodeURIComponent(d.ean || ean) + '">Cadastrar compra</a>' +
                    '</div>';
                return;
            }

            let linhas = d.ultimas.map(u =>
                '<li><div class="linha-topo"><span class="forte">' + u.loja + '</span>' +
                '<span class="valor">' + moeda(u.unitario) + '</span></div>' +
                '<div class="linha-baixo"><span>' + u.data + '</span></div></li>'
            ).join('');

            alvo.innerHTML =
                '<div class="cartao">' +
                '<h2>' + d.descricao + '</h2>' +
                '<p class="mono">' + d.ean + '</p>' +
                '<div class="numeros">' +
                '<div class="numero"><strong>' + moeda(d.stats.ultimo) + '</strong><span>último</span></div>' +
                '<div class="numero"><strong>' + moeda(d.stats.min) + '</strong><span>menor</span></div>' +
                '<div class="numero"><strong>' + moeda(d.stats.max) + '</strong><span>maior</span></div>' +
                '</div>' +
                '<p class="ajuda">' + d.stats.n + ' compra(s) registradas</p>' +
                '</div>' +
                '<ul class="lista">' + linhas + '</ul>' +
                '<p class="centro"><a href="' + d.url + '">Ver histórico completo</a></p>';
        } catch (e) {
            alvo.innerHTML = '<div class="aviso aviso-erro">Falha na busca: ' + e.message + '</div>';
        }
    }
})();
</script>
