/*
 * Ler a resposta de um fetch sem transformar erro do servidor em "falha de rede".
 *
 * `r.json()` estoura quando a resposta NAO e JSON — pagina de erro da
 * hospedagem, corte por tempo, 500 com HTML, sessao expirada devolvendo o
 * login. Como a chamada mora dentro de um try que rotula tudo como rede, o
 * que sobrava na tela era a mensagem do navegador. No Safari ela e "The
 * string did not match the expected pattern", que nao diz nada a ninguem e
 * ainda joga a culpa no lugar errado: a rede funcionou, quem respondeu
 * torto foi o servidor.
 *
 * Aqui a resposta e lida como TEXTO primeiro. Sendo JSON, devolve o objeto.
 * Nao sendo, levanta um erro que diz o status HTTP e o comeco do que veio —
 * que e o que permite separar timeout de 500 e de sessao expirada sem
 * precisar abrir o log do servidor.
 */
(function (global) {
    'use strict';

    /** O comeco do que veio, legivel: sem tags e sem quebra de linha. */
    function resumir(texto) {
        const limpo = String(texto || '')
            .replace(/<script[\s\S]*?<\/script>/gi, ' ')
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        return limpo.length > 160 ? limpo.slice(0, 160) + '…' : limpo;
    }

    /**
     * O JSON da resposta, ou um erro que se explica.
     *
     * O erro carrega `http` e `corpo` para quem chama poder decidir: com
     * `http` preenchido houve resposta e a culpa e do servidor; sem ele, a
     * requisicao nem chegou e aí sim foi a rede.
     */
    async function ler(r) {
        const texto = await r.text();
        try {
            return JSON.parse(texto);
        } catch (e) {
            const resumo = resumir(texto);
            const erro = new Error(
                'o servidor respondeu HTTP ' + r.status + (
                    resumo
                        ? ' — ' + resumo
                        : ' sem corpo nenhum (provavelmente estourou o tempo)'
                )
            );
            erro.http = r.status;
            erro.corpo = texto;
            throw erro;
        }
    }

    /** A frase que vai para a tela, sem chamar de rede o que nao foi. */
    function frase(e) {
        return e && e.http ? 'Não deu: ' + e.message : 'Falha de rede: ' + e.message;
    }

    global.Resposta = { ler, resumir, frase };
    // Em Node (teste) nao ha window, e os testes que exercitam o caminho do
    // fetch precisam do MESMO helper que o navegador usa — um stub aqui
    // deixaria de cobrir justamente a parte que acabou de dar problema.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = global.Resposta;
    }
})(typeof window !== 'undefined' ? window : globalThis);
