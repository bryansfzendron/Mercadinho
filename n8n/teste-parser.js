/*
 * Roda o Code node "Extrair Itens do Cupom" contra um HTML sintetico que imita
 * a estrutura da Consulta Completa: bloco Prod com linha de cabecalho em
 * <label>, aba Cobranca reusando class="toggle box", entidade HTML dupla,
 * item SEM GTIN e valores em formato brasileiro.
 */
const fs = require('fs');
const path = require('path');

const CODIGO = fs.readFileSync(
    path.join(__dirname, 'codigo', '04-extrair-itens.js'),
    'utf8'
);

function tabelaItem({ n, desc, qtd, un, vb, codigo, ean, vu, desconto, ncm, cfop }) {
    return `
    <table class="toggle box">
      <tr>
        <td class="fixo-prod-serv-numero">${n}</td>
        <td class="fixo-prod-serv-descricao"><span>${desc}</span></td>
        <td class="fixo-prod-serv-qtd">${qtd}</td>
        <td class="fixo-prod-serv-uc">${un}</td>
        <td class="fixo-prod-serv-vb">${vb}</td>
      </tr>
      <tr><td>
        <label>Código do Produto</label><span>${codigo}</span>
        <label>Código EAN Comercial</label><span>${ean}</span>
        <label>Valor unitário de comercialização</label><span>${vu}</span>
        <label>Valor do Desconto</label><span>${desconto}</span>
        <label>Código NCM</label><span>${ncm}</span>
        <label>CFOP</label><span>${cfop}</span>
      </td></tr>
    </table>`;
}

const html = `
<html><body>
<div id="NFe">
  <label>Modelo</label><span>65</span>
  <label>Série</label><span>101</span>
  <label>Número</label><span>28048</span>
  <label>Data de Emissão</label><span>05/09/2026 19:32:11</span>
</div>
<div id="Emitente">
  <label>Nome / Razão Social</label><span>HIGA PRODUTOS ALIMENTICIOS LTDA</span>
  <label>CNPJ</label><span>46.029.724/0006-73</span>
  <label>Inscrição Estadual</label><span>111222333444</span>
  <label>Município</label><span>SAO PAULO</span>
  <label>UF</label><span>SP</span>
</div>
<div id="Prod">
  <table class="toggle box">
    <tr>
      <td class="fixo-prod-serv-numero"><label>Num.</label></td>
      <td class="fixo-prod-serv-descricao"><label>Descrição</label></td>
    </tr>
  </table>
  ${tabelaItem({ n: 1, desc: 'AZEITE D&amp;#39;ORO 500ML', qtd: '2,0000', un: 'UN',
      vb: '59,80', codigo: '7291', ean: '7891000315507', vu: '29,90',
      desconto: '0,00', ncm: '15091000', cfop: '5102' })}
  ${tabelaItem({ n: 2, desc: 'BANANA PRATA KG', qtd: '1,235', un: 'KG',
      vb: '8,63', codigo: '331', ean: 'SEM GTIN', vu: '6,99',
      desconto: '1,00', ncm: '08039000', cfop: '5102' })}
</div>
<div id="Conteudo_pnlNFe_tabTotais"></div>
<div id="Totais">
  <label>Valor Total dos Produtos</label><span>68,43</span>
  <label>Desconto</label><span>1,00</span>
  <label>Valor Total da NFe</label><span>67,43</span>
</div>
<div id="Transporte"></div>
<div id="Cobranca">
  <table class="toggle box">
    <tr><td class="fixo-prod-serv-numero">99</td>
        <td class="fixo-prod-serv-descricao">FATURA QUE NAO E ITEM</td></tr>
  </table>
</div>
</body></html>`.padEnd(2500, ' ');

const entrada = {
    nota_id: 42,
    token: 'segredo',
    callback_url: 'https://mercadinho.bryanzendron.com.br/api/callback',
    guardar_html: false,
    chave: '35260946029724000673651010000280481783880108',
    qrcode: 'https://www.nfce.fazenda.sp.gov.br/qrcode?p=...',
};

const $input = { first: () => ({ json: { body: html, statusCode: 200 } }) };
const $ = (nome) => ({ first: () => ({ json: entrada }) });

const executar = new Function('$input', '$', CODIGO);
const saida = executar($input, $);
const payload = saida[0].json.payload;

let falhas = 0;
function checar(nome, obtido, esperado) {
    const a = JSON.stringify(obtido);
    const b = JSON.stringify(esperado);
    if (a === b) return;
    falhas++;
    console.log(`FALHOU  ${nome}\n   esperado: ${b}\n   obtido:   ${a}`);
}

checar('status', payload.status, 'ok');
checar('erro ausente', payload.erro, undefined);
checar('quantidade de itens (Cobranca fora, cabecalho fora)', payload.itens.length, 2);

checar('emitente', payload.nota.emitente, 'HIGA PRODUTOS ALIMENTICIOS LTDA');
checar('cnpj so digitos', payload.nota.cnpj, '46029724000673');
checar('municipio', payload.nota.municipio, 'SAO PAULO');
checar('uf', payload.nota.uf, 'SP');
checar('numero', payload.nota.numero_nota, '28048');
checar('serie', payload.nota.serie, '101');
checar('emissao', payload.nota.emissao, '05/09/2026 19:32:11');
checar('chave vem do QR', payload.nota.chave, entrada.chave);
checar('total produtos', payload.nota.valor_total_produtos, '68,43');
checar('total nota', payload.nota.valor_total_nota, '67,43');

const i1 = payload.itens[0];
checar('item 1 numero', i1.item, 1);
checar('item 1 entidade dupla', i1.descricao, "AZEITE D'ORO 500ML");
checar('item 1 ean', i1.ean, '7891000315507');
checar('item 1 qtd', i1.quantidade, '2,0000');
checar('item 1 unidade', i1.unidade, 'UN');
checar('item 1 valor unitario', i1.valor_unitario, '29,90');
checar('item 1 valor total', i1.valor_total_item, '59,80');
checar('item 1 codigo interno', i1.codigo, '7291');
checar('item 1 ncm', i1.ncm, '15091000');
checar('item 1 cfop', i1.cfop, '5102');

const i2 = payload.itens[1];
checar('item 2 SEM GTIN preservado', i2.ean, 'SEM GTIN');
checar('item 2 desconto por item', i2.desconto_item, '1,00');
checar('item 2 qtd fracionada', i2.quantidade, '1,235');
checar('item 2 unidade KG', i2.unidade, 'KG');

// ---- caminho de erro: HTML vazio tem que virar callback de erro ----
const $inputVazio = { first: () => ({ json: { body: '', statusCode: 500 } }) };
const saidaErro = new Function('$input', '$', CODIGO)($inputVazio, $);
checar('erro: status', saidaErro[0].json.payload.status, 'erro');
checar('erro: nota_id preservado', saidaErro[0].json.payload.nota_id, 42);
checar('erro: callback_url preservado', saidaErro[0].json.callback_url, entrada.callback_url);
if (!saidaErro[0].json.payload.erro) {
    falhas++;
    console.log('FALHOU  erro: mensagem ausente');
}

console.log(falhas === 0 ? '\nTodos os testes do parser passaram.' : `\n${falhas} falha(s).`);
process.exit(falhas ? 1 : 0);
