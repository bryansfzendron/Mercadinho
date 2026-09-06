<?php
declare(strict_types=1);

/*
 * Testa as funcoes puras das vendas do TouchPay: normalizacao do callback,
 * a data com fuso e a conta do valor unitario (que a API nao manda).
 * Nada aqui toca o MySQL.
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';

// vendas.php so define funcoes; cfg()/db() so aparecem dentro das que falam
// com o banco ou com a rede.
require APP . '/vendas.php';

date_default_timezone_set('America/Sao_Paulo');

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

/** Uma venda como o fluxo n8n manda. */
function venda(array $extra = []): array
{
    return array_merge([
        'id'              => 511241,
        'uuid'            => '112be6e6-6fd0-465e-93fc-463776699063',
        'data'            => '2026-09-06T11:43:16-03:00',
        'pdv_id'          => 628,
        'pdv_nome'        => 'Nature',
        'resultado'       => 'Ok',
        'forma_pagamento' => 'Debit',
        'bandeira'        => 'MASTERCARD',
        'valor_total'     => 23.45,
        'valor_pago'      => 23.45,
        'codigo'          => '19DCF7501FE94D4B9D6557CD18BA9C76',
        'itens'           => [item()],
    ], $extra);
}

function item(array $extra = []): array
{
    return array_merge([
        'produto_id_externo' => 26934,
        'ean'                => '7894900027013',
        'codigo'             => '7894900027013',
        'descricao'          => 'REFRIGERANTE PET COCA COLA 2 LT',
        'categoria'          => 'BEBIDAS 1',
        'quantidade'         => 1,
        'valor_total'        => 14.95,
    ], $extra);
}

// ---------------------------------------------------------------- callback
$p = vendas_callback_normalizar(['token' => 'x', 'vendas' => [venda()], 'lote' => 2, 'lotes' => 5]);
checar('token do callback', $p['token'], 'x');
checar('status padrao e ok', $p['status'], 'ok');
checar('conta as vendas', count($p['vendas']), 1);
checar('numero do lote', [$p['lote'], $p['lotes']], [2, 5]);

$embrulhado = vendas_callback_normalizar(['body' => ['token' => 'y', 'vendas' => [venda(), venda()]]]);
checar('aceita corpo embrulhado em body', [count($embrulhado['vendas']), $embrulhado['token']], [2, 'y']);

checar('lote vazio nao vira lixo', vendas_callback_normalizar(['token' => 'z'])['vendas'], []);
checar('status de erro chega inteiro',
    vendas_callback_normalizar(['status' => 'erro', 'erro' => 'deu ruim'])['erro'], 'deu ruim');

// ------------------------------------------------------------------- datas
checar('data com fuso vira horario local', venda_data_hora('2026-09-06T11:43:16-03:00'), '2026-09-06 11:43:16');
// O TouchPay manda -03:00; se um dia mandar UTC, a conversao tem que valer.
checar('data em UTC e convertida', venda_data_hora('2026-09-06T14:43:16Z'), '2026-09-06 11:43:16');
checar('data vazia e nula', venda_data_hora(''), null);
checar('data sem sentido e nula', venda_data_hora('nao e data'), null);

// -------------------------------------------------------------- normalizar
$n = venda_normalizar(venda());
checar('id externo', $n['venda']['externo_id'], 511241);
checar('data normalizada', $n['venda']['data_hora'], '2026-09-06 11:43:16');
checar('pdv externo', $n['venda']['pdv_externo_id'], 628);
checar('forma de pagamento', $n['venda']['forma_pagamento'], 'Debit');
checar('um item', count($n['itens']), 1);
checar('ean do item', $n['itens'][0]['ean'], '7894900027013');

// O que a API NAO manda: o unitario. Item de quantidade 4 vem com o valor
// das 4 — dividir e obrigacao nossa.
$q4 = venda_normalizar(venda(['itens' => [item(['quantidade' => 4, 'valor_total' => 15.56])]]));
checar('total da linha preservado', $q4['itens'][0]['valor_total'], 15.56);
checar('unitario calculado', $q4['itens'][0]['valor_unitario'], 3.89);

// Quantidade zero ou ausente nao pode virar divisao por zero.
$q0 = venda_normalizar(venda(['itens' => [item(['quantidade' => 0, 'valor_total' => 7.5])]]));
checar('quantidade zero vira 1', $q0['itens'][0]['quantidade'], 1.0);
checar('unitario sem divisao por zero', $q0['itens'][0]['valor_unitario'], 7.5);

// Item sem codigo de barras: EAN nulo, mas a linha entra.
$semEan = venda_normalizar(venda(['itens' => [item(['ean' => '', 'codigo' => ''])]]));
checar('ean vazio vira nulo', $semEan['itens'][0]['ean'], null);
checar('codigo vazio vira nulo', $semEan['itens'][0]['codigo'], null);
checar('item sem ean ainda entra', count($semEan['itens']), 1);

// Venda sem id ou sem data nao tem como ser gravada nem reimportada.
checar('venda sem id e descartada', venda_normalizar(venda(['id' => 0])), null);
checar('venda sem data e descartada', venda_normalizar(venda(['data' => ''])), null);

// Venda negada entra: o relatorio e que decide filtrar.
checar('resultado preservado', venda_normalizar(venda(['resultado' => 'Denied']))['venda']['resultado'], 'Denied');

// Venda sem item nenhum e valida (acontece em transacao cancelada).
checar('venda sem item nao quebra', venda_normalizar(venda(['itens' => []]))['itens'], []);

// Texto com entidade HTML e campo comprido, como no resto do app.
$longo = venda_normalizar(venda(['itens' => [item(['descricao' => 'CAF&Eacute; ' . str_repeat('A', 300)])]]));
checar('entidade html decodificada', mb_substr($longo['itens'][0]['descricao'], 0, 5), 'CAFÉ ');
checar('descricao cortada em 255', mb_strlen($longo['itens'][0]['descricao']), 255);

// ------------------------------------------------------------------- lote
$lote = vendas_normalizar_lote([venda(), venda(['id' => 511242])]);
checar('lote com duas vendas', count($lote), 2);

// A mesma venda duas vezes acontece quando entra transacao nova no meio da
// paginacao. Duplicata no lote derrubaria o INSERT inteiro pela chave unica.
$repetida = vendas_normalizar_lote([venda(), venda(), venda(['id' => 511242])]);
checar('venda repetida entra uma vez so', count($repetida), 2);
checar('a repetida mantem os dados', $repetida[0]['venda']['externo_id'], 511241);

$sujo = vendas_normalizar_lote([venda(), 'nao e array', venda(['id' => 0]), []]);
checar('lote ignora lixo', count($sujo), 1);
checar('lote so de lixo fica vazio', vendas_normalizar_lote(['x', []]), []);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
