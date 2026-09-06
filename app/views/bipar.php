<a class="voltar" href="/produtos">‹ Produtos</a>
<h1>Bipar produto</h1>
<p class="ajuda">Aponte para o <strong>código de barras</strong> do produto na prateleira.</p>

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

<form id="form-manual" class="linha-form">
    <input id="ean" type="text" inputmode="numeric" pattern="[0-9]*"
           placeholder="ou digite o código" autocomplete="off">
    <button type="submit" class="botao botao-alt">Buscar</button>
</form>

<div id="resultado"></div>

<script src="/assets/scanner.js?v=2"></script>
<script>
(function () {
    const AUTO   = 'mercadinho:camera-auto';
    const video  = document.getElementById('video');
    const caixa  = document.getElementById('camera');
    const espera = document.getElementById('espera-texto');
    const dica   = document.getElementById('dica');
    const btnCam = document.getElementById('btn-camera');
    const btnLuz = document.getElementById('btn-luz');
    const campo  = document.getElementById('ean');
    const alvo   = document.getElementById('resultado');
    let leitor = null;
    let luzAcesa = false;

    /** O que o dedo digita vem com virgula; e as vezes com "R$" junto. */
    function numero(v) {
        const limpo = String(v || '').replace(/[^0-9,.]/g, '').replace(/\./g, '').replace(',', '.');
        const n = parseFloat(limpo);
        return isFinite(n) ? n : 0;
    }

    function moeda(v) {
        return 'R$ ' + Number(v).toFixed(2).replace('.', ',');
    }

    function qtd(v) {
        const n = Number(v);
        return Number.isInteger(n) ? String(n) : n.toFixed(3).replace('.', ',');
    }

    function esc(t) {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    /** Preco de venda e estoque vindos do TouchPay, por ponto de venda. */
    function blocoLoja(loja) {
        if (!loja || !loja.length) return '';
        const linhas = loja.map(l => {
            const estoque = l.estoque > 0
                ? qtd(l.estoque) + ' em estoque'
                : '<span class="zerado">sem estoque</span>';
            const reservado = l.reservado > 0 ? ' · ' + qtd(l.reservado) + ' reservado' : '';
            return '<li>' +
                '<div class="linha-topo"><span class="forte">' + esc(l.pdv) + '</span>' +
                '<span class="valor">' + (l.preco == null ? '—' : moeda(l.preco)) + '</span></div>' +
                '<div class="linha-baixo"><span>' + estoque + reservado + '</span>' +
                '<span>' + esc(l.atualizado) + '</span></div></li>';
        }).join('');
        return '<h2>Na loja agora</h2><ul class="lista">' + linhas + '</ul>';
    }

    /** A conta que interessa: vendo por X, paguei Y. */
    function blocoMargem(loja, stats) {
        if (!loja || !loja.length || !stats || !stats.ultimo) return '';
        const comPreco = loja.filter(l => l.preco != null);
        if (!comPreco.length) return '';
        const venda = Math.max(...comPreco.map(l => l.preco));
        const custo = Number(stats.ultimo);
        if (!(custo > 0)) return '';
        const margem = venda - custo;
        const sinal = margem >= 0 ? '+' : '−';
        const perc = Math.abs(margem / custo) * 100;
        const fator = (venda / custo).toFixed(2).replace('.', ',');
        return '<div class="margem ' + (margem >= 0 ? 'margem-boa' : 'margem-ruim') + '">' +
            '<strong class="margem-fator">' + fator + '<span>x</span></strong>' +
            '<div class="margem-conta">' +
            '<span class="margem-lucro">' + sinal + moeda(Math.abs(margem)) +
            ' <span class="margem-perc">' + sinal + perc.toFixed(0) + '%</span></span>' +
            '<span class="margem-linha">vende ' + moeda(venda) + ' · pagou ' + moeda(custo) + '</span>' +
            '</div></div>';
    }


    /** O maior preco de venda entre os PDVs, que e o que a conta usa. */
    function precoDeVenda(loja) {
        const comPreco = (loja || []).filter(l => l.preco != null);
        return comPreco.length ? Math.max(...comPreco.map(l => l.preco)) : null;
    }

    /**
     * Vale a pena comprar por esse preco?
     *
     * Desconta do preco de venda o que acompanha o faturamento (maquininha,
     * condominio e franquia) antes de comparar com o custo. Sem isso a conta
     * mente para cima: um produto com fator 1,2x ja da prejuizo depois dos
     * ~11,7% que saem de toda venda.
     *
     * Custo fixo (energia, sistema) fica de fora de proposito: ele nao muda
     * com a decisao de comprar mais uma unidade deste produto.
     */
    function valeAPena(custo, venda, pctVariavel, ultimoPago) {
        if (!(custo > 0) || !(venda > 0)) return null;
        const variavel = venda * (pctVariavel || 0) / 100;
        const lucro = venda - custo - variavel;
        return {
            custo, venda, variavel, lucro,
            fator: venda / custo,
            margem: lucro / venda * 100,
            // Empate conta como "no limite": lucro zero nao e negocio.
            veredito: lucro > 0.005 ? 'vale' : (lucro < -0.005 ? 'nao' : 'limite'),
            // Comparacao com o que ele costuma pagar, quando ha historico.
            versusUltimo: ultimoPago > 0 ? (custo - ultimoPago) / ultimoPago * 100 : null,
        };
    }

    /** O bloco de "quanto estao cobrando?" com a resposta embaixo. */
    function blocoVale(d) {
        const venda = precoDeVenda(d.loja);
        if (venda == null) {
            return '<div class="cartao"><h2 class="sem-topo">Vale a pena?</h2>' +
                '<p class="ajuda">Este produto não tem preço de venda no planograma, ' +
                'então não dá para dizer se compensa. Defina o preço no TouchPay e ' +
                'sincronize.</p></div>';
        }
        const custos = d.custos || {};
        return '<div class="cartao">' +
            '<h2 class="sem-topo">Vale a pena?</h2>' +
            '<p class="ajuda">Quanto estão cobrando por uma unidade agora?</p>' +
            '<div class="linha-form">' +
              '<input id="custo-agora" type="text" inputmode="decimal" placeholder="ex.: 3,89" ' +
                     'autocomplete="off" autocorrect="off" spellcheck="false">' +
              '<button type="button" id="btn-vale" class="botao">Calcular</button>' +
            '</div>' +
            '<div id="vale-resposta"></div>' +
            '<p class="ajuda">Você vende por <strong>' + moeda(venda) + '</strong>. ' +
            'A conta desconta ' + (custos.pct || 0).toFixed(2).replace('.', ',') + '% ' +
            'que sai de toda venda (maquininha, condomínio e franquia)' +
            (custos.tem_mix === false ? ', ainda sem a taxa da maquininha por falta de venda no período' : '') +
            '. Energia e sistema ficam de fora: não mudam por comprar mais uma unidade.</p>' +
            '</div>';
    }

    /** Liga o campo de "vale a pena" que o blocoVale acabou de desenhar. */
    function ligarVale(d) {
        const campo = document.getElementById('custo-agora');
        const botao = document.getElementById('btn-vale');
        const alvoR = document.getElementById('vale-resposta');
        if (!campo || !botao || !alvoR) return;

        const venda = precoDeVenda(d.loja);
        const pct = (d.custos && d.custos.pct) || 0;
        const ultimo = (d.stats && Number(d.stats.ultimo)) || 0;

        function responder() {
            const v = valeAPena(numero(campo.value), venda, pct, ultimo);
            if (!v) { alvoR.innerHTML = ''; return; }

            const classe = v.veredito === 'vale' ? 'margem-boa' : 'margem-ruim';
            const titulo = v.veredito === 'vale' ? 'Vale a pena'
                        : (v.veredito === 'limite' ? 'No limite' : 'Não vale');

            // Prejuizo se diz "perde", nao "sobra R$ -0,09".
            let linhas = (v.lucro >= 0 ? 'sobra ' : 'perde ') + moeda(Math.abs(v.lucro)) +
                         ' por unidade · vende ' + moeda(v.venda);
            if (v.versusUltimo != null) {
                const sinal = v.versusUltimo >= 0 ? '+' : '−';
                linhas += ' · ' + sinal + Math.abs(v.versusUltimo).toFixed(0) +
                          '% vs. os ' + moeda(ultimo) + ' que você pagou';
            }

            alvoR.innerHTML =
                '<div class="margem ' + classe + '">' +
                '<strong class="margem-fator">' + v.fator.toFixed(2).replace('.', ',') + '<span>x</span></strong>' +
                '<div class="margem-conta">' +
                '<span class="margem-lucro">' + titulo +
                ' <span class="margem-perc">' + v.margem.toFixed(0) + '% do preço</span></span>' +
                '<span class="margem-linha">' + linhas + '</span>' +
                '</div></div>';
        }

        botao.addEventListener('click', responder);
        campo.addEventListener('input', responder);
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
        } catch (e) {
            leitor = null;
            dica.textContent = '';
            if (automatico) {
                espera.textContent = 'Toque em "Ligar câmera"';
                return;
            }
            espera.textContent = e.semPermissao ? 'Sem permissão de câmera' : 'Câmera indisponível';
            alvo.innerHTML = '<div class="aviso aviso-erro">' +
                (e.semPermissao ? e.message + '<br>' + Scanner.comoLiberar() : (e.message || 'Erro na câmera')) +
                '</div>';
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

    document.getElementById('form-manual').addEventListener('submit', (ev) => {
        ev.preventDefault();
        if (campo.value.trim()) buscar(campo.value.trim());
    });

    function aoBipar(codigo) {
        campo.value = codigo;
        if (navigator.vibrate) navigator.vibrate(60);
        caixa.classList.add('leu');
        setTimeout(() => caixa.classList.remove('leu'), 500);
        // A camera segue ligada: bipar o proximo produto e so apontar.
        buscar(codigo);
    }

    async function buscar(ean) {
        dica.textContent = 'Buscando ' + ean + '...';
        alvo.innerHTML = '<p class="ajuda">Buscando ' + ean + '...</p>';
        try {
            const r = await fetch('/api/produto?ean=' + encodeURIComponent(ean));
            const d = await r.json();
            dica.textContent = leitor ? 'Aponte para o próximo produto' : '';

            if (!d.encontrado) {
                const naLoja = (d.loja && d.loja.length) ? d.loja[0] : null;
                alvo.innerHTML =
                    '<div class="cartao centro">' +
                    '<p class="grande">' + (naLoja && naLoja.preco != null ? moeda(naLoja.preco) : '—') + '</p>' +
                    '<p>' + esc(naLoja ? naLoja.descricao : (d.mensagem || 'Você nunca comprou esse produto.')) + '</p>' +
                    (naLoja ? '<p class="ajuda">Preço de venda na loja. Você ainda não comprou esse produto.</p>' : '') +
                    '<p class="mono">' + esc(d.ean || ean) + '</p>' +
                    '<a class="botao" href="/manual?ean=' + encodeURIComponent(d.ean || ean) + '">Cadastrar compra</a>' +
                    '</div>' +
                    blocoVale(d) +
                    blocoLoja(d.loja);
                ligarVale(d);
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
                blocoMargem(d.loja, d.stats) +
                '</div>' +
                blocoVale(d) +
                blocoLoja(d.loja) +
                '<h2>Você pagou</h2>' +
                '<ul class="lista">' + linhas + '</ul>' +
                '<p class="centro"><a href="' + d.url + '">Ver histórico completo</a></p>';

            ligarVale(d);
        } catch (e) {
            dica.textContent = leitor ? 'Aponte para o próximo produto' : '';
            alvo.innerHTML = '<div class="aviso aviso-erro">Falha na busca: ' + e.message + '</div>';
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (!leitor) return;
        if (document.hidden) { leitor.pausar(); } else { leitor.retomar(); }
    });

    (async function abrirSozinha() {
        if (!querAutomatico()) return;
        const estadoPerm = await Scanner.permissao();
        if (estadoPerm === 'denied') {
            espera.textContent = 'Sem permissão de câmera';
            alvo.innerHTML = '<div class="aviso aviso-erro">' + Scanner.comoLiberar() + '</div>';
            return;
        }
        if (estadoPerm === 'granted' || estadoPerm === 'desconhecido') {
            ligar(true);
        }
    })();
})();
</script>
