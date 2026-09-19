<?php
declare(strict_types=1);

/*
 * A parte pura da busca de nome por codigo de barras: a chave do cache e o
 * que se extrai da resposta da Open Food Facts. A consulta HTTP e o banco
 * ficam de fora — aqui nada sai do processo.
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/mercado.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

// ------------------------------------------------------- a chave do cache
checar('EAN-13 vira chave', mercado_chave('7891000100103'), '7891000100103');
checar('espaco e hifen saem', mercado_chave(' 789-1000 100103 '), '7891000100103');
checar('EAN-8 tambem serve', mercado_chave('78910014'), '78910014');
// Balanca de mercado emite codigo interno com peso embutido: nao e GTIN
// valido e mesmo assim precisa poder guardar um nome.
checar('codigo de balanca entra', mercado_chave('2001234000005'), '2001234000005');
checar('codigo curto demais nao vira chave', mercado_chave('123'), null);
checar('campo vazio nao vira chave', mercado_chave(''), null);
checar('texto sem digito nao vira chave', mercado_chave('sem gtin'), null);
checar('chave nao passa de 14 digitos',
    mercado_chave('123456789012345678'), '12345678901234');

// ------------------------------------------- o nome na resposta da OFF
$resposta = static fn (array $produto, int $status = 1): array =>
    ['status' => $status, 'product' => $produto];

checar('produto nao encontrado nao vira nome',
    off_nome(['status' => 0, 'status_verbose' => 'product not found']), null);
checar('resposta sem produto nao quebra', off_nome(['status' => 1]), null);
checar('resposta vazia nao quebra', off_nome([]), null);

checar('nome em portugues e o preferido',
    off_nome($resposta(['product_name' => 'Condensed Milk', 'product_name_pt' => 'Leite Condensado'])),
    'Leite Condensado');
checar('sem portugues, usa o nome geral',
    off_nome($resposta(['product_name' => 'Condensed Milk'])),
    'Condensed Milk');
checar('produto sem nome nenhum e como nao achar',
    off_nome($resposta(['brands' => 'Nestlé'])), null);

// A quantidade entra junto: numa lista de compras, "Coca-Cola" e
// "Coca-Cola 2 L" sao itens de preco diferente.
checar('quantidade entra no nome',
    off_nome($resposta(['product_name_pt' => 'Leite Condensado Moça', 'quantity' => '395 g'])),
    'Leite Condensado Moça 395 g');
checar('quantidade que ja esta no nome nao entra duas vezes',
    off_nome($resposta(['product_name_pt' => 'Refrigerante Coca-Cola 2Lt', 'quantity' => '2l'])),
    'Refrigerante Coca-Cola 2Lt');
checar('sem quantidade, so o nome',
    off_nome($resposta(['product_name_pt' => 'Café Torrado'])), 'Café Torrado');
checar('espaco sobrando nao vira nome com ponta solta',
    off_nome($resposta(['product_name_pt' => '  Arroz  ', 'quantity' => '  '])), 'Arroz');

// O campo do banco e VARCHAR(255): nome gigante entra cortado, nao recusado.
$gigante = off_nome($resposta(['product_name_pt' => str_repeat('a', 300)]));
checar('nome gigante e cortado em 255', mb_strlen((string) $gigante), 255);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
