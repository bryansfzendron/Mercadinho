<?php
declare(strict_types=1);

/*
 * A parte pura de repor a gondola: qual item da lista e o que foi bipado, o
 * que mudou de/para, e a leitura do JWT. Nada aqui sai do processo — nenhuma
 * chamada ao TouchPay, nenhum banco.
 *
 * Isto e o que impede os dois erros caros desta tela: mexer no produto errado
 * (pg_casar) e gravar alteracao que ninguem pediu (pg_mudancas).
 *
 *   php testes/planograma.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/mercado.php';
require APP . '/touchpay.php';
require APP . '/planograma.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

// --------------------------------------------------- o "OM" da frente
// Parte dos codigos do TouchPay vem com OM grudado. Sem tirar, o EAN bipado
// nunca casa com a linha do planograma.
checar('OM sai do codigo', pg_codigo_limpo('OM7896007811021'), '7896007811021');
checar('minusculo tambem', pg_codigo_limpo('om7896007811021'), '7896007811021');
checar('codigo sem OM passa reto', pg_codigo_limpo('7896290300189'), '7896290300189');
// "OMO" e sabao em po: comer duas letras de um nome nao e limpar prefixo.
checar('OM de palavra nao e prefixo', pg_codigo_limpo('OMO LAVAGEM'), 'OMO LAVAGEM');
checar('vazio continua vazio', pg_codigo_limpo(null), '');

// ------------------------------------------- de onde vem a lista de itens
$plano = ['entries' => ['items' => [['productId' => 1]], 'totalItems' => 1]];
checar('planograma vem em entries.items', pg_itens($plano)[0]['productId'], 1);
checar('cadastro vem em items', pg_itens(['items' => [['id' => 7]]])[0]['id'], 7);
checar('lista pelada serve', pg_itens([['id' => 9]])[0]['id'], 9);
checar('resposta vazia nao quebra', pg_itens([]), []);
checar('resposta que nao e array nao quebra', pg_itens('erro'), []);

// -------------------------------------------- qual item foi o bipado
// A busca deles e por pedaco de texto: o EAN inteiro ainda traz o irmao.
$itens = [
    ['productId' => 111, 'productCode' => '7896290300141', 'productDescription' => 'Arroz 5kg'],
    ['productId' => 222, 'productCode' => 'OM7896290300189', 'productDescription' => 'Arroz 1kg'],
];
checar('productId manda quando o espelho sabe',
    pg_casar($itens, 222, '7896290300141')['productDescription'], 'Arroz 1kg');
checar('sem productId, vale o codigo igual',
    pg_casar($itens, null, '7896290300189')['productId'], 222);
checar('o OM nao atrapalha o casamento',
    pg_casar($itens, null, '7896290300189')['productCode'], 'OM7896290300189');
// O erro que esta funcao existe para nao cometer: pegar o primeiro da lista.
checar('codigo que nao esta na lista nao casa com ninguem',
    pg_casar($itens, null, '7899999999999'), null);
checar('lista vazia nao casa', pg_casar([], 222, '789'), null);
checar('productBarCode tambem serve de casamento',
    pg_casar([['productId' => 5, 'productCode' => 'INTERNO-9', 'productBarCode' => '7891000100103']],
        null, '7891000100103')['productId'], 5);

// ----------------------------------------------- o que o dedo digitou
checar('virgula decimal', pg_numero('9,50'), 9.5);
checar('ponto decimal', pg_numero('9.50'), 9.5);
checar('com R$ junto', pg_numero('R$ 12,90'), 12.9);
checar('inteiro', pg_numero('3'), 3.0);
// Vazio e null de proposito: quer dizer "nao encostei neste campo". Virar
// zero faria apagar o preco sem querer deixar o produto de graca.
checar('campo vazio e null, nao zero', pg_numero(''), null);
checar('null continua null', pg_numero(null), null);
checar('zero digitado e zero de verdade', pg_numero('0'), 0.0);
checar('texto sem numero e null', pg_numero('abc'), null);

// ---------------------------------------------------- a linha normalizada
$entrada = pg_entrada_normalizar([
    'productId' => 4028, 'inventoryItemId' => 6485456,
    'productCode' => 'OM7896290300189', 'productDescription' => 'Arroz Prato Fino 1kg',
    'price' => 9, 'quantityToSupply' => '3', 'minimumQuantity' => 1, 'currentQuantity' => 0,
]);
checar('produto', $entrada['produto_id'], 4028);
checar('item de inventario', $entrada['inventario_item_id'], 6485456);
checar('ean sai limpo do codigo', $entrada['ean'], '7896290300189');
checar('quantidade em texto vira numero', $entrada['necessaria'], 3.0);
// O PUT deles quer a linha inteira de volta: mandar so o que mudou zera o
// resto. Por isso o objeto cru viaja junto.
checar('o objeto cru fica guardado', $entrada['bruto']['productCode'], 'OM7896290300189');

$produto = pg_produto_normalizar(['id' => 41978, 'code' => '7896290300141',
    'description' => 'Arroz Prato Fino 5Kg']);
checar('produto do cadastro', $produto['produto_id'], 41978);
// Preco e coisa do planograma, nao do cadastro: e por isso que incluir exige
// digitar um.
checar('cadastro nao tem preco', $produto['preco'], null);

// ------------------------------------------------------- o que mudou
$antes = ['preco' => 9.0, 'necessaria' => 3.0, 'critico' => 1.0, 'estoque' => 0.0];

checar('nada digitado, nada muda', pg_mudancas($antes, []), []);
checar('mesmo valor nao e alteracao',
    pg_mudancas($antes, ['preco' => 9.0, 'estoque' => 0.0]), []);
// 9.1 do JSON e 9.1 digitado nao sao o mesmo bit; sem folga, abrir e salvar
// sem tocar em nada acusaria alteracao em todo campo com casa decimal.
checar('folga de float nao vira alteracao',
    pg_mudancas(['preco' => 9.1], ['preco' => 9.1000001]), []);
checar('meio centavo ja e alteracao',
    count(pg_mudancas(['preco' => 9.10], ['preco' => 9.11])), 1);

$m = pg_mudancas($antes, ['preco' => 9.5, 'estoque' => 3.0]);
checar('duas alteracoes', count($m), 2);
checar('a ordem e a dos campos', $m[0]['campo'], 'preco');
checar('de', $m[0]['de'], 9.0);
checar('para', $m[0]['para'], 9.5);
checar('estoque entra junto', $m[1]['campo'], 'estoque');

// Campo nao digitado nao pode virar zero: seria zerar o estoque de quem so
// queria mexer no preco.
checar('campo null nao entra nas mudancas',
    pg_mudancas($antes, ['preco' => 9.5, 'estoque' => null])[0]['campo'], 'preco');
checar('e nao entra mais nada', count(pg_mudancas($antes, ['preco' => 9.5, 'estoque' => null])), 1);
// Zerar estoque e uma alteracao de verdade e precisa passar.
checar('zerar estoque e alteracao',
    pg_mudancas(['estoque' => 5.0], ['estoque' => 0.0])[0]['para'], 0.0);

// ------------------------------------------------------------- o JWT
// exp = 1790025438, do token real do painel.
$jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'
     . '.' . rtrim(strtr(base64_encode('{"exp":1790025438,"iss":"Mercurio"}'), '+/', '-_'), '=')
     . '.assinatura';
checar('le o exp do token', tp_jwt_exp($jwt), 1790025438);
checar('token torto nao quebra', tp_jwt_exp('nao-e-jwt'), null);
checar('token sem exp devolve null',
    tp_jwt_exp('a.' . rtrim(strtr(base64_encode('{"iss":"x"}'), '+/', '-_'), '=') . '.b'), null);

// ------------------------------------------------------- o erro deles
checar('mensagem do JSON aparece',
    tp_erro_legivel(422, '{"message":"Preco invalido"}'),
    'TouchPay (HTTP 422): Preco invalido');
// Pagina de erro em HTML nao vai crua para uma tela de celular.
checar('HTML nao vaza para a tela',
    tp_erro_legivel(500, '<html><body>Internal Server Error</body></html>'),
    'TouchPay respondeu HTTP 500.');
checar('403 explica que e permissao',
    str_contains(tp_erro_legivel(403, ''), 'permissao'), true);

// ===================================================================
// VALIDADE
// ===================================================================

// ------------------------------------------------------ ler a data
checar('do jeito que o TouchPay manda', pg_data_iso('2027-02-14T00:00:00Z'), '2027-02-14');
checar('do campo de data', pg_data_iso('2027-02-14'), '2027-02-14');
checar('do dedo, em portugues', pg_data_iso('14/02/2027'), '2027-02-14');
// 31 de fevereiro passa em qualquer regex e nao existe no calendario.
checar('dia que nao existe e null', pg_data_iso('2027-02-31'), null);
checar('mes que nao existe e null', pg_data_iso('2027-13-01'), null);
checar('bissexto de verdade passa', pg_data_iso('2028-02-29'), '2028-02-29');
checar('bissexto falso nao passa', pg_data_iso('2027-02-29'), null);
// Vazio e "nao encostei nesta validade", nunca "apague a validade".
checar('vazio e null', pg_data_iso(''), null);
checar('null continua null', pg_data_iso(null), null);
checar('texto solto e null', pg_data_iso('amanha'), null);

checar('data em portugues', pg_data_br('2027-02-14'), '14/02/2027');
checar('sem data, travessao', pg_data_br(null), '—');
checar('do jeito que eles querem de volta', pg_data_tp('2027-02-14'), '2027-02-14T00:00:00Z');
checar('null nao vira data', pg_data_tp(null), null);

// --------------------------------------------- data que gente digita
$hoje = '2026-09-22';
checar('daqui a um ano passa', pg_validade_plausivel('2027-09-22', $hoje), true);
checar('ontem passa: cadastrar o que venceu e uso legitimo',
    pg_validade_plausivel('2026-09-21', $hoje), true);
checar('cinco anos de prateleira passa', pg_validade_plausivel('2031-09-01', $hoje), true);
// O caso real: um item no inventario deles com validade em 5027.
checar('o ano 5027 nao passa', pg_validade_plausivel('5027-02-18', $hoje), false);
checar('onze anos nao passa', pg_validade_plausivel('2037-10-01', $hoje), false);
checar('tres anos atras nao passa', pg_validade_plausivel('2023-01-01', $hoje), false);
checar('sem data nao e plausivel', pg_validade_plausivel(null, $hoje), false);

// ------------------------------------------- manter a antiga ou gravar
// A regra: vale a que vence PRIMEIRO. Repor com lote novo nao pode empurrar
// a data para a frente e esconder o pacote velho que ficou la atras.
checar('sem nada cadastrado, grava',
    pg_validade_decidir(null, '2027-06-30')['acao'], 'gravar');
checar('a nova vence antes: grava',
    pg_validade_decidir('2027-06-30', '2027-02-14')['acao'], 'gravar');
checar('a do estoque vence antes: mantem',
    pg_validade_decidir('2027-02-14', '2027-06-30')['acao'], 'manter');
checar('mantendo, a data que fica e a antiga',
    pg_validade_decidir('2027-02-14', '2027-06-30')['data'], '2027-02-14');
checar('data igual nao e alteracao',
    pg_validade_decidir('2027-02-14', '2027-02-14')['acao'], 'nada');
checar('sem digitar nada, nada acontece',
    pg_validade_decidir('2027-02-14', null)['acao'], 'nada');
// O motivo vai para a tela: e ele que explica a escolha marcada.
checar('o motivo diz qual vence antes',
    str_contains(pg_validade_decidir('2027-02-14', '2027-06-30')['motivo'], '14/02/2027'), true);

// ------------------------------------------------------ o uuid
$u1 = pg_uuid();
checar('uuid tem o formato', (bool) preg_match(
    '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u1), true);
checar('dois uuids nao se repetem', $u1 === pg_uuid(), false);

// ===================================================================
// A OPERACAO — a parte que pode estragar a loja inteira
// ===================================================================

// Tres produtos no planograma. O 20 tem validade, o 30 tem validade, o 10
// nao tem. Vamos mexer so no 10.
$entradas = [
    ['productId' => 10, 'inventoryItemId' => 101, 'quantityToSupply' => 5, 'capacity' => 12, 'currentQuantity' => 3],
    ['productId' => 20, 'inventoryItemId' => 102, 'quantityToSupply' => 0, 'capacity' => 6,  'currentQuantity' => 7],
    ['productId' => 30, 'inventoryItemId' => 103, 'quantityToSupply' => 2, 'capacity' => 8,  'currentQuantity' => 0],
];
$inventario = [
    ['productId' => 10, 'quantity' => 4, 'productExpirationDate' => null],
    ['productId' => 20, 'quantity' => 7, 'productExpirationDate' => '2027-02-14T00:00:00Z'],
    ['productId' => 30, 'quantity' => 0, 'productExpirationDate' => '2028-06-30T00:00:00Z'],
];

$op = pg_operacao_montar($entradas, $inventario, 96823, 10, '2027-05-20',
    'uuid-de-teste', '2026-09-22T11:15:13.000', '2026-09-22T11:19:03.000');
$corpo = $op['corpo'];
$itens = $corpo['supplyItems'];

// A lista COMPLETA. Mandar parcial e deixar o servidor deles decidir sozinho
// o que fazer com os que faltaram — e isso ninguem aqui sabe.
checar('vai o planograma inteiro, nao so o alvo', count($itens), 3);
checar('o cabecalho diz que planograma e', $corpo['planogramId'], 96823);
checar('o tipo e o mesmo que o app deles manda', $corpo['type'], 'Inventory');
checar('o supplyType tambem', $corpo['supplyType'], 'PickList');
checar('operacao nao e cega', $corpo['isBlindOperation'], false);

// O ALVO: e o unico confirmado, e e o unico com data nova.
checar('o alvo leva a data nova', $itens[0]['productExpirationDate'], '2027-05-20T00:00:00Z');
checar('o alvo e confirmado', $itens[0]['dateConfirmed'], '2026-09-22T11:19:03.000');
// Confirmar carrega quantidade. Vai a do inventario lido agora: confirmar o
// numero que o proprio TouchPay acabou de dizer nao move estoque nenhum.
checar('confirma a quantidade que o inventario disse', $itens[0]['confirmedQuantity'], 4.0);
checar('e ela bate com o previousQuantity', $itens[0]['previousQuantity'], 4.0);
checar('nao pede para apagar', $itens[0]['removeExpirationDate'], false);

// OS OUTROS: inertes, e com a validade que JA TINHAM. Este e o teste que
// impede o pior erro possivel — montar a lista so com o planograma faria
// todos viajarem com validade null e apagaria a validade da loja inteira.
checar('quem nao foi tocado mantem a validade',
    $itens[1]['productExpirationDate'], '2027-02-14T00:00:00Z');
checar('e o outro tambem', $itens[2]['productExpirationDate'], '2028-06-30T00:00:00Z');
checar('ninguem mais e confirmado', $itens[1]['dateConfirmed'], null);
checar('nem tem quantidade confirmada', $itens[1]['confirmedQuantity'], null);
checar('produto sem validade continua sem', $itens[0]['productExpirationDate'] === null, false);

// O item de inventario e o que amarra tudo: sem ele nao ha onde gravar.
checar('cada item leva seu inventoryItemId', $itens[1]['inventoryItemId'], 102);
checar('capacidade vem do planograma', $itens[0]['capacity'], 12.0);
checar('necessaria vem do planograma', $itens[0]['quantityToSupply'], 5.0);
// Sem inventario para o produto, sobra o numero do planograma.
$soPlano = pg_operacao_montar($entradas, [], 96823, 10, '2027-05-20', 'u', 'i', 'f');
checar('sem inventario, a quantidade vem do planograma',
    $soPlano['corpo']['supplyItems'][0]['previousQuantity'], 3.0);

// ------------------------------------------ a porta antes de escrever
checar('corpo bom passa', pg_operacao_conferir($corpo, $inventario, 10), null);

// Lista vazia nunca sai daqui.
checar('operacao sem itens nao sai',
    str_contains((string) pg_operacao_conferir(['supplyItems' => []], $inventario, 10), 'nenhum item'), true);

// Confirmar quem ninguem pediu: seria mexer no produto errado, o erro caro
// desta tela desde o comeco. Um segundo item confirmado ja chega para parar,
// e a queixa nomeia o intruso em vez de so contar quantos foram.
$intruso = $corpo;
$intruso['supplyItems'][1]['dateConfirmed'] = '2026-09-22T11:19:03.000';
checar('confirmar um item a mais para a gravacao',
    str_contains((string) pg_operacao_conferir($intruso, $inventario, 10), 'ninguem pediu'), true);
checar('e a queixa diz qual foi',
    str_contains((string) pg_operacao_conferir($intruso, $inventario, 10), '20'), true);

// Nenhum confirmado: a operacao nao faria nada, e mandar assim e escrever na
// loja inteira para nada.
$nenhum = $corpo;
$nenhum['supplyItems'][0]['dateConfirmed'] = null;
checar('operacao sem ninguem marcado para a gravacao',
    str_contains((string) pg_operacao_conferir($nenhum, $inventario, 10), 'em vez de um'), true);

$trocado = $corpo;
$trocado['supplyItems'][0]['dateConfirmed'] = null;
$trocado['supplyItems'][2]['dateConfirmed'] = '2026-09-22T11:19:03.000';
checar('confirmar o produto errado para a gravacao',
    str_contains((string) pg_operacao_conferir($trocado, $inventario, 10), 'ninguem pediu'), true);

// O PIOR CASO: um item que tinha validade ia sair sem ela. Se o servidor ler
// null como "apague", isso apagaria a validade de quem nunca foi tocado.
$perdida = $corpo;
$perdida['supplyItems'][1]['productExpirationDate'] = null;
checar('validade que sumiu no caminho para a gravacao',
    str_contains((string) pg_operacao_conferir($perdida, $inventario, 10), 'ia embora'), true);

// Item sem inventoryItemId nao tem onde gravar; mandar assim e torcer.
$semItem = $corpo;
$semItem['supplyItems'][2]['inventoryItemId'] = 0;
checar('item sem inventario para a gravacao',
    str_contains((string) pg_operacao_conferir($semItem, $inventario, 10), 'sem item de inventario'), true);

// O alvo PODE ficar sem validade (e justamente o que esta sendo trocado),
// entao a regra de cima nao pode pega-lo por engano.
$alvoSemData = pg_operacao_montar($entradas, $inventario, 96823, 20, null, 'u', 'i', 'f');
checar('trocar a validade do alvo nao dispara o alarme',
    pg_operacao_conferir($alvoSemData['corpo'], $inventario, 20), null);

// ============================================================
// A URL DO INVENTARIO — o instante, nunca a meia-noite
// ============================================================
// A quantidade lida aqui vira confirmedQuantity na operacao que grava
// validade. Pedindo a meia-noite, o numero e o do comeco do dia: confirmar
// a contagem da manha depois de um dia de vendas mandaria o estoque de volta
// para o numero da manha. Foi assim que este arquivo nasceu errado.
$tarde = '2026-09-23T17:40:12.000Z';
$url = tp_inventario_url(42, null, 1, 10000, $tarde);

checar('a hora vai na URL, codificada',
    str_contains($url, 'date=2026-09-23T17%3A40%3A12.000Z'), true);
// A regressao que este teste existe para impedir:
checar('nunca a meia-noite', str_contains($url, 'T00%3A00%3A00'), false);
checar('o inventario pedido vai na URL', str_contains($url, 'inventoryIds=42'), true);
checar('o fuso de Brasilia acompanha', str_contains($url, 'timezoneOffset=180'), true);
checar('so ponto de venda', str_contains($url, 'inventoryTypes=pointOfSale'), true);

// Sem produto o filtro fica vazio (traz o inventario inteiro); com produto,
// preenchido — e o que torna a leitura de um bipe barata.
checar('sem produto, filtro vazio',
    str_contains(tp_inventario_url(42, null, 1, 50, $tarde), 'productId=&'), true);
checar('com produto, filtro preenchido',
    str_contains(tp_inventario_url(42, 7397, 1, 50, $tarde), 'productId=7397&'), true);
checar('produto zero e o mesmo que nenhum',
    str_contains(tp_inventario_url(42, 0, 1, 50, $tarde), 'productId=&'), true);

checar('a pagina vai na URL', str_contains(tp_inventario_url(42, null, 3, 50, $tarde), 'page=3&'), true);

// O instante e UTC e tem o formato que eles aceitam.
checar('o instante tem cara de ISO 8601 em Z', (bool) preg_match(
    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/', tp_instante()), true);
// gmdate e nao date: mandar a hora local marcada como Z seria pedir o estoque
// de tres horas no futuro.
checar('o instante e UTC, nao a hora daqui',
    substr(tp_instante(), 0, 13), substr(gmdate('Y-m-d\TH'), 0, 13));

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
