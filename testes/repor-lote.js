/*
 * A fila do Repor em lote: quem entra em cada fase e como a fila trata erro.
 *
 *   node testes/repor-lote.js
 */
const R = require('../assets/repor-lote.js');

let ok = 0, falhou = 0;
function checar(nome, obtido, esperado) {
    const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
    if (a === b) { ok++; return; }
    falhou++;
    console.log(`FALHOU  ${nome}\n   esperado: ${b}\n   obtido:   ${a}`);
}

const L = (linha, status) => ({ linha, status, codigo: '789' + linha, descricao: 'P' + linha });
const lote = [L(2, 'pendente'), L(3, 'planograma'), L(4, 'cadastro'), L(5, 'gravada'),
              L(6, 'sem_cadastro'), L(7, 'pendencia'), L(8, 'erro'), L(9, 'sem_mudanca')];
const nums = (ls) => ls.map((l) => l.linha);

checar('conferir: so as pendentes', nums(R.fila(lote, 'conferir', false)), [2]);
checar('conferir com erros: pendentes e erro', nums(R.fila(lote, 'conferir', true)), [2, 8]);
checar('gravar: so as prontas, nunca a gravada', nums(R.fila(lote, 'gravar', false)), [3, 4]);
checar('gravar de novo: prontas e as com erro', nums(R.fila(lote, 'gravar', true)), [3, 4, 8]);
checar('reconferir pula so a gravada', nums(R.filaReconferir(lote)), [2, 3, 4, 6, 7, 8, 9]);

const r = R.resumo(lote);
checar('resumo: total', r.total, 8);
checar('resumo: a gravar = planograma + cadastro', r.aGravar, 2);
checar('resumo: conferidas', r.conferidas, 7);

checar('texto dos sem cadastro', R.textoSemCadastro(lote), 'Produtos para cadastrar no TouchPay:\n- 7896 · P6');
checar('sem cadastro vazio', R.textoSemCadastro([L(1, 'gravada')]), '');
checar('progresso da conferencia', R.textoProgresso(lote, 'conferir'),
    '7 de 8 conferidas · 1 sem cadastro · 1 sem preço · 1 com erro');

(async () => {
    // roda em sequencia, uma por vez
    const ordem = [];
    let emVoo = 0, maxVoo = 0;
    const passo = async (l) => {
        emVoo++; maxVoo = Math.max(maxVoo, emVoo);
        await new Promise((res) => setTimeout(res, 5));
        ordem.push(l.linha); emVoo--;
        return { ...l, status: 'gravada' };
    };
    const vistas = [];
    const fim = await R.rodar([L(3, 'planograma'), L(4, 'cadastro')], passo, (n) => vistas.push(n.status));
    checar('fila: ordem da planilha', ordem, [3, 4]);
    checar('fila: nunca duas ao mesmo tempo', maxVoo, 1);
    checar('fila: avisa cada linha', vistas, ['gravada', 'gravada']);
    checar('fila: terminou sem parar', fim.parou, false);

    // erro de rede para a fila: gravar pode ter escrito e a resposta se perdeu
    const rodadas = [];
    const falha = await R.rodar([L(3, 'planograma'), L(4, 'cadastro'), L(5, 'cadastro')],
        async (l) => { rodadas.push(l.linha); if (l.linha === 4) throw new Error('sem resposta'); return l; },
        () => {});
    checar('fila: erro de rede para tudo', rodadas, [3, 4]);
    checar('fila: motivo repassado', [falha.parou, falha.motivo], [true, 'sem resposta']);

    // cancelar entre linhas
    let corte = false;
    const feitas = [];
    const cancel = await R.rodar([L(3, 'planograma'), L(4, 'cadastro')],
        async (l) => { feitas.push(l.linha); corte = true; return l; }, () => {}, () => corte);
    checar('fila: cancelar so entre linhas', [feitas, cancel.parou], [[3], true]);

    console.log(`\n${ok} passaram, ${falhou} falharam`);
    process.exit(falhou ? 1 : 0);
})();
