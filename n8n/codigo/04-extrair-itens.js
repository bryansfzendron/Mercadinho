// Extrai cabecalho e itens da Consulta Completa e monta o payload que o
// Mercadinho espera em POST /api/callback.
//
// Armadilhas tratadas aqui:
//  - a aba Cobranca tambem usa <table class="toggle box">, por isso o bloco
//    "Prod" e fatiado antes de procurar as tabelas;
//  - a linha de cabecalho da tabela usa <label>, nao <span>;
//  - descricoes vem com entidade HTML dupla (D&amp;#39;ORO -> D'ORO);
//  - valores ficam em formato brasileiro e sao repassados como texto — o PHP
//    aceita "1.234,56" e float.
const resposta = $input.first().json;
// Os dados da viagem (nota_id, token, callback_url, chave) vem sempre do
// Normalizar Entrada, nunca do node anterior. Se um node do meio for editado
// e deixar de repassar campos, o callback continua sabendo para onde voltar.
const entrada = $('Normalizar Entrada').first().json;
const html = String(resposta.body || '');

const base = {
    nota_id: entrada.nota_id,
    token: entrada.token,
};

function decodificar(valor) {
    let texto = String(valor == null ? '' : valor);
    for (let i = 0; i < 3; i++) {
        const novo = texto
            .replace(/&#(\d+);/g, (_, d) => String.fromCharCode(Number(d)))
            .replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCharCode(parseInt(h, 16)))
            .replace(/&quot;/g, '"')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&nbsp;/g, ' ')
            .replace(/&amp;/g, '&');
        if (novo === texto) {
            break;
        }
        texto = novo;
    }
    return texto;
}

function limpar(trecho) {
    return decodificar(String(trecho).replace(/<[^>]*>/g, ' ')).replace(/\s+/g, ' ').trim();
}

/**
 * Fatia o HTML entre dois id=, para nao misturar as abas.
 * Com idFim ausente no documento devolve vazio de proposito, para que os
 * fallbacks do tipo `fatia(a, b) || fatia(a, null)` funcionem.
 */
function fatia(idInicio, idFim) {
    const i = html.indexOf('id="' + idInicio + '"');
    if (i < 0) {
        return '';
    }
    if (!idFim) {
        return html.slice(i);
    }
    const j = html.indexOf('id="' + idFim + '"', i);
    return j > i ? html.slice(i, j) : '';
}

/** Pares <label>Rotulo</label><span>Valor</span>. */
function pares(bloco) {
    const mapa = {};
    const re = /<label[^>]*>([\s\S]*?)<\/label>\s*<span[^>]*>([\s\S]*?)<\/span>/gi;
    let m;
    while ((m = re.exec(bloco)) !== null) {
        const rotulo = limpar(m[1]).replace(/:$/, '');
        if (rotulo) {
            mapa[rotulo] = limpar(m[2]);
        }
    }
    return mapa;
}

function semAcento(texto) {
    return String(texto).normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
}

/** Busca tolerante: casa por trecho do rotulo, sem acento e sem caixa. */
function acha(mapa, ...candidatos) {
    const chaves = Object.keys(mapa);
    for (const candidato of candidatos) {
        const alvo = semAcento(candidato);
        for (const chave of chaves) {
            if (semAcento(chave) === alvo) {
                return mapa[chave];
            }
        }
    }
    for (const candidato of candidatos) {
        const alvo = semAcento(candidato);
        for (const chave of chaves) {
            if (semAcento(chave).includes(alvo)) {
                return mapa[chave];
            }
        }
    }
    return '';
}

function celula(tabela, classe) {
    const re = new RegExp('<t[dh][^>]*class="[^"]*' + classe + '[^"]*"[^>]*>([\\s\\S]*?)<\\/t[dh]>', 'i');
    const m = tabela.match(re);
    return m ? limpar(m[1]) : '';
}

try {
    if (!html || html.length < 2000) {
        throw new Error('A Consulta Completa voltou vazia ou curta demais (' + html.length + ' bytes).');
    }

    // ---------- cabecalho ----------
    const dadosNFe = pares(fatia('NFe', 'Emitente') || fatia('NFe', null).slice(0, 8000));
    const dadosEmit = pares(
        fatia('Emitente', 'Destinatario') ||
        fatia('Emitente', 'Prod') ||
        fatia('Emitente', null).slice(0, 8000)
    );
    const dadosTotais = pares(
        fatia('Totais', 'Transporte') || fatia('Totais', null).slice(0, 8000)
    );

    const cnpj = (acha(dadosEmit, 'CNPJ') || '').replace(/\D/g, '');

    const nota = {
        chave: entrada.chave,
        emitente: acha(dadosEmit, 'Nome / Razao Social', 'Razao Social', 'Nome'),
        cnpj,
        inscricao_estadual: acha(dadosEmit, 'Inscricao Estadual'),
        municipio: acha(dadosEmit, 'Municipio'),
        uf: acha(dadosEmit, 'UF'),
        modelo: acha(dadosNFe, 'Modelo'),
        serie: acha(dadosNFe, 'Serie'),
        numero_nota: acha(dadosNFe, 'Numero'),
        emissao: acha(dadosNFe, 'Data de Emissao', 'Emissao'),
        valor_total_produtos: acha(dadosTotais, 'Valor Total dos Produtos', 'Valor dos Produtos'),
        desconto_total_nota: acha(dadosTotais, 'Desconto'),
        valor_total_nota: acha(dadosTotais, 'Valor Total da NFe', 'Valor a Pagar', 'Valor Total'),
        url_consulta: entrada.qrcode,
        consultado_em: new Date().toISOString(),
    };

    // ---------- itens ----------
    // Fatiar "Prod" antes e obrigatorio: a aba Cobranca reusa a mesma classe.
    const blocoProdutos = fatia('Prod', 'Conteudo_pnlNFe_tabTotais');
    if (!blocoProdutos) {
        throw new Error('Nao achei o bloco de produtos (id="Prod") na resposta.');
    }

    const tabelas = blocoProdutos.match(/<table[^>]*class="[^"]*toggle[^"]*box[^"]*"[\s\S]*?<\/table>/gi) || [];
    const itens = [];

    for (const tabela of tabelas) {
        const numero = celula(tabela, 'fixo-prod-serv-numero');
        // A linha de cabecalho usa <label> no lugar de <span>: descartar.
        if (!numero || !/^\d+$/.test(numero)) {
            continue;
        }

        const detalhe = pares(tabela);
        const eanBruto = acha(detalhe, 'Codigo EAN Comercial', 'GTIN', 'EAN');

        itens.push({
            item: Number(numero),
            codigo: acha(detalhe, 'Codigo do Produto', 'Codigo'),
            descricao: celula(tabela, 'fixo-prod-serv-descricao'),
            quantidade: celula(tabela, 'fixo-prod-serv-qtd') || acha(detalhe, 'Quantidade Comercial'),
            unidade: celula(tabela, 'fixo-prod-serv-uc') || acha(detalhe, 'Unidade Comercial'),
            valor_unitario: acha(detalhe, 'Valor unitario de comercializacao', 'Valor unitario'),
            valor_total_item: celula(tabela, 'fixo-prod-serv-vb') || acha(detalhe, 'Valor total bruto'),
            desconto_item: acha(detalhe, 'Valor do Desconto'),
            ean: eanBruto,
            ncm: acha(detalhe, 'Codigo NCM', 'NCM'),
            cest: acha(detalhe, 'Codigo CEST', 'CEST'),
            cfop: acha(detalhe, 'CFOP'),
        });
    }

    if (itens.length === 0) {
        throw new Error(
            'Cheguei na Consulta Completa mas nao extrai nenhum item — ' +
            'o layout da SEFAZ pode ter mudado. Encontrei ' + tabelas.length + ' tabela(s) no bloco Prod.'
        );
    }

    const payload = { ...base, status: 'ok', nota, itens };
    if (entrada.guardar_html) {
        payload.html = html;
    }

    return [{ json: { callback_url: entrada.callback_url, payload } }];
} catch (e) {
    // Qualquer falha vira um callback de erro, para a nota nao ficar
    // eternamente "processando" no Mercadinho.
    return [{
        json: {
            callback_url: entrada.callback_url,
            payload: { ...base, status: 'erro', erro: String(e.message || e) },
        },
    }];
}
