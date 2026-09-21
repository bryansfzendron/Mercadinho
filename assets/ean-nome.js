/*
 * O nome de um codigo de barras, do lado do navegador.
 *
 * Duas telas perguntam a mesma coisa. No Mercado, bipando na gondola; na nota
 * manual, lancando o cupom da feira depois. Antes isto morava so no Mercado, e
 * na nota manual a pessoa digitava "Leite Moca 395g" com o codigo ja no campo
 * ao lado — o app sabia o nome e nao dizia.
 *
 * Sao duas memorias, e a ordem importa. A deste aparelho responde primeiro e
 * sem rede: no corredor do fundo do mercado e a unica que responde. A do
 * servidor vem depois e por cima, porque e ela quem sabe das suas notas, do
 * espelho da loja e do que a Open Food Facts respondeu — a cascata inteira
 * esta em app/mercado.php.
 *
 * Nome de produto e enfeite: sem rede, sem resposta ou fora do ar, o campo
 * fica vazio e se digita o nome na mao, que e o que se fazia antes.
 */
(function (global) {
    'use strict';

    const CHAVE_NOMES = 'mercadinho:nomes';

    /*
     * A paciencia da consulta. Quem esta com o carrinho parado no corredor
     * espera menos do que um servidor demora para desistir sozinho.
     */
    const PACIENCIA_MS = 4000;

    /**
     * A chave de um codigo de barras: so digitos, no maximo 14 — a mesma do
     * servidor (mercado_chave), senao a memoria daqui e a de la guardariam o
     * mesmo produto em duas gavetas diferentes.
     *
     * Codigo curto demais nao vira chave: "123" digitado pela metade nao e
     * produto nenhum, e procurar por ele so gastaria rede.
     */
    function chave(codigo) {
        const d = String(codigo == null ? '' : codigo).replace(/\D/g, '');
        return d.length >= 6 ? d.slice(0, 14) : '';
    }

    /*
     * A memoria de nomes deste aparelho. Separada da lista de compras de
     * proposito: limpar a compra nao pode esquecer o que se aprendeu sobre os
     * produtos — na proxima ida ao mercado eles sao os mesmos.
     */
    function lerTodos() {
        try {
            return JSON.parse(global.localStorage.getItem(CHAVE_NOMES) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    /** O nome guardado neste aparelho, ou '' quando nao ha. Nao usa rede. */
    function local(codigo) {
        const k = chave(codigo);
        return k ? (lerTodos()[k] || '') : '';
    }

    /** Guarda o nome daquele codigo neste aparelho. */
    function lembrar(codigo, nome) {
        const k = chave(codigo);
        if (!k || !nome) return;
        try {
            const nomes = lerTodos();
            nomes[k] = nome;
            global.localStorage.setItem(CHAVE_NOMES, JSON.stringify(nomes));
        } catch (e) { /* modo privado, ou memoria cheia */ }
    }

    /**
     * Manda para o servidor o nome que a pessoa digitou. Fire-and-forget: sem
     * sinal, a memoria deste aparelho ja guardou.
     */
    function guardar(codigo, nome) {
        const doc = global.document;
        const meta = doc && doc.querySelector('meta[name="csrf"]');
        if (!meta) return;
        global.fetch('/api/ean', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': meta.getAttribute('content'),
            },
            body: JSON.stringify({ codigo: codigo, nome: nome }),
        }).catch(() => { /* fica para a proxima */ });
    }

    /**
     * Pergunta ao servidor. Devolve `{nome, fonte, ultimo}` ou null quando a
     * rede nao veio a tempo — nunca lanca: quem chama esta no meio de uma
     * compra e nao tem o que fazer com um erro.
     *
     * O nome que vier e guardado neste aparelho, para responder sem rede da
     * proxima vez.
     */
    async function buscar(codigo) {
        try {
            const corta = new AbortController();
            const relogio = setTimeout(() => corta.abort(), PACIENCIA_MS);
            const r = await global.fetch(
                '/api/ean?codigo=' + encodeURIComponent(codigo),
                { signal: corta.signal }
            );
            clearTimeout(relogio);
            const d = await r.json();
            if (d && d.nome) {
                lembrar(codigo, d.nome);
            }
            return d || null;
        } catch (e) {
            return null;
        }
    }

    const api = { chave, local, lembrar, guardar, buscar, CHAVE_NOMES, PACIENCIA_MS };

    global.EanNome = api;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
