// Extrai cabecalho e itens da Consulta Completa e monta o payload que o
// Mercadinho espera em POST /api/callback.
//
// Armadilhas tratadas aqui:
//  - o corpo do HTTP Request vem em "data" nesta versao do n8n (em versoes
//    antigas era "body"); ler so um dos dois devolve string vazia;
//  - cada item ocupa DUAS tabelas irmas: a linha resumida em
//    <table class="toggle box"> e os detalhes em <table class="toggable box">.
//    Casar so "toggle" traz descricao e valor, mas nunca EAN, codigo, valor
//    unitario nem desconto — era o bug que deixava esses campos vazios;
//  - a aba Destinatario repete os rotulos do Emitente ("Nome / Razao Social",
//    "Municipio", "UF"...) com valor vazio; por isso pares() nao sobrescreve
//    rotulo ja visto e a fatia do Emitente termina em id="DestRem";
//  - a aba Cobranca tambem usa class="toggle box", por isso o bloco "Prod" e
//    fatiado antes de procurar as tabelas;
//  - a linha de cabecalho da tabela usa <label>, nao <span>;
//  - descricoes vem com entidade HTML dupla (D&amp;#39;ORO -> D'ORO);
//  - valores ficam em formato brasileiro e sao repassados como texto — o PHP
//    aceita "1.234,56" e float.
const resposta = $input.first().json;
// Os dados da viagem (nota_id, token, callback_url, chave) vem sempre do
// Normalizar Entrada, nunca do node anterior. Se um node do meio for editado
// e deixar de repassar campos, o callback continua sabendo para onde voltar.
const entrada = $('Normalizar Entrada').first().json;
const html = String(
    (resposta && resposta.data) ||
    (resposta && resposta.body && resposta.body.data) ||
    (resposta && resposta.body) ||
    ''
);

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
            .replace(/&apos;/g, "'")
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

/**
 * Pares <label>Rotulo</label><span>Valor</span>.
 * O primeiro valor vence: quando a fatia alcanca o inicio da aba seguinte, os
 * rotulos repetidos (e vazios) do Destinatario nao apagam os do Emitente.
 */
function pares(bloco) {
    const mapa = {};
    const re = /<label[^>]*>([\s\S]*?)<\/label>\s*<span[^>]*>([\s\S]*?)<\/span>/gi;
    let m;
    while ((m = re.exec(bloco)) !== null) {
        const rotulo = limpar(m[1]).replace(/:$/, '');
        if (rotulo && !(rotulo in mapa)) {
            mapa[rotulo] = limpar(m[2]);
        }
    }
    return mapa;
}

function semAcento(texto) {
    return String(texto).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase();
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

/** "3552205 - SOROCABA" -> { codigo: '3552205', nome: 'SOROCABA' }. */
function municipioPartes(valor) {
    const texto = limpar(valor);
    const m = texto.match(/^(\d{5,7})\s*-\s*(.+)$/);
    return m ? { codigo: m[1], nome: m[2].trim() } : { codigo: '', nome: texto };
}

try {
    if (!html || html.length < 2000) {
        throw new Error('A Consulta Completa voltou vazia ou curta demais (' + html.length + ' bytes).');
    }

    // ---------- cabecalho ----------
    const dadosNFe = pares(fatia('NFe', 'Emitente') || fatia('NFe', null).slice(0, 12000));
    // A aba do emitente termina em id="DestRem" (a do destinatario). Sem esse
    // corte, os rotulos vazios do destinatario apagavam a razao social.
    const dadosEmit = pares(
        fatia('Emitente', 'DestRem') ||
        fatia('Emitente', 'Destinatario') ||
        fatia('Emitente', 'Prod') ||
        fatia('Emitente', null).slice(0, 12000)
    );
    const dadosTotais = pares(
        fatia('Totais', 'Transporte') ||
        fatia('Totais', 'Conteudo_pnlNFe_tabTransporte') ||
        fatia('Totais', null).slice(0, 20000)
    );

    const cnpj = (acha(dadosEmit, 'CNPJ') || acha(dadosNFe, 'CNPJ') || '').replace(/\D/g, '');
    const municipio = municipioPartes(acha(dadosEmit, 'Municipio'));

    const nota = {
        chave: entrada.chave,
        emitente: acha(dadosEmit, 'Nome / Razao Social', 'Razao Social', 'Nome')
            || acha(dadosNFe, 'Nome / Razao Social', 'Razao Social'),
        nome_fantasia: acha(dadosEmit, 'Nome Fantasia'),
        cnpj,
        inscricao_estadual: acha(dadosEmit, 'Inscricao Estadual') || acha(dadosNFe, 'Inscricao Estadual'),
        endereco: acha(dadosEmit, 'Endereco'),
        bairro: acha(dadosEmit, 'Bairro / Distrito', 'Bairro'),
        cep: acha(dadosEmit, 'CEP'),
        municipio: municipio.nome,
        codigo_municipio: municipio.codigo,
        uf: acha(dadosEmit, 'UF') || acha(dadosNFe, 'UF'),
        modelo: acha(dadosNFe, 'Modelo'),
        serie: acha(dadosNFe, 'Serie'),
        numero_nota: acha(dadosNFe, 'Numero'),
        emissao: acha(dadosNFe, 'Data de Emissao', 'Emissao'),
        natureza_operacao: acha(dadosNFe, 'Natureza da Operacao'),
        valor_total_produtos: acha(dadosTotais, 'Valor Total dos Produtos', 'Valor dos Produtos'),
        desconto_total_nota: acha(dadosTotais, 'Valor Total dos Descontos', 'Desconto'),
        outras_despesas_nota: acha(dadosTotais, 'Outras Despesas Acessorias'),
        valor_frete_nota: acha(dadosTotais, 'Valor do Frete'),
        valor_tributos_nota: acha(dadosTotais, 'Valor Aproximado dos Tributos'),
        valor_total_nota: acha(dadosTotais, 'Valor Total da NFe', 'Valor a Pagar', 'Valor Total da Nota'),
        url_consulta: entrada.qrcode,
        consultado_em: new Date().toISOString(),
    };

    // ---------- itens ----------
    // Fatiar "Prod" antes e obrigatorio: a aba Cobranca reusa a mesma classe.
    const blocoProdutos = fatia('Prod', 'Conteudo_pnlNFe_tabTotais') || fatia('Prod', 'Totais');
    if (!blocoProdutos) {
        throw new Error('Nao achei o bloco de produtos (id="Prod") na resposta.');
    }

    // Cada item comeca numa <table class="toggle box"> e vai ate a proxima:
    // assim a <table class="toggable box"> irma, que carrega EAN, codigo do
    // produto, valor unitario e desconto, entra no mesmo pedaco.
    const abertura = /<table[^>]*class="[^"]*\btoggle\b[^"]*"[^>]*>/gi;
    const inicios = [];
    let marca;
    while ((marca = abertura.exec(blocoProdutos)) !== null) {
        inicios.push(marca.index);
    }

    const itens = [];
    for (let k = 0; k < inicios.length; k++) {
        const pedaco = blocoProdutos.slice(
            inicios[k],
            inicios[k + 1] !== undefined ? inicios[k + 1] : blocoProdutos.length
        );
        // A linha resumida (numero, descricao, qtd, unidade, valor) e a
        // primeira tabela do pedaco; o resto e detalhe.
        const fim = pedaco.indexOf('</table>');
        const resumo = fim >= 0 ? pedaco.slice(0, fim) : pedaco;

        const numero = celula(resumo, 'fixo-prod-serv-numero');
        // A linha de cabecalho usa <label> no lugar de <span>: descartar.
        if (!numero || !/^\d+$/.test(numero)) {
            continue;
        }

        const detalhe = pares(pedaco);

        itens.push({
            item: Number(numero),
            codigo: acha(detalhe, 'Codigo do Produto', 'Codigo do Prod'),
            descricao: celula(resumo, 'fixo-prod-serv-descricao') || acha(detalhe, 'Descricao'),
            quantidade: celula(resumo, 'fixo-prod-serv-qtd') || acha(detalhe, 'Quantidade Comercial'),
            unidade: celula(resumo, 'fixo-prod-serv-uc') || acha(detalhe, 'Unidade Comercial'),
            valor_unitario: acha(detalhe, 'Valor unitario de comercializacao', 'Valor unitario'),
            valor_total_item: celula(resumo, 'fixo-prod-serv-vb') || acha(detalhe, 'Valor total bruto'),
            desconto_item: acha(detalhe, 'Valor do Desconto'),
            outras_despesas: acha(detalhe, 'Outras Despesas Acessorias'),
            frete_item: acha(detalhe, 'Valor Total do Frete'),
            seguro_item: acha(detalhe, 'Valor do Seguro'),
            valor_tributos: acha(detalhe, 'Valor Aproximado dos Tributos'),
            ean: acha(detalhe, 'Codigo EAN Comercial', 'GTIN', 'EAN'),
            ean_tributavel: acha(detalhe, 'Codigo EAN Tributavel'),
            ncm: acha(detalhe, 'Codigo NCM', 'NCM'),
            cest: acha(detalhe, 'Codigo CEST', 'CEST'),
            cfop: acha(detalhe, 'CFOP'),
            origem: acha(detalhe, 'Origem da Mercadoria'),
        });
    }

    if (itens.length === 0) {
        throw new Error(
            'Cheguei na Consulta Completa mas nao extrai nenhum item — ' +
            'o layout da SEFAZ pode ter mudado. Encontrei ' + inicios.length + ' tabela(s) no bloco Prod.'
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
