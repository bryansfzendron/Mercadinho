<?php
declare(strict_types=1);

/*
 * Repor itens da nota: o cruzamento da nota com o planograma, as contas de
 * margem e variacao e a conversao do formulario nas linhas do Repor em lote.
 * Nada sai do processo — nem TouchPay, nem banco.
 *
 *   php testes/repor_nota.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/mercado.php';
require APP . '/touchpay.php';
require APP . '/planograma.php';
require APP . '/planilha.php';
require APP . '/planilha_repor.php';
require APP . '/repor_nota.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

// ---------- contas ----------
checar('margem: (10 - 6) / 10', repor_nota_margem(10.0, 6.0), 40.0);
checar('margem sem custo', repor_nota_margem(10.0, null), null);
checar('margem com preco zero', repor_nota_margem(0.0, 5.0), null);
checar('variacao: de 20 para 21,6', repor_nota_variacao(20.0, 21.6), 8.0);
checar('variacao: queda', repor_nota_variacao(20.0, 18.0), -10.0);
checar('variacao sem preco atual', repor_nota_variacao(null, 18.0), null);

// ---------- o cruzamento ----------
$nota = [
    ['id' => 11, 'item_num' => 1, 'descricao_original' => 'ARROZ 5KG', 'quantidade' => '12.0000', 'unidade' => 'UN',
     'valor_unitario_liquido' => '1.8000', 'valor_unitario' => '2.0000', 'desconto' => '2.40',
     'produto_ean' => '7891000100103', 'ean_original' => ''],
    ['id' => 12, 'item_num' => 2, 'descricao_original' => 'FEIJAO 1KG', 'quantidade' => '6.0000', 'unidade' => 'UN',
     'valor_unitario_liquido' => '5.0000', 'valor_unitario' => '5.0000', 'desconto' => '0',
     'produto_ean' => '7891000100110', 'ean_original' => ''],
    ['id' => 13, 'item_num' => 3, 'descricao_original' => 'SEM CODIGO', 'quantidade' => '1', 'unidade' => 'UN',
     'valor_unitario_liquido' => '3', 'valor_unitario' => '3', 'desconto' => '0',
     'produto_ean' => null, 'ean_original' => ''],
    ['id' => 14, 'item_num' => 4, 'descricao_original' => 'ARROZ DE NOVO', 'quantidade' => '2', 'unidade' => 'UN',
     'valor_unitario_liquido' => '1.8', 'valor_unitario' => '1.8', 'desconto' => '0',
     'produto_ean' => '7891000100103', 'ean_original' => ''],
    ['id' => 15, 'item_num' => 5, 'descricao_original' => 'QUEIJO KG', 'quantidade' => '1.5', 'unidade' => 'KG',
     'valor_unitario_liquido' => '30', 'valor_unitario' => '30', 'desconto' => '0',
     'produto_ean' => null, 'ean_original' => '7896007811021'],
];
$plano = [
    ['productId' => 77, 'productCode' => 'OM7891000100103', 'productDescription' => 'ARROZ 5KG',
     'price' => 20.0, 'quantityToSupply' => 10, 'minimumQuantity' => 3, 'currentQuantity' => 5, 'inventoryItemId' => 900],
    ['productId' => 78, 'productCode' => '7896007811021', 'productDescription' => 'QUEIJO',
     'price' => 59.9, 'quantityToSupply' => 4, 'minimumQuantity' => 1, 'currentQuantity' => 0, 'inventoryItemId' => 901],
];
$inv = [
    ['productId' => 77, 'productExpirationDate' => '2027-01-10T00:00:00Z'],
    ['productId' => 77, 'productExpirationDate' => '2026-12-01T00:00:00Z'],
];
$feitos = [12 => [['pdv' => 'MIRANTE', 'quando' => '2026-10-15 10:00:00']]];
$v = repor_nota_itens($nota, $plano, $inv, $feitos, 2.0);
$por = array_column($v, null, 'id');

checar('cartoes: um por item', count($v), 5);
checar('no planograma (codigo com OM casa)', $por[11]['situacao'], 'planograma');
checar('valores atuais do planograma', [$por[11]['atual']['preco'], $por[11]['atual']['estoque'],
    $por[11]['atual']['necessaria'], $por[11]['atual']['critico']], [20.0, 5.0, 10.0, 3.0]);
checar('validade atual = a que vence primeiro', $por[11]['atual']['validade'], '2026-12-01');
checar('custo e o liquido da nota', $por[11]['custo'], 1.8);
checar('sugerido = custo x taxa', $por[11]['sugerido'], 3.6);
checar('entrada padrao = quantidade da nota', $por[11]['entrada'], 12.0);
checar('marcado por padrao', [$por[11]['marcado'], $por[11]['travado']], [true, false]);
checar('o bruto nao vaza para a tela', array_key_exists('bruto', $por[11]['atual']), false);

checar('fora do planograma', [$por[12]['situacao'], $por[12]['atual']], ['fora', null]);
checar('ja reposto: vem desmarcado', [$por[12]['marcado'], count($por[12]['feitos'])], [false, 1]);
checar('sem EAN: travado e desmarcado', [$por[13]['situacao'], $por[13]['travado'], $por[13]['marcado']], ['sem_ean', true, false]);
checar('mesmo EAN de outro item: travado com aviso', [$por[14]['travado'], str_contains((string) $por[14]['aviso'], 'item 1')], [true, true]);
checar('EAN so na nota (ean_original) tambem casa', $por[15]['situacao'], 'planograma');
checar('quantidade fracionada avisa', [$por[15]['fracionada'], $por[15]['entrada']], [true, 1.5]);

$sem_tp = array_column(repor_nota_itens($nota, [], [], [], 2.0, false), null, 'id');
checar('TouchPay nao leu: nao diz "fora do planograma"', $sem_tp[11]['situacao'], 'desconhecida');
checar('TouchPay nao leu: sem EAN continua sem EAN', $sem_tp[13]['situacao'], 'sem_ean');

// ---------- o formulario vira linhas do Repor em lote ----------
$campos = static fn(array $x) => $x + ['sel' => '1', 'entrada' => '12', 'validade' => '', 'escolha' => 'manter',
    'preco' => '3,60', 'necessaria' => '10', 'critico' => '3'];

$c = repor_nota_linhas_do_post([11 => $campos([])], $v);
checar('manter: nao manda preco', $c['linhas'][1]['preco'], '');
checar('linha leva o EAN e a descricao', [$c['linhas'][1]['codigo'], $c['linhas'][1]['descricao']], ['7891000100103', 'ARROZ 5KG']);
checar('mapa da linha para o item da nota', $c['mapa'], [1 => 11]);

$c = repor_nota_linhas_do_post([11 => $campos(['escolha' => 'trocar'])], $v);
checar('trocar: manda o preco digitado', $c['linhas'][1]['preco'], '3,60');

$c = repor_nota_linhas_do_post([11 => $campos(['sel' => ''] ), 15 => $campos(['entrada' => '2'])], $v);
checar('desmarcado fica de fora', array_keys($c['linhas']), [5]);

$c = repor_nota_linhas_do_post([], $v);
checar('nada marcado e erro', str_contains($c['erros'][0]['msg'], 'pelo menos um'), true);

$c = repor_nota_linhas_do_post([13 => $campos([])], $v);
checar('item sem EAN marcado a forca e erro', str_contains($c['erros'][0]['msg'], 'código de barras'), true);

// de ponta a ponta com a validacao do Repor em lote
$pdvs = [['id' => 5, 'nome' => 'MIRANTE', 'planograma_id' => 96988]];
$c = repor_nota_linhas_do_post([
    11 => $campos(['escolha' => 'trocar']),
    15 => $campos(['entrada' => '2', 'preco' => '', 'necessaria' => '', 'critico' => '', 'validade' => '15/03/2027']),
], $v);
$r = repor_ler_linhas($c['linhas'], 5, 2.0, $pdvs, '2026-10-16');
checar('validacao do lote aceita', $r['erros'], []);
checar('itens do lote: linha = numero do item na nota', array_column($r['itens'], 'linha'), [1, 5]);
checar('itens do lote: preco so no que trocou', [$r['itens'][0]['preco'], $r['itens'][1]['preco']], [3.6, null]);
checar('itens do lote: validade em ISO', $r['itens'][1]['validade'], '2027-03-15');

$c = repor_nota_linhas_do_post([15 => $campos(['entrada' => '1,5'])], $v);
$r = repor_ler_linhas($c['linhas'], 5, 2.0, $pdvs, '2026-10-16');
checar('entrada fracionada e recusada com a linha do item', [$r['erros'][0]['linha'], str_contains($r['erros'][0]['msg'], 'inteiro')], [5, true]);

// ---------- ja repostos ----------
$m = repor_nota_feitos_mapa([
    ['dados' => json_encode(['nota_item_id' => 11]), 'atualizado_em' => '2026-10-15 10:00:00', 'pdv' => 'MIRANTE'],
    ['dados' => json_encode(['nota_item_id' => 11]), 'atualizado_em' => '2026-10-16 09:00:00', 'pdv' => 'ITALIA'],
    ['dados' => json_encode(['codigo' => '789']), 'atualizado_em' => '2026-10-16 09:00:00', 'pdv' => 'X'],
]);
checar('mapa: item em dois PDVs', array_column($m[11], 'pdv'), ['MIRANTE', 'ITALIA']);
checar('mapa: linha sem nota_item_id e ignorada', array_keys($m), [11]);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
