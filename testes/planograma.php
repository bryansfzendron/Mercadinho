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

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
