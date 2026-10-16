/*
 * Repor em lote: a fila do navegador.
 *
 * O servidor corta a requisicao em ~30s e o TouchPay so aceita uma chamada
 * por item, entao esta tela manda UMA linha por requisicao, em sequencia:
 *
 *   fase 1 (conferir): le o TouchPay e mostra o que vai mudar. Nao escreve.
 *   fase 2 (gravar):   so depois do clique em "Gravar".
 *
 * Quem decide se uma linha pode rodar de novo e o banco (status da linha), nao
 * esta tela: F5, duas abas ou clique duplo nao somam o estoque duas vezes.
 * As regras da fila moram em funcoes puras, testadas no Node
 * (testes/repor-lote.js).
 */
(function (global) {
    'use strict';

    const ROTULOS = {
        pendente:     'aguardando',
        planograma:   'pronta · atualiza',
        cadastro:     'pronta · entra no planograma',
        sem_cadastro: 'sem cadastro',
        pendencia:    'falta preço',
        sem_mudanca:  'nada a mudar',
        gravando:     'gravando…',
        gravada:      'gravada',
        erro:         'erro',
    };

    const SELOS = {
        gravada: 'selo-ok', planograma: 'selo-ok', cadastro: 'selo-ok',
        erro: 'selo-erro', sem_cadastro: 'selo-pendente', pendencia: 'selo-pendente',
        pendente: 'selo-pendente', gravando: 'selo-pendente',
    };

    /** Quais linhas cada fase manda, na ordem da planilha. */
    function fila(linhas, fase, incluirErros) {
        const aceitos = fase === 'gravar'
            ? ['planograma', 'cadastro']
            : ['pendente'];
        if (incluirErros) aceitos.push('erro');
        return linhas.filter(function (l) { return aceitos.indexOf(l.status) >= 0; });
    }

    /** "Reconferir" manda de novo tudo que ainda nao foi gravado. */
    function filaReconferir(linhas) {
        return linhas.filter(function (l) {
            return ['gravada', 'gravando'].indexOf(l.status) < 0;
        });
    }

    function resumo(linhas) {
        const r = { total: linhas.length };
        Object.keys(ROTULOS).forEach(function (s) { r[s] = 0; });
        linhas.forEach(function (l) { r[l.status] = (r[l.status] || 0) + 1; });
        r.aGravar = r.planograma + r.cadastro;
        r.conferidas = r.total - r.pendente;
        return r;
    }

    /** Linhas que tiveram resposta final; a barra anda com elas. */
    function feitas(linhas, fase) {
        return linhas.filter(function (l) {
            return fase === 'gravar'
                ? ['gravada', 'erro', 'sem_mudanca', 'sem_cadastro', 'pendencia'].indexOf(l.status) >= 0
                : l.status !== 'pendente';
        }).length;
    }

    function textoSemCadastro(linhas) {
        const sem = linhas.filter(function (l) { return l.status === 'sem_cadastro'; });
        if (!sem.length) return '';
        return 'Produtos para cadastrar no TouchPay:\n' + sem.map(function (l) {
            return '- ' + l.codigo + (l.descricao ? ' · ' + l.descricao : '');
        }).join('\n');
    }

    function textoProgresso(linhas, fase) {
        const r = resumo(linhas);
        const n = feitas(linhas, fase);
        const partes = [n + ' de ' + r.total + (fase === 'gravar' ? ' gravadas/resolvidas' : ' conferidas')];
        if (r.sem_cadastro) partes.push(r.sem_cadastro + ' sem cadastro');
        if (r.pendencia) partes.push(r.pendencia + ' sem preço');
        if (r.erro) partes.push(r.erro + ' com erro');
        return partes.join(' · ');
    }

    /**
     * Roda a fila UMA por vez. `passo(linha)` devolve a linha atualizada;
     * `aoAtualizar(nova, indice)` redesenha. `parar()` cancela entre linhas.
     * Erro de rede PARA a fila: numa gravacao o servidor pode ter escrito e a
     * resposta se perdeu, entao seguir adiante esconderia o estado incerto.
     */
    async function rodar(itens, passo, aoAtualizar, parar) {
        for (let i = 0; i < itens.length; i++) {
            if (parar && parar()) return { parou: true, motivo: 'cancelado' };
            let nova;
            try {
                nova = await passo(itens[i]);
            } catch (e) {
                return { parou: true, motivo: e && e.message ? e.message : 'sem resposta' };
            }
            aoAtualizar(nova, i);
        }
        return { parou: false };
    }

    const api = { ROTULOS: ROTULOS, fila: fila, filaReconferir: filaReconferir, resumo: resumo,
                  feitas: feitas, textoSemCadastro: textoSemCadastro, textoProgresso: textoProgresso,
                  rodar: rodar };

    // ----------------------------------------------------------------- tela
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function iniciar() {
        const doc = global.document;
        const raiz = doc.getElementById('lote');
        if (!raiz) return;

        const loteId = raiz.getAttribute('data-lote');
        let linhas = JSON.parse(raiz.getAttribute('data-linhas') || '[]');
        const meta = doc.querySelector('meta[name="csrf"]');
        const csrf = meta ? meta.getAttribute('content') : '';
        let fase = 'conferir';
        let ocupado = false;

        const el = function (id) { return doc.getElementById(id); };

        async function chamar(acao, linha) {
            const r = await global.fetch('/api/planograma/lote/' + loteId + '/' + acao + '/' + linha, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
                body: '{}',
            });
            if (r.status === 401 || r.status === 419) {
                throw new Error('Sua sessão expirou. Recarregue a página e entre de novo.');
            }
            let d = null;
            try { d = await r.json(); } catch (e) { d = null; }
            if (!r.ok || !d || d.erro) {
                throw new Error((d && d.erro) || 'Sem resposta do servidor. Recarregue para ver o estado.');
            }
            return d;
        }

        function desenhar() {
            el('lt-corpo').innerHTML = linhas.map(function (l) {
                const muda = l.mudancas ? esc(l.mudancas) : '<span class="ajuda">—</span>';
                const extra = [l.nota, l.pendencia, l.mensagem && l.status !== 'gravada' ? l.mensagem : '']
                    .filter(Boolean).map(function (t) { return '<br><small class="ajuda">' + esc(t) + '</small>'; }).join('');
                const entrada = l.entrada ? ' <small class="ajuda">(+' + esc(l.entrada) + ')</small>' : '';
                return '<tr><td>' + l.linha + '</td>'
                    + '<td><span class="forte">' + esc(l.descricao || l.codigo) + '</span>' + entrada
                    + '<br><small class="ajuda">' + esc(l.codigo) + '</small></td>'
                    + '<td>' + esc(l.pdv) + '</td>'
                    + '<td style="white-space:normal">' + muda + extra + '</td>'
                    + '<td><span class="selo ' + (SELOS[l.status] || '') + '">' + esc(ROTULOS[l.status] || l.status) + '</span></td></tr>';
            }).join('');

            const r = resumo(linhas);
            const n = feitas(linhas, fase);
            el('lt-barra').style.width = (r.total ? Math.round(n * 100 / r.total) : 0) + '%';
            el('lt-texto').textContent = textoProgresso(linhas, fase);

            const sem = linhas.filter(function (l) { return l.status === 'sem_cadastro'; });
            el('lt-sem').hidden = sem.length === 0;
            el('lt-sem-lista').innerHTML = sem.map(function (l) {
                return '<li><div class="linha-cartao"><span class="forte">' + esc(l.descricao || l.codigo)
                    + '</span> <small class="ajuda">' + esc(l.codigo) + '</small></div></li>';
            }).join('');

            const bot = el('lt-gravar');
            bot.disabled = ocupado || r.aGravar === 0;
            bot.textContent = r.aGravar ? 'Gravar ' + r.aGravar + ' linha(s) no TouchPay' : 'Nada para gravar';
            el('lt-repetir').hidden = r.erro === 0 || ocupado;
        }

        function atualizar(nova) {
            const i = linhas.findIndex(function (l) { return l.linha === nova.linha; });
            if (i >= 0) linhas[i] = nova;
            desenhar();
        }

        function aviso(t) { el('lt-aviso').textContent = t; }

        async function executar(nomeFase, itens) {
            if (ocupado) return;
            if (!itens.length) {
                aviso(resumo(linhas).aGravar ? 'Conferido. Veja o que vai mudar e clique em Gravar.' : 'Nada pendente neste lote.');
                return;
            }
            ocupado = true;
            fase = nomeFase;
            desenhar();
            aviso(nomeFase === 'gravar'
                ? 'Gravando no TouchPay, uma linha por vez. Pode demorar; não feche esta aba.'
                : 'Conferindo no TouchPay… nada é gravado nesta etapa.');
            const acao = nomeFase === 'gravar' ? 'gravar' : 'conferir';
            const fim = await rodar(itens, function (l) { return chamar(acao, l.linha); }, atualizar);
            ocupado = false;
            desenhar();
            aviso(fim.parou
                ? 'Parei: ' + fim.motivo
                : (nomeFase === 'gravar'
                    ? 'Pronto. Confira as linhas com erro e o que ficou sem cadastro.'
                    : 'Conferido. Veja o que vai mudar e clique em Gravar.'));
        }

        el('lt-gravar').addEventListener('click', function () {
            const r = resumo(linhas);
            if (!global.confirm('Gravar ' + r.aGravar + ' linha(s) no TouchPay? Preço e estoque vão mudar na loja.')) return;
            executar('gravar', fila(linhas, 'gravar', false));
        });
        el('lt-repetir').addEventListener('click', function () {
            executar('gravar', fila(linhas, 'gravar', true).filter(function (l) { return l.status === 'erro'; }));
        });
        el('lt-reconferir').addEventListener('click', function () {
            executar('conferir', filaReconferir(linhas));
        });
        el('lt-copiar').addEventListener('click', function () {
            const t = textoSemCadastro(linhas);
            if (global.navigator.clipboard) {
                global.navigator.clipboard.writeText(t).then(function () { aviso('Lista copiada.'); });
            } else {
                global.prompt('Copie o texto:', t);
            }
        });

        desenhar();
        // Abriu com linhas por conferir (lote novo, ou a aba foi fechada no meio).
        executar('conferir', fila(linhas, 'conferir', false));
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (global.document) {
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', iniciar);
        } else {
            iniciar();
        }
    }
})(typeof window !== 'undefined' ? window : globalThis);
