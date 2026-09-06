<?php
declare(strict_types=1);

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';

// notas.php so define funcoes e uma const no topo, entao carrega sem banco.
// O teste chama apenas callback_normalizar(), que nao consulta o MySQL.
require APP . '/notas.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

// Uma linha no formato achatado que o n8n manda hoje: as 27 colunas,
// cabecalho repetido em cada item.
function linha(array $extra): array
{
    return array_merge([
        'nota_id' => 7,
        'token' => 'segredo',
        'chave' => '35260946029724000673651010000280481783880108',
        'emitente' => 'HIGA PRODUTOS ALIMENTICIOS LTDA',
        'cnpj' => '46029724000673',
        'inscricao_estadual' => '111222333444',
        'municipio' => 'SAO PAULO',
        'uf' => 'SP',
        'modelo' => '65',
        'serie' => '101',
        'numero_nota' => '28048',
        'emissao' => '05/09/2026 19:32:11',
        'valor_total_produtos' => '68,43',
        'desconto_total_nota' => '1,00',
        'valor_total_nota' => '67,43',
        'url_consulta' => 'https://www.nfce.fazenda.sp.gov.br/qrcode?p=...',
        'consultado_em' => '2026-09-05T22:00:00Z',
    ], $extra);
}

$item1 = linha(['item' => 1, 'codigo' => '7291', 'descricao' => "AZEITE D'ORO 500ML",
    'quantidade' => '2,0000', 'unidade' => 'UN', 'valor_unitario' => '29,90',
    'valor_total_item' => '59,80', 'desconto_item' => '0,00',
    'ean' => '7891000315507', 'ncm' => '15091000', 'cest' => '', 'cfop' => '5102']);

$item2 = linha(['item' => 2, 'codigo' => '331', 'descricao' => 'BANANA PRATA KG',
    'quantidade' => '1,235', 'unidade' => 'KG', 'valor_unitario' => '6,99',
    'valor_total_item' => '8,63', 'desconto_item' => '1,00',
    'ean' => 'SEM GTIN', 'ncm' => '08039000', 'cest' => '', 'cfop' => '5102']);

// ---- a) achatado dentro de "itens" ----
$r = callback_normalizar(['itens' => [$item1, $item2]]);
checar('achatado: nota_id do item', $r['nota_id'], 7);
checar('achatado: token do item', $r['token'], 'segredo');
checar('achatado: status padrao', $r['status'], 'ok');
checar('achatado: 2 itens', count($r['itens']), 2);
checar('achatado: emitente no cabecalho', $r['cab']['emitente'], 'HIGA PRODUTOS ALIMENTICIOS LTDA');
checar('achatado: cnpj', $r['cab']['cnpj'], '46029724000673');
checar('achatado: municipio', $r['cab']['municipio'], 'SAO PAULO');
checar('achatado: total da nota', $r['cab']['valor_total_nota'], '67,43');
checar('achatado: chave', $r['cab']['chave'], '35260946029724000673651010000280481783880108');

// ---- b) lista crua na raiz do corpo ----
$r = callback_normalizar([$item1, $item2]);
checar('lista crua: 2 itens', count($r['itens']), 2);
checar('lista crua: emitente', $r['cab']['emitente'], 'HIGA PRODUTOS ALIMENTICIOS LTDA');
checar('lista crua: nota_id', $r['nota_id'], 7);

// ---- c) embrulhado em "body" (n8n as vezes manda assim) ----
$r = callback_normalizar(['body' => ['dados' => [$item1]]]);
checar('body+dados: 1 item', count($r['itens']), 1);
checar('body+dados: emitente', $r['cab']['emitente'], 'HIGA PRODUTOS ALIMENTICIOS LTDA');

// ---- d) formato aninhado antigo continua valendo ----
$r = callback_normalizar([
    'nota_id' => 9, 'token' => 'abc', 'status' => 'ok',
    'nota' => ['emitente' => 'MERCADO X', 'cnpj' => '11222333000181'],
    'itens' => [['descricao' => 'ARROZ', 'valor_total_item' => '20,00']],
]);
checar('aninhado: nota_id', $r['nota_id'], 9);
checar('aninhado: emitente', $r['cab']['emitente'], 'MERCADO X');
checar('aninhado: 1 item', count($r['itens']), 1);

// ---- e) erro ----
$r = callback_normalizar(['nota_id' => 3, 'token' => 't', 'status' => 'erro', 'erro' => 'captcha']);
checar('erro: status', $r['status'], 'erro');
checar('erro: mensagem', $r['erro'], 'captcha');
checar('erro: sem itens', $r['itens'], []);

// ---- f) payload que o n8n manda hoje: aninhado, com EAN e desconto por item ----
$novo = callback_normalizar([
    'nota_id' => 12,
    'token' => 'segredo',
    'status' => 'ok',
    'nota' => [
        'chave' => '35260946029724000673651010000280481783880108',
        'emitente' => 'HIGA PRODUTOS ALIMENTICIOS LTDA',
        'cnpj' => '46029724000673',
        'inscricao_estadual' => '798552003114',
        'municipio' => 'SOROCABA',
        'codigo_municipio' => '3552205',
        'uf' => 'SP',
        'modelo' => '65', 'serie' => '101', 'numero_nota' => '28048',
        'emissao' => '04/09/2026 22:26:38-03:00',
        'valor_total_produtos' => '1.595,140',
        'desconto_total_nota' => '62,040',
        'valor_total_nota' => '1.533,100',
    ],
    'itens' => [[
        'item' => 1, 'codigo' => '359894',
        'descricao' => 'SAND FAROESTE BURGER C CARAM 145G',
        'quantidade' => '3,0000', 'unidade' => 'UN',
        'valor_unitario' => '6,9800000000', 'valor_total_item' => '20,940',
        'desconto_item' => '0,870', 'ean' => '7891164026974',
        'ncm' => '16029000', 'cest' => '1707900', 'cfop' => '5405',
        'valor_tributos' => '5,110', 'origem' => '0 - Nacional',
    ]],
]);
checar('n8n atual: nota_id', $novo['nota_id'], 12);
checar('n8n atual: emitente', $novo['cab']['emitente'], 'HIGA PRODUTOS ALIMENTICIOS LTDA');
checar('n8n atual: municipio sem codigo IBGE', $novo['cab']['municipio'], 'SOROCABA');
checar('n8n atual: emissao com fuso', data_mysql($novo['cab']['emissao']), '2026-09-04 22:26:38');
checar('n8n atual: ean do item', $novo['itens'][0]['ean'], '7891164026974');
checar('n8n atual: ean normalizado', ean_normalizado($novo['itens'][0]['ean']), '7891164026974');
checar('n8n atual: codigo interno', $novo['itens'][0]['codigo'], '359894');
checar('n8n atual: desconto do item', num_br($novo['itens'][0]['desconto_item']), 0.87);
checar('n8n atual: valor unitario longo', num_br($novo['itens'][0]['valor_unitario']), 6.98);
checar(
    'n8n atual: liquido do item',
    round(num_br($novo['itens'][0]['valor_total_item']) - num_br($novo['itens'][0]['desconto_item']), 2),
    20.07
);
checar('n8n atual: campo extra nao atrapalha', $novo['itens'][0]['valor_tributos'], '5,110');

// ---- g) calculo do liquido, item a item ----
foreach ([
    ['bruto' => '59,80', 'desc' => '0,00', 'qtd' => '2,0000', 'liq' => 59.80, 'unit' => 29.90],
    ['bruto' => '8,63',  'desc' => '1,00', 'qtd' => '1,235',  'liq' => 7.63,  'unit' => 6.1781],
    ['bruto' => '10,00', 'desc' => '2,50', 'qtd' => '1',      'liq' => 7.50,  'unit' => 7.50],
] as $c) {
    $bruto = num_br($c['bruto']);
    $desc = num_br($c['desc']);
    $qtd = num_br($c['qtd']);
    $liquido = round(max(0, $bruto - $desc), 2);
    checar("liquido de {$c['bruto']} - {$c['desc']}", $liquido, $c['liq']);
    checar("unitario liquido ({$c['qtd']})", round($liquido / $qtd, 4), $c['unit']);
}

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
