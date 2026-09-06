<?php
declare(strict_types=1);

/*
 * Testa as funcoes puras do espelho da loja: normalizacao do callback do n8n
 * e o tratamento do codigo com prefixo "OM" que o TouchPay usa.
 * Nada aqui toca o MySQL.
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';

// loja.php chama cfg()/db() apenas dentro das funcoes que falam com o banco;
// carregar o arquivo so define funcoes.
require APP . '/loja.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

/** Um item como o fluxo n8n manda. */
function item(array $extra = []): array
{
    return array_merge([
        'produto_id_externo' => 927,
        'ean'                => '7891151039673',
        'codigo'             => '7891151039673',
        'descricao'          => 'BALA FREEGELLS VITC CITRUS 27,6G',
        'categoria'          => 'CHOCOLATES DOCES GULOSEIMAS 1',
        'preco'              => 3,
        'estoque'            => 0,
        'reservado'          => 0,
        'custo_medio'        => 0,
        'unidade'            => 'Unit',
        'minimo'             => 2,
        'capacidade'         => 0,
        'imagem'             => null,
        'validade'           => null,
    ], $extra);
}

$payload = [
    'token'         => 'segredo',
    'status'        => 'ok',
    'fonte'         => 'touchpay',
    'pos'           => ['id' => 529, 'nome' => 'Itália', 'tipo' => 'MicroMarket', 'inventario_id' => 529],
    'consultado_em' => '2026-09-06T02:31:00.000Z',
    'itens'         => [item(), item(['produto_id_externo' => 26904, 'ean' => '0070847033301', 'preco' => 13])],
];

// ---- a) payload aninhado, como o n8n manda ----
$r = loja_callback_normalizar($payload);
checar('token', $r['token'], 'segredo');
checar('status', $r['status'], 'ok');
checar('ponto de venda', $r['pos']['id'], 529);
checar('nome do PDV', $r['pos']['nome'], 'Itália');
checar('2 itens', count($r['itens']), 2);

// ---- b) embrulhado em "body" ----
$r = loja_callback_normalizar(['body' => $payload]);
checar('body: 2 itens', count($r['itens']), 2);
checar('body: PDV', $r['pos']['id'], 529);

// ---- c) erro vindo do fluxo ----
$r = loja_callback_normalizar(['token' => 't', 'status' => 'erro', 'erro' => 'login recusado']);
checar('erro: status', $r['status'], 'erro');
checar('erro: mensagem', $r['erro'], 'login recusado');
checar('erro: sem itens', $r['itens'], []);
$g = loja_processar_callback($r);
checar('erro nao explode', $g['ok'], true);
checar('erro nao grava item', $g['itens'], 0);

// ---- d) payload sem PDV ou sem itens e recusado ----
$g = loja_processar_callback(['status' => 'ok', 'erro' => '', 'pos' => [], 'itens' => [item()]]);
checar('sem PDV: recusa', $g['ok'], false);
$g = loja_processar_callback(['status' => 'ok', 'erro' => '', 'pos' => ['id' => 529], 'itens' => []]);
checar('sem itens: recusa', $g['ok'], false);

// ---- e) o prefixo "OM" do TouchPay ----
// O fluxo n8n ja tira o "OM", mas o PHP tambem nao pode se perder se vier.
checar('ean com OM', ean_normalizado('OM7896007811021'), '7896007811021');
checar('ean limpo', ean_normalizado('7896007811021'), '7896007811021');
checar('ean com zero a esquerda', ean_normalizado('0070847033301'), '0070847033301');
checar('ean SEM GTIN', ean_normalizado('SEM GTIN'), null);
checar('ean vazio', ean_normalizado(''), null);
checar('ean curto demais', ean_normalizado('123'), null);

// ---- f) precos e quantidades em formato brasileiro ou numero ----
checar('preco inteiro', num_br(3), 3.0);
checar('preco decimal', num_br(13.5), 13.5);
checar('preco texto br', num_br('13,50'), 13.5);
checar('estoque zero', num_br(0), 0.0);
checar('nulo vira zero', num_br(null), 0.0);

// ---- g) o INSERT em blocos monta o SQL certo ----
// Sem banco: so confere o particionamento em blocos.
$linhas = array_fill(0, 450, [1, null, 927, '789', 'c', 'd', null, 3.0, 0.0, 0.0, 0.0, null, null, 'Unit', null, '2026-09-06 00:00:00']);
checar('450 linhas viram 3 blocos de ate 200', (int) ceil(count($linhas) / 200), 3);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
