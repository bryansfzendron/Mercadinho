<?php
declare(strict_types=1);

/*
 * Testa o parser da NFC-e em PHP (app/sefaz.php), o porte do Code node
 * n8n/codigo/04-extrair-itens.js. O HTML sintetico e o mesmo do
 * n8n/teste-parser.js: se mudar la, mude aqui. Nada aqui toca o MySQL nem a rede.
 *
 *   php testes/sefaz.php                 # so os testes
 *   php testes/sefaz.php consulta.html   # e mostra o que sai de um HTML salvo
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/sefaz.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

/** A mensagem da excecao, ou null se a funcao nao lancou. */
function erro_de(callable $f): ?string
{
    try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); }
    return null;
}

/** Linha resumida + tabela irma de detalhes, como a SEFAZ manda. */
function tabela_item(array $i): string
{
    return <<<HTML
    <table class="toggle box">
      <tr>
        <td class="fixo-prod-serv-numero"><span>{$i['n']}</span></td>
        <td class="fixo-prod-serv-descricao"><span>{$i['desc']}</span></td>
        <td class="fixo-prod-serv-qtd"><span>{$i['qtd']}</span></td>
        <td class="fixo-prod-serv-uc"><span>{$i['un']}</span></td>
        <td class="fixo-prod-serv-vb"><span>{$i['vb']}</span></td>
      </tr>
    </table>
    <table class="toggable box" style="background-color:#ECECEC">
      <tr>
        <td>
          <table class="box">
            <tr>
              <td colspan="4"><label>Código do Produto</label><span>{$i['codigo']}</span></td>
              <td colspan="2"><label>Código NCM</label><span>{$i['ncm']}</span></td>
              <td colspan="2"><label>Código CEST</label><span>{$i['cest']}</span></td>
            </tr>
            <tr>
              <td colspan="4"><label>CFOP</label><span>{$i['cfop']}</span></td>
              <td colspan="4"><label>Outras Despesas Acessórias</label><span>
              </span></td>
            </tr>
            <tr>
              <td colspan="4"><label>Valor do Desconto</label><span>{$i['desconto']}</span></td>
              <td colspan="4"><label>Valor Total do Frete</label><span></span></td>
            </tr>
            <tr>
              <td colspan="4"><label>Código EAN Comercial</label><span>{$i['ean']}</span></td>
              <td colspan="2"><label>Unidade Comercial</label><span>{$i['un']}</span></td>
              <td colspan="2"><label>Quantidade Comercial</label><span>{$i['qtd']}</span></td>
            </tr>
            <tr>
              <td colspan="4"><label>Código EAN Tributável</label><span>{$i['ean']}</span></td>
              <td colspan="4"><label>Valor unitário de comercialização</label><span>{$i['vu']}</span></td>
            </tr>
            <tr>
              <td colspan="4"><label>Valor Aproximado dos Tributos</label><span>{$i['tributos']}</span></td>
              <td colspan="4"><label>Origem da Mercadoria</label><span>0 - Nacional</span></td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
    HTML;
}

$item1 = tabela_item(['n' => 1, 'desc' => 'AZEITE D&amp;#39;ORO 500ML', 'qtd' => '2,0000', 'un' => 'UN',
    'vb' => '59,80', 'codigo' => '7291', 'ean' => '7891000315507', 'vu' => '29,9000000000',
    'desconto' => '0,00', 'ncm' => '15091000', 'cest' => '1707900', 'cfop' => '5102', 'tributos' => '5,11']);
$item2 = tabela_item(['n' => 2, 'desc' => 'BANANA PRATA KG', 'qtd' => '1,235', 'un' => 'KG',
    'vb' => '8,63', 'codigo' => '331', 'ean' => 'SEM GTIN', 'vu' => '6,9900000000',
    'desconto' => '1,00', 'ncm' => '08039000', 'cest' => '', 'cfop' => '5102', 'tributos' => '0,80']);

$html = str_pad(<<<HTML
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
  {$item1}
  {$item2}
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
</body></html>
HTML, 2500);

$chave = '35260946029724000673651010000280481783880108';
$d = sefaz_extrair($html, $chave, 'https://www.nfce.fazenda.sp.gov.br/qrcode?p=...');
$nota = $d['nota'];

// ------------------------------------------------------------- cabecalho
checar('quantidade de itens (Cobranca fora, cabecalho fora)', count($d['itens']), 2);
// O destinatario vem logo depois do emitente repetindo os mesmos rotulos
// vazios; se voltar a sobrescrever, estes quatro testes quebram.
checar('emitente', $nota['emitente'], 'HIGA PRODUTOS ALIMENTICIOS LTDA');
checar('inscricao estadual do emitente', $nota['inscricao_estadual'], '798552003114');
checar('cnpj so digitos', $nota['cnpj'], '46029724000673');
checar('municipio sem o codigo IBGE', $nota['municipio'], 'SOROCABA');
checar('codigo do municipio', $nota['codigo_municipio'], '3552205');
checar('uf', $nota['uf'], 'SP');
checar('endereco', $nota['endereco'], 'AV. JUVENAL DE CAMPOS, 550');
checar('numero', $nota['numero_nota'], '28048');
checar('serie', $nota['serie'], '101');
checar('emissao com fuso', $nota['emissao'], '05/09/2026 19:32:11-03:00');
checar('chave vem do QR', $nota['chave'], $chave);
checar('total produtos', $nota['valor_total_produtos'], '68,43');
checar('desconto total', $nota['desconto_total_nota'], '1,00');
checar('total nota', $nota['valor_total_nota'], '67,43');

// ------------------------------------------------------------- itens
$i1 = $d['itens'][0];
checar('item 1 numero', $i1['item'], 1);
checar('item 1 entidade dupla', $i1['descricao'], "AZEITE D'ORO 500ML");
checar('item 1 ean', $i1['ean'], '7891000315507');
checar('item 1 ean tributavel', $i1['ean_tributavel'], '7891000315507');
checar('item 1 qtd', $i1['quantidade'], '2,0000');
checar('item 1 unidade', $i1['unidade'], 'UN');
checar('item 1 valor unitario', $i1['valor_unitario'], '29,9000000000');
checar('item 1 valor total', $i1['valor_total_item'], '59,80');
checar('item 1 codigo interno', $i1['codigo'], '7291');
checar('item 1 ncm', $i1['ncm'], '15091000');
checar('item 1 cest', $i1['cest'], '1707900');
checar('item 1 cfop', $i1['cfop'], '5102');
checar('item 1 tributos', $i1['valor_tributos'], '5,11');
checar('item 1 origem', $i1['origem'], '0 - Nacional');

$i2 = $d['itens'][1];
checar('item 2 SEM GTIN preservado', $i2['ean'], 'SEM GTIN');
checar('item 2 codigo interno', $i2['codigo'], '331');
checar('item 2 desconto por item', $i2['desconto_item'], '1,00');
checar('item 2 qtd fracionada', $i2['quantidade'], '1,235');
checar('item 2 unidade KG', $i2['unidade'], 'KG');
checar('item 2 valor unitario', $i2['valor_unitario'], '6,9900000000');

// O payload passa pelo mesmo callback_normalizar() do n8n; o formato
// aninhado tem que sair com cabecalho e itens no lugar.
checar('chaves do payload', array_keys($d), ['nota', 'itens']);

// ------------------------------------------------------------- erros
checar('HTML curto vira erro',
    erro_de(fn () => sefaz_extrair('', $chave, 'q')),
    'A Consulta Completa voltou vazia ou curta demais (0 bytes).');
checar('sem bloco Prod vira erro',
    erro_de(fn () => sefaz_extrair(str_pad('<html><div id="NFe"></div></html>', 2500), $chave, 'q')),
    'Não achei o bloco de produtos (id="Prod") na resposta.');
$recusa = '<html><head><title>Request Rejected</title></head><body>The requested URL was rejected. '
        . 'Please consult with your administrator.<br><br>Your support ID is: <10138580085650065904><br></body></html>';
checar('Request Rejected com support ID',
    erro_de(fn () => sefaz_checar_recusa($recusa, 'a Consulta Completa')),
    'O firewall da SEFAZ recusou a Consulta Completa (Request Rejected, support ID 10138580085650065904).');
checar('pagina boa nao e recusa', erro_de(fn () => sefaz_checar_recusa($html, 'x')), null);

// ------------------------------------------------------------- pecas soltas
checar('entidade dupla', sefaz_decodificar('D&amp;#39;ORO'), "D'ORO");
// mb_chr(48) e "0": um `?:` no lugar do === false apagaria o digito.
checar('&#48; vira zero, nao some', sefaz_decodificar('A&#48;B'), 'A0B');
checar('&nbsp; vira espaco', sefaz_limpar('<b>A&nbsp;&nbsp;B</b>'), 'A B');
checar('campo oculto decodifica &amp;',
    sefaz_campo_oculto('<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="a&amp;b+/=" />', '__VIEWSTATE'),
    'a&b+/=');
checar('campo oculto ausente', sefaz_campo_oculto('<input name="x" value="1">', '__VIEWSTATE'), '');
checar('rotulo igual vence rotulo que contem',
    sefaz_acha(['Valor Total dos Produtos' => '1', 'Valor' => '2'], 'Valor'), '2');
checar('busca sem acento e sem caixa',
    sefaz_acha(['Nome / Razão Social' => 'X'], 'nome / razao social'), 'X');
checar('municipio sem codigo fica inteiro', sefaz_municipio('SOROCABA'), ['codigo' => '', 'nome' => 'SOROCABA']);

// O servidor nao pode virar um navegador que abre qualquer URL do campo do QR.
checar('URL da SEFAZ passa e o | vira %7C',
    sefaz_url_valida('https://www.nfce.fazenda.sp.gov.br/qrcode?p=123|3|1'),
    'https://www.nfce.fazenda.sp.gov.br/qrcode?p=123%7C3%7C1');
foreach ([
    'outro host com a SEFAZ na query' => 'https://evil.example.com/?x=nfce.fazenda.sp.gov.br',
    'host que so comeca igual'        => 'https://www.nfce.fazenda.sp.gov.br.evil.com/qrcode?p=1',
    'arquivo local'                   => 'file:///etc/passwd',
    'texto solto'                     => '35260946029724000673651010000280481783880108',
] as $nome => $url) {
    checar('recusa ' . $nome, erro_de(fn () => sefaz_url_valida($url)), 'O QR Code não aponta para a SEFAZ de São Paulo.');
}

// ------------------------------------------------- HTML salvo (opcional)
if (isset($argv[1])) {
    $real = sefaz_extrair((string) file_get_contents($argv[1]), '', $argv[1]);
    printf("\n%s: %s, %d itens, total %s\n", basename($argv[1]),
        $real['nota']['emitente'], count($real['itens']), $real['nota']['valor_total_nota']);
}

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
