/*
 * O nome de um codigo de barras, do lado do navegador — a parte que roda sem
 * navegador. Duas telas dependem deste arquivo (Mercado e lancamento manual),
 * entao um erro aqui aparece nas duas.
 *
 *   node testes/ean-nome.js
 */
// O helper que le a resposta e global no navegador (layout.php o carrega
// em toda tela). Aqui ele entra antes do modulo que depende dele.
require('../assets/resposta.js');
const E = require('../assets/ean-nome.js');

let ok = 0, falhou = 0;
function checar(nome, obtido, esperado) {
    if (obtido === esperado) { ok++; return; }
    falhou++;
    console.log(`FALHOU  ${nome}\n   esperado: ${JSON.stringify(esperado)}\n   obtido:   ${JSON.stringify(obtido)}`);
}

// ------------------------------------------- a chave do codigo de barras
// A mesma do servidor (mercado_chave). Se as duas divergirem, o nome guardado
// aqui e o guardado la viram gavetas diferentes do mesmo item.
checar('EAN-13 vira chave', E.chave('7891000100103'), '7891000100103');
checar('espaco e hifen saem', E.chave(' 789-1000 100103 '), '7891000100103');
checar('codigo de balanca entra', E.chave('2001234000005'), '2001234000005');
checar('codigo curto demais nao vira chave', E.chave('123'), '');
checar('vazio nao vira chave', E.chave(''), '');
checar('nulo nao quebra', E.chave(null), '');
checar('chave nao passa de 14 digitos', E.chave('123456789012345678'), '12345678901234');

// ------------------------------------------- a memoria deste aparelho
const gaveta = {};
globalThis.localStorage = {
    getItem: (k) => (k in gaveta ? gaveta[k] : null),
    setItem: (k, v) => { gaveta[k] = String(v); },
};

checar('codigo nunca visto nao tem nome', E.local('7891000100103'), '');
E.lembrar('7891000100103', 'Leite Moça 395g');
checar('o nome guardado volta', E.local('7891000100103'), 'Leite Moça 395g');
// O codigo chega de jeitos diferentes (bipado, digitado com espaco): a chave e
// que manda, senao o mesmo produto ocuparia duas gavetas.
checar('o mesmo codigo sujo acha o nome', E.local(' 789 1000 100103 '), 'Leite Moça 395g');
checar('outro codigo continua sem nome', E.local('7891000100110'), '');

E.lembrar('7891000100103', '');
checar('nome vazio nao apaga o que ja se sabia', E.local('7891000100103'), 'Leite Moça 395g');
E.lembrar('123', 'nao deveria entrar');
checar('codigo curto demais nao ocupa gaveta', Object.keys(gaveta[E.CHAVE_NOMES] ? JSON.parse(gaveta[E.CHAVE_NOMES]) : {}).length, 1);

// Modo privado: localStorage existe e recusa gravar. A compra nao pode parar.
globalThis.localStorage = {
    getItem: () => { throw new Error('modo privado'); },
    setItem: () => { throw new Error('modo privado'); },
};
checar('modo privado nao quebra a leitura', E.local('7891000100103'), '');
E.lembrar('7891000100103', 'Leite Moça 395g'); // nao pode lancar

// ------------------------------------------- a memoria do servidor
(async function () {
    globalThis.localStorage = {
        getItem: (k) => (k in gaveta ? gaveta[k] : null),
        setItem: (k, v) => { gaveta[k] = String(v); },
    };

    // Sem rede o nome e enfeite: devolve null e quem chamou segue digitando.
    globalThis.fetch = () => Promise.reject(new Error('sem sinal'));
    checar('sem rede a busca devolve null', await E.buscar('7891000100110'), null);

    // Resposta quebrada tambem nao pode lancar: no corredor do fundo o
    // proxy do mercado devolve HTML de portal cativo, nao JSON.
    // O stub imita uma Response de verdade — status e text() —, porque e assim
    // que Resposta.ler() a le: texto primeiro, JSON depois. Era esse caminho
    // que transformava erro do servidor em "falha de rede" na tela.
    const resposta = (corpo, status = 200) => Promise.resolve({
        status,
        text: () => Promise.resolve(typeof corpo === 'string' ? corpo : JSON.stringify(corpo)),
    });

    globalThis.fetch = () => resposta('<html>portal cativo</html>', 200);
    checar('resposta que nao e json devolve null', await E.buscar('7891000100110'), null);

    globalThis.fetch = () => resposta(
        { codigo: '7891000100110', nome: 'Café 3 Corações 500g', ultimo: 18.9 });
    const d = await E.buscar('7891000100110');
    checar('o nome vem do servidor', d.nome, 'Café 3 Corações 500g');
    checar('e quanto se pagou da ultima vez vem junto', d.ultimo, 18.9);
    // Guardado aqui, responde sem rede da proxima vez — que e o unico jeito no
    // corredor onde o sinal cai.
    checar('o nome do servidor fica no aparelho', E.local('7891000100110'), 'Café 3 Corações 500g');

    // Codigo que base nenhuma conhece: nome nulo, e nada e guardado.
    globalThis.fetch = () => resposta({ codigo: '7891000100127', nome: null, ultimo: null });
    const vazio = await E.buscar('7891000100127');
    checar('produto desconhecido volta sem nome', vazio.nome, null);
    checar('produto desconhecido nao ocupa gaveta', E.local('7891000100127'), '');

    // O caso que veio do iPhone: a hospedagem corta e devolve pagina de erro.
    // Nao pode lancar, e o motivo tem de sobrar legivel para quem le a tela.
    globalThis.fetch = () => resposta('<html><body>504 Gateway Time-out</body></html>', 504);
    checar('pagina de erro nao lanca', await E.buscar('7891000100110'), null);

    try {
        await Resposta.ler({ status: 500, text: () => Promise.resolve('<h1>Erro 500</h1>') });
        checar('resposta nao-json tem de lancar', 'passou', 'devia lancar');
    } catch (e) {
        checar('o erro diz o status', e.http, 500);
        checar('e diz o que veio', e.message.includes('Erro 500'), true);
        // A rede funcionou: quem respondeu torto foi o servidor.
        checar('nao chama de falha de rede', Resposta.frase(e).startsWith('Não deu'), true);
    }
    // Corpo vazio e o sintoma classico de tempo estourado.
    try {
        await Resposta.ler({ status: 500, text: () => Promise.resolve('') });
    } catch (e) {
        checar('corpo vazio sugere o tempo', e.message.includes('estourou o tempo'), true);
    }
    // Requisicao que nem chegou continua sendo rede.
    checar('sem resposta ainda e rede',
        Resposta.frase(new Error('Load failed')).startsWith('Falha de rede'), true);

    console.log(`\n${ok} passaram, ${falhou} falharam`);
    process.exit(falhou > 0 ? 1 : 0);
})();
