/*
 * Roda o Code node "Extrair Itens do Cupom" contra um HTML sintetico que imita
 * a estrutura real da Consulta Completa (conferida contra a nota 28048):
 *
 *  - o corpo da resposta chega em "data", nao em "body";
 *  - cada item sao DUAS tabelas irmas: <table class="toggle box"> com a linha
 *    resumida e <table class="toggable box"> com os detalhes (EAN, codigo,
 *    valor unitario, desconto) numa tabela aninhada;
 *  - a aba Destinatario (id="DestRem") repete os rotulos do Emitente com valor
 *    vazio, logo depois dele;
 *  - a aba Cobranca reusa class="toggle box";
 *  - o cabecalho da lista usa <label> e class="prod-serv-header box";
 *  - entidade HTML dupla, item SEM GTIN e valores em formato brasileiro.
 */
const fs = require('fs');
const path = require('path');

const CODIGO = fs.readFileSync(
    path.join(__dirname, 'codigo', '04-extrair-itens.js'),
    'utf8'
);

/** Linha resumida + tabela irma de detalhes, como a SEFAZ manda. */
function tabelaItem({ n, desc, qtd, un, vb, codigo, ean, vu, desconto, ncm, cest, cfop, tributos }) {
    return `
    <table class="toggle box">
      <tr>
        <td class="fixo-prod-serv-numero"><span>${n}</span></td>
        <td class="fixo-prod-serv-descricao"><span>${desc}</span></td>
        <td class="fixo-prod-serv-qtd"><span>${qtd}</span></td>
        <td class="fixo-prod-serv-uc"><span>${un}</span></td>
        <td class="fixo-prod-serv-vb"><span>${vb}</span></td>
      </tr>
    </table>
    <table class="toggable box" style="background-color:#ECECEC">
      <tr>
        <td>
          <table class="box">
            <tr>
              <td colspan="4"><label>Código do Produto</label><span>${codigo}</span></td>
              <td colspan="2"><label>Código NCM</label><span>${ncm}</span></td>
              <td colspan="2"><label>Código CEST</label><span>${cest}</span></td>
            </tr>
            <tr>
              <td colspan="4"><label>CFOP</label><span>${cfop}</span></td>
              <td colspan="4"><label>Outras Despesas Acessórias</label><span>
              </span></td>
            </tr>
            <tr>
              <td colspan="4"><label>Valor do Desconto</label><span>${desconto}</span></td>
              <td colspan="4"><label>Valor Total do Frete</label><span></span></td>
            </tr>
            <tr>
              <td colspan="4"><label>Código EAN Comercial</label><span>${ean}</span></td>
              <td colspan="2"><label>Unidade Comercial</label><span>${un}</span></td>
              <td colspan="2"><label>Quantidade Comercial</label><span>${qtd}</span></td>
            </tr>
            <tr>
              <td colspan="4"><label>Código EAN Tributável</label><span>${ean}</span></td>
              <td colspan="4"><label>Valor unitário de comercialização</label><span>${vu}</span></td>
            </tr>
            <tr>
              <td colspan="4"><label>Valor Aproximado dos Tributos</label><span>${tributos}</span></td>
              <td colspan="4"><label>Origem da Mercadoria</label><span>0 - Nacional</span></td>
            </tr>
          </table>
        </td>
      </tr>
    </table>`;
}

const html = `
<html><body>
<div id="NFe">
  <label>Modelo</label><span>65</span>
  <label>Série</label><span>101</span>
  <label>Número</label><span>28048</span>
  <label>Data de Emissão</label><span>05/09/2026 19:32:11-03:00</span>
  <label>Natureza da Operação</label><span>venda</span>
</div>
<div id="Emitente">
  <label>Nome / Razão Social</label><span>HIGA PRODUTOS ALIMENTICIOS LTDA</span>
  <label>Nome Fantasia</label><span></span>
  <label>CNPJ</label><span>46.029.724/0006-73</span>
  <label>Endereço</label><span>AV. JUVENAL DE CAMPOS, 550</span>
  <label>Bairro / Distrito</label><span>JARDIM FACULDADE</span>
  <label>CEP</label><span>18030-280</span>
  <label>Município</label><span>3552205
        -
        SOROCABA</span>
  <label>UF</label><span>SP</span>
  <label>Inscrição Estadual</label><span>798552003114</span>
</div>
<div id="DestRem">
  <label>CNPJ/CPF/Id. Estrangeiro</label><span></span>
  <label>Nome / Razão Social</label><span></span>
  <label>Município</label><span></span>
  <label>UF</label><span></span>
  <label>Inscrição Estadual</label><span></span>
</div>
<div id="Prod">
  <table class="prod-serv-header box">
    <tr>
      <td class="fixo-prod-serv-numero"><label>Num.</label></td>
      <td class="fixo-prod-serv-descricao"><label>Descrição</label></td>
    </tr>
  </table>
  ${tabelaItem({ n: 1, desc: 'AZEITE D&amp;#39;ORO 500ML', qtd: '2,0000', un: 'UN',
      vb: '59,80', codigo: '7291', ean: '7891000315507', vu: '29,9000000000',
      desconto: '0,00', ncm: '15091000', cest: '1707900', cfop: '5102', tributos: '5,11' })}
  ${tabelaItem({ n: 2, desc: 'BANANA PRATA KG', qtd: '1,235', un: 'KG',
      vb: '8,63', codigo: '331', ean: 'SEM GTIN', vu: '6,9900000000',
      desconto: '1,00', ncm: '08039000', cest: '', cfop: '5102', tributos: '0,80' })}
</div>
<div id="Conteudo_pnlNFe_tabTotais"></div>
<div id="Totais">
  <label>Valor Total dos Produtos</label><span>68,43</span>
  <label>Valor Total dos Descontos</label><span>1,00</span>
  <label>Outras Despesas Acessórias</label><span>0,000</span>
  <label>Valor do Frete</label><span>0,000</span>
  <label>Valor Aproximado dos Tributos</label><span>5,91</span>
  <label>Valor Total da NFe</label><span>67,43</span>
</div>
<div id="Transporte"></div>
<div id="Cobranca">
  <table class="toggle box">
    <tr><td class="fixo-prod-serv-numero"><span>99</span></td>
        <td class="fixo-prod-serv-descricao"><span>FATURA QUE NAO E ITEM</span></td></tr>
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

// fullResponse do HTTP Request desta versao do n8n: o corpo vem em "data".
const $input = { first: () => ({ json: { data: html, statusCode: 200 } }) };
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

// O destinatario vem logo depois do emitente repetindo os mesmos rotulos
// vazios; se voltar a sobrescrever, estes quatro testes quebram.
checar('emitente', payload.nota.emitente, 'HIGA PRODUTOS ALIMENTICIOS LTDA');
checar('inscricao estadual do emitente', payload.nota.inscricao_estadual, '798552003114');
checar('cnpj so digitos', payload.nota.cnpj, '46029724000673');
checar('municipio sem o codigo IBGE', payload.nota.municipio, 'SOROCABA');
checar('codigo do municipio', payload.nota.codigo_municipio, '3552205');
checar('uf', payload.nota.uf, 'SP');
checar('endereco', payload.nota.endereco, 'AV. JUVENAL DE CAMPOS, 550');
checar('numero', payload.nota.numero_nota, '28048');
checar('serie', payload.nota.serie, '101');
checar('emissao com fuso', payload.nota.emissao, '05/09/2026 19:32:11-03:00');
checar('chave vem do QR', payload.nota.chave, entrada.chave);
checar('total produtos', payload.nota.valor_total_produtos, '68,43');
checar('desconto total', payload.nota.desconto_total_nota, '1,00');
checar('total nota', payload.nota.valor_total_nota, '67,43');

const i1 = payload.itens[0];
checar('item 1 numero', i1.item, 1);
checar('item 1 entidade dupla', i1.descricao, "AZEITE D'ORO 500ML");
checar('item 1 ean', i1.ean, '7891000315507');
checar('item 1 ean tributavel', i1.ean_tributavel, '7891000315507');
checar('item 1 qtd', i1.quantidade, '2,0000');
checar('item 1 unidade', i1.unidade, 'UN');
checar('item 1 valor unitario', i1.valor_unitario, '29,9000000000');
checar('item 1 valor total', i1.valor_total_item, '59,80');
checar('item 1 codigo interno', i1.codigo, '7291');
checar('item 1 ncm', i1.ncm, '15091000');
checar('item 1 cest', i1.cest, '1707900');
checar('item 1 cfop', i1.cfop, '5102');
checar('item 1 tributos', i1.valor_tributos, '5,11');
checar('item 1 origem', i1.origem, '0 - Nacional');

const i2 = payload.itens[1];
checar('item 2 SEM GTIN preservado', i2.ean, 'SEM GTIN');
checar('item 2 codigo interno', i2.codigo, '331');
checar('item 2 desconto por item', i2.desconto_item, '1,00');
checar('item 2 qtd fracionada', i2.quantidade, '1,235');
checar('item 2 unidade KG', i2.unidade, 'KG');
checar('item 2 valor unitario', i2.valor_unitario, '6,9900000000');

// ---- corpo em "body", como em versoes antigas do n8n ----
const saidaBody = new Function('$input', '$', CODIGO)(
    { first: () => ({ json: { body: html, statusCode: 200 } }) },
    $
);
checar('corpo em body ainda funciona', saidaBody[0].json.payload.itens.length, 2);

// ---- caminho de erro: HTML vazio tem que virar callback de erro ----
const $inputVazio = { first: () => ({ json: { data: '', statusCode: 500 } }) };
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
