<?php
declare(strict_types=1);

/*
 * Testa a skill da Alexa (app/alexa.php): a verificacao da assinatura da
 * Amazon, a leitura do periodo falado e as falas. Nada aqui toca o MySQL nem
 * a rede — a cadeia de certificados e gerada na hora, com uma CA de mentira.
 *
 *   php testes/alexa.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/conta.php';
require APP . '/loja.php';
require APP . '/metas.php';
require APP . '/alexa.php';

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

// ---------------------------------------------------------------------
// URL do certificado (exemplos da documentacao da Alexa)
// ---------------------------------------------------------------------
foreach ([
    'https://s3.amazonaws.com/echo.api/echo-api-cert.pem'            => true,
    'https://s3.amazonaws.com:443/echo.api/echo-api-cert.pem'        => true,
    'HTTPS://s3.AMAZONAWS.com/echo.api/echo-api-cert.pem'            => true,
    'https://s3.amazonaws.com/echo.api/../echo.api/echo-api-cert.pem' => true,
    'http://s3.amazonaws.com/echo.api/echo-api-cert.pem'             => false,
    'https://notamazon.com/echo.api/echo-api-cert.pem'               => false,
    'https://s3.amazonaws.com/EcHo.aPi/echo-api-cert.pem'            => false,
    'https://s3.amazonaws.com/invalid.path/echo-api-cert.pem'        => false,
    'https://s3.amazonaws.com:563/echo.api/echo-api-cert.pem'        => false,
    'https://s3.amazonaws.com/echo.api/../invalid.path/cert.pem'     => false,
    'https://user@s3.amazonaws.com/echo.api/echo-api-cert.pem'       => false,
    'https://s3.amazonaws.com/echo.api/'                             => false,
] as $url => $esperado) {
    checar('url ' . $url, alexa_url_certificado_ok($url), $esperado);
}

// ---------------------------------------------------------------------
// Horario do pedido
// ---------------------------------------------------------------------
$agora = strtotime('2026-11-11T14:00:00Z');
checar('horario: na hora', alexa_horario_ok('2026-11-11T14:00:00Z', $agora), true);
checar('horario: 150 s antes passa', alexa_horario_ok('2026-11-11T13:57:30Z', $agora), true);
checar('horario: 151 s antes nao', alexa_horario_ok('2026-11-11T13:57:29Z', $agora), false);
checar('horario: futuro demais nao', alexa_horario_ok('2026-11-11T14:03:00Z', $agora), false);
checar('horario: vazio nao', alexa_horario_ok('', $agora), false);

// ---------------------------------------------------------------------
// Cadeia de certificados e assinatura
// ---------------------------------------------------------------------
$dir = sys_get_temp_dir() . '/alexa-teste-' . bin2hex(random_bytes(4));
mkdir($dir);
$cnf = $dir . '/openssl.cnf';
file_put_contents($cnf, <<<CNF
[ req ]
distinguished_name = dn
[ dn ]
[ v3_ca ]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
[ v3_amazon ]
basicConstraints = CA:FALSE
keyUsage = digitalSignature
subjectAltName = DNS:echo-api.amazon.com
[ v3_outro ]
basicConstraints = CA:FALSE
keyUsage = digitalSignature
subjectAltName = DNS:example.com
CNF);
$op = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

$ca_chave = openssl_pkey_new($op);
$ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'CA de teste'], $ca_chave, $op), null, $ca_chave, 30,
    $op + ['x509_extensions' => 'v3_ca']);
openssl_x509_export($ca, $ca_pem);
file_put_contents($dir . '/ca.pem', $ca_pem);

$emitir = static function (string $ext) use ($ca, $ca_chave, $op): array {
    $chave = openssl_pkey_new($op);
    $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'echo-api.amazon.com'], $chave, $op), $ca, $ca_chave, 30,
        $op + ['x509_extensions' => $ext], random_int(1, PHP_INT_MAX));
    openssl_x509_export($cert, $pem);
    return [$pem, $chave];
};
[$folha_pem, $folha_chave] = $emitir('v3_amazon');
[$outro_pem, $outro_chave] = $emitir('v3_outro');

$cadeia = $folha_pem . $ca_pem;
$agora_real = time();
$cas = [$dir . '/ca.pem'];

checar('pem: separa os dois certificados', count(alexa_pem_certificados($cadeia)), 2);
$chave = alexa_certificado_ok($cadeia, $agora_real, $cas);
checar('cadeia boa: devolve a chave', $chave !== null, true);
checar('sem CA confiavel: recusa', alexa_certificado_ok($cadeia, $agora_real, []), null);
checar('SAN de outro site: recusa', alexa_certificado_ok($outro_pem . $ca_pem, $agora_real, $cas), null);
checar('certificado vencido: recusa', alexa_certificado_ok($cadeia, $agora_real + 40 * 86400, $cas), null);
checar('ainda nao valido: recusa', alexa_certificado_ok($cadeia, $agora_real - 86400, $cas), null);
checar('lixo: recusa', alexa_certificado_ok('nao e pem', $agora_real, $cas), null);

// CA que nao e a que assinou a folha.
$outra_ca_chave = openssl_pkey_new($op);
$outra_ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Outra CA'], $outra_ca_chave, $op), null, $outra_ca_chave, 30,
    $op + ['x509_extensions' => 'v3_ca']);
openssl_x509_export($outra_ca, $outra_ca_pem);
file_put_contents($dir . '/outra.pem', $outra_ca_pem);
checar('CA errada: recusa', alexa_certificado_ok($cadeia, $agora_real, [$dir . '/outra.pem']), null);

$corpo = '{"version":"1.0","request":{"type":"LaunchRequest"}}';
openssl_sign($corpo, $assinatura, $folha_chave, OPENSSL_ALGO_SHA256);
$b64 = base64_encode($assinatura);
checar('assinatura confere', alexa_assinatura_ok($corpo, $b64, $chave), true);
checar('corpo mexido: recusa', alexa_assinatura_ok($corpo . ' ', $b64, $chave), false);
openssl_sign($corpo, $assinatura_outra, $outro_chave, OPENSSL_ALGO_SHA256);
checar('assinada por outra chave: recusa', alexa_assinatura_ok($corpo, base64_encode($assinatura_outra), $chave), false);
checar('assinatura nao base64: recusa', alexa_assinatura_ok($corpo, '***', $chave), false);
checar('assinatura vazia: recusa', alexa_assinatura_ok($corpo, '', $chave), false);

foreach (glob($dir . '/*') as $f) { unlink($f); }
rmdir($dir);

// ---------------------------------------------------------------------
// Dinheiro e listas falados
// ---------------------------------------------------------------------
checar('reais: centavos', alexa_reais(12.5), '12 reais e 50 centavos');
checar('reais: um real', alexa_reais(1.0), '1 real');
checar('reais: um centavo', alexa_reais(3.01), '3 reais e 1 centavo');
checar('reais: so centavos', alexa_reais(0.5), '50 centavos');
checar('reais: zero', alexa_reais(0.0), '0 reais');
checar('reais: mil sem centavos', alexa_reais(1234.56), '1.235 reais');
checar('reais: arredonda para mil', alexa_reais(999.996), '1.000 reais');
checar('reais: 99,999 vira 100', alexa_reais(99.999), '100 reais');
checar('lista: um', alexa_lista(['a']), 'a');
checar('lista: tres', alexa_lista(['a', 'b', 'c']), 'a, b e c');
checar('nome: minusculas', alexa_nome_produto('  REFRIG  COCA COLA 350ML '), 'refrig coca cola 350ml');

// ---------------------------------------------------------------------
// De quando sao os dados
// ---------------------------------------------------------------------
$agora = strtotime('2026-11-11 15:00:00');
checar('frescor: hoje, recente',
    alexa_frescor('2026-11-11 14:20:00', $agora), ['texto' => 'Dados atualizados às 14 e 20.', 'velho' => false]);
checar('frescor: hora cheia', alexa_frescor('2026-11-11 14:00:00', $agora)['texto'], 'Dados atualizados às 14 horas.');
checar('frescor: velho pede o app',
    alexa_frescor('2026-11-11 09:05:00', $agora), ['texto' => 'Dados atualizados às 9 e 5. Abra o app para atualizar.', 'velho' => true]);
checar('frescor: outro dia',
    alexa_frescor('2026-11-10 22:30:00', $agora)['texto'], 'Dados atualizados em 10 de novembro, às 22 e 30. Abra o app para atualizar.');
checar('frescor: nunca sincronizou', alexa_frescor(null, $agora)['velho'], true);

// ---------------------------------------------------------------------
// Periodo falado (hoje: quarta, 11/11/2026; semana ISO 46 = 09 a 15/11)
// ---------------------------------------------------------------------
$hoje = '2026-11-11';
checar('periodo: agora', alexa_periodo('PRESENT_REF', $hoje), ['de' => $hoje, 'ate' => $hoje, 'rotulo' => 'hoje']);
checar('periodo: vazio e hoje', alexa_periodo('', $hoje)['rotulo'], 'hoje');
checar('periodo: ontem', alexa_periodo('2026-11-10', $hoje), ['de' => '2026-11-10', 'ate' => '2026-11-10', 'rotulo' => 'ontem']);
checar('periodo: dia antigo', alexa_periodo('2026-11-05', $hoje)['rotulo'], 'em 5 de novembro');
checar('periodo: "segunda" futura volta 7 dias',
    alexa_periodo('2026-11-16', $hoje), ['de' => '2026-11-09', 'ate' => '2026-11-09', 'rotulo' => 'em 9 de novembro']);
checar('periodo: futuro distante', alexa_periodo('2026-12-25', $hoje), null);
checar('periodo: semana passada',
    alexa_periodo('2026-W45', $hoje), ['de' => '2026-11-02', 'ate' => '2026-11-08', 'rotulo' => 'na semana passada']);
checar('periodo: esta semana corta em hoje',
    alexa_periodo('2026-W46', $hoje), ['de' => '2026-11-09', 'ate' => '2026-11-11', 'rotulo' => 'nesta semana']);
checar('periodo: semana antiga', alexa_periodo('2026-W40', $hoje)['rotulo'], 'na semana de 28 de setembro');
checar('periodo: fim de semana passado',
    alexa_periodo('2026-W45-WE', $hoje), ['de' => '2026-11-07', 'ate' => '2026-11-08', 'rotulo' => 'no fim de semana passado']);
checar('periodo: fim de semana que nao chegou', alexa_periodo('2026-W46-WE', $hoje), null);
checar('periodo: mes passado',
    alexa_periodo('2026-10', $hoje), ['de' => '2026-10-01', 'ate' => '2026-10-31', 'rotulo' => 'em outubro']);
checar('periodo: este mes corta em hoje',
    alexa_periodo('2026-11', $hoje), ['de' => '2026-11-01', 'ate' => '2026-11-11', 'rotulo' => 'neste mês']);
checar('periodo: "dezembro" volta um ano',
    alexa_periodo('2026-12', $hoje), ['de' => '2025-12-01', 'ate' => '2025-12-31', 'rotulo' => 'em dezembro de 2025']);
checar('periodo: este ano', alexa_periodo('2026', $hoje), ['de' => '2026-01-01', 'ate' => $hoje, 'rotulo' => 'neste ano']);
checar('periodo: ano que vem', alexa_periodo('2027', $hoje), null);
checar('periodo: estacao nao entende', alexa_periodo('2026-WI', $hoje), null);
checar('dias: um dia', alexa_dias('2026-11-10', '2026-11-10'), 1);
checar('dias: outubro', alexa_dias('2026-10-01', '2026-10-31'), 31);

// ---------------------------------------------------------------------
// Falas
// ---------------------------------------------------------------------
checar('hoje: com comparacao',
    alexa_fala_hoje(1234.5, 87, 1100.0, 'Dados atualizados às 14 e 20.'),
    'Hoje o mercadinho vendeu 1.235 reais em 87 vendas. 12% a mais que no mesmo horário da semana passada. Dados atualizados às 14 e 20.');
checar('hoje: menos que semana passada',
    alexa_fala_hoje(500.0, 30, 1000.0, 'X.'),
    'Hoje o mercadinho vendeu 500 reais em 30 vendas. 50% a menos que no mesmo horário da semana passada. X.');
checar('hoje: sem semana passada', alexa_fala_hoje(10.0, 1, null, 'X.'), 'Hoje o mercadinho vendeu 10 reais em 1 venda. X.');
checar('hoje: sem venda', alexa_fala_hoje(0.0, 0, 100.0, 'X.'), 'Hoje ainda não tem venda registrada. X.');

checar('periodo: um dia', alexa_fala_periodo('ontem', 820.0, 52, 1), 'Ontem o mercadinho vendeu 820 reais em 52 vendas.');
checar('periodo: com media',
    alexa_fala_periodo('em outubro', 31000.0, 2100, 31),
    'Em outubro o mercadinho vendeu 31.000 reais em 2100 vendas. Média de 1.000 reais por dia.');
checar('periodo: vazio', alexa_fala_periodo('em 5 de novembro', 0.0, 0, 1), 'Em 5 de novembro não tem venda registrada.');

$prog = meta_progresso(12000.0, 30000.0, 11, 30) + [];
checar('meta: no caminho', alexa_fala_meta($prog, 'novembro'),
    'A meta de novembro é 30.000 reais. Até agora entraram 12.000 reais, 40% da meta. No ritmo atual, o mês fecha em 32.727 reais. '
    . 'Para bater, faltam 18.000 reais, ou 947 reais e 37 centavos por dia nos 19 dias que restam.');
checar('meta: batida', alexa_fala_meta(meta_progresso(31000.0, 30000.0, 25, 30), 'novembro'),
    'A meta de novembro é 30.000 reais. Até agora entraram 31.000 reais, 103% da meta. A meta já foi batida!');
checar('meta: ultimo dia', alexa_fala_meta(meta_progresso(29000.0, 30000.0, 30, 30), 'novembro'),
    'A meta de novembro é 30.000 reais. Até agora entraram 29.000 reais, 97% da meta. No ritmo atual, o mês fecha em 29.000 reais. '
    . 'Faltam 1.000 reais para bater hoje.');
checar('meta: sem meta', alexa_fala_meta(meta_progresso(12000.0, 0.0, 11, 30), 'novembro'),
    'Não tem meta de faturamento cadastrada para novembro. O mês está em 12.000 reais, e no ritmo atual fecha em 32.727 reais.');

checar('mais vendidos: tres', alexa_fala_mais_vendidos('hoje', [
    ['descricao' => 'COCA COLA LATA', 'quantidade' => 12, 'total' => 60.0],
    ['descricao' => 'AGUA 500ML', 'quantidade' => 1, 'total' => 3.5],
    ['descricao' => 'PAO DE QUEIJO', 'quantidade' => 2.5, 'total' => 25.0],
    ['descricao' => 'QUARTO', 'quantidade' => 1, 'total' => 1.0],
]), 'Hoje o que mais vendeu foi coca cola lata, 12 unidades, 60 reais. '
  . 'Depois vêm agua 500ml, 1 unidade, 3 reais e 50 centavos e pao de queijo, 2,5 unidades, 25 reais.');
checar('mais vendidos: um', alexa_fala_mais_vendidos('ontem', [['descricao' => 'X', 'quantidade' => 3, 'total' => 9.0]]),
    'Ontem o que mais vendeu foi x, 3 unidades, 9 reais.');
checar('mais vendidos: nada', alexa_fala_mais_vendidos('hoje', []), 'Hoje não tem venda registrada.');

$hoje = '2026-11-11';
$itens = [
    ['descricao' => 'IOGURTE', 'validade' => '2026-11-09'],
    ['descricao' => 'IOGURTE', 'validade' => '2026-11-10'], // mesmo produto em outro PDV
    ['descricao' => 'LEITE', 'validade' => '2026-11-11'],
    ['descricao' => 'PAO', 'validade' => '2026-11-12'],
    ['descricao' => 'QUEIJO', 'validade' => '2026-11-16'],
    ['descricao' => 'SUCO', 'validade' => '2026-11-30'],
];
checar('validade: vencido e semana',
    alexa_fala_validade(loja_faixas_validade($itens, $hoje), $hoje),
    'Tem 1 produto vencido em estoque: iogurte. Nos próximos 7 dias vencem 3 produtos: leite vence hoje, pao vence amanhã e queijo vence em 5 dias.');
checar('validade: um so na semana',
    alexa_fala_validade(loja_faixas_validade([['descricao' => 'PAO', 'validade' => '2026-11-12']], $hoje), $hoje),
    'Nos próximos 7 dias, pao vence amanhã.');
checar('validade: so o mes',
    alexa_fala_validade(loja_faixas_validade([['descricao' => 'SUCO', 'validade' => '2026-11-30']], $hoje), $hoje),
    'Nenhum produto vencido nem vencendo esta semana. 1 produto vence nos próximos 30 dias.');
checar('validade: nada', alexa_fala_validade([], $hoje), 'Nenhum produto vencido nem vencendo esta semana.');
$muitos = [];
foreach (range(1, 8) as $i) {
    $muitos[] = ['descricao' => 'ITEM ' . $i, 'validade' => '2026-11-01'];
}
checar('validade: corta em cinco nomes',
    alexa_fala_validade(loja_faixas_validade($muitos, $hoje), $hoje),
    'Tem 8 produtos vencidos em estoque: item 1, item 2, item 3, item 4 e item 5, e mais 3.');

// ---------------------------------------------------------------------
// Compra num dia e horario
// ---------------------------------------------------------------------
checar('hora: 15:30', alexa_hora('15:30'), '15:30');
checar('hora: 9:05', alexa_hora('9:05'), '09:05');
checar('hora: periodo do dia', alexa_hora('AF'), null);
checar('hora: vazio', alexa_hora(''), null);
checar('hora falada: cheia', alexa_hora_falada('15:00'), '15 horas');
checar('hora falada: minutos', alexa_hora_falada('15:32'), '15 e 32');
$v1 = ['data_hora' => '2026-11-10 15:32:10', 'valor_pago' => '18.50', 'forma_pagamento' => 'PIX'];
$it = [
    ['descricao' => 'COCA COLA LATA', 'quantidade' => '2'],
    ['descricao' => 'PAO DE QUEIJO', 'quantidade' => '1'],
];
checar('compra: uma venda',
    alexa_fala_compra([$v1], $it, 'ontem', '15:30'),
    'Às 15 e 32 ontem, uma venda de 18 reais e 50 centavos no pix: 2 coca cola lata e 1 pao de queijo.');
checar('compra: varias na janela',
    alexa_fala_compra([$v1, $v1], $it, 'ontem', '15:30'),
    'Às 15 e 32 ontem, uma venda de 18 reais e 50 centavos no pix: 2 coca cola lata e 1 pao de queijo.'
    . ' Tem mais 1 venda nessa janela. Diga o horário mais exato para escolher outra.');
checar('compra: nenhuma',
    alexa_fala_compra([], [], 'ontem', '15:30'),
    'Não achei nenhuma venda perto das 15 e 30 ontem, numa janela de 10 minutos.');
checar('compra: sem itens',
    alexa_fala_compra([$v1], [], 'hoje', '15:30'),
    'Às 15 e 32 hoje, uma venda de 18 reais e 50 centavos no pix, mas sem a lista de itens.');
$muitos = [];
foreach (range(1, 7) as $i) { $muitos[] = ['descricao' => 'ITEM ' . $i, 'quantidade' => '1']; }
checar('compra: corta em cinco itens',
    alexa_fala_compra([$v1], $muitos, 'hoje', '15:30'),
    'Às 15 e 32 hoje, uma venda de 18 reais e 50 centavos no pix: 1 item 1, 1 item 2, 1 item 3, 1 item 4 e 1 item 5, e mais 2.');

// ---------------------------------------------------------------------
// Despacho dos pedidos
// ---------------------------------------------------------------------
$vistos = [];
$fontes = [
    'hoje'          => static function () use (&$vistos) { $vistos[] = 'hoje'; return 'HOJE'; },
    'periodo'       => static function (array $p) use (&$vistos) { $vistos[] = 'periodo ' . $p['de']; return 'PERIODO'; },
    'meta'          => static function () use (&$vistos) { $vistos[] = 'meta'; return 'META'; },
    'mais_vendidos' => static function (array $p) use (&$vistos) { $vistos[] = 'mais ' . $p['de']; return 'MAIS'; },
    'validade'      => static function () use (&$vistos) { $vistos[] = 'validade'; return 'VALIDADE'; },
    'compra'        => static function (array $p, string $h) use (&$vistos) { $vistos[] = 'compra ' . $p['de'] . ' ' . $h; return 'COMPRA'; },
];
$pedir = static function (string $intent, array $slots = []) use ($fontes, $hoje): array {
    $s = [];
    foreach ($slots as $k => $v) {
        $s[$k] = ['name' => $k, 'value' => $v];
    }
    return alexa_atender(['request' => ['type' => 'IntentRequest', 'intent' => ['name' => $intent, 'slots' => $s]]], $fontes, $hoje);
};
$fala = static fn (array $r) => $r['response']['outputSpeech']['text'] ?? null;

$r = alexa_atender(['request' => ['type' => 'LaunchRequest']], $fontes, $hoje);
checar('abrir: fala de hoje e pergunta', $fala($r), 'HOJE O que mais você quer saber?');
checar('abrir: sessao aberta', $r['response']['shouldEndSession'], false);
checar('abrir: card sem a pergunta', $r['response']['card']['content'], 'HOJE');

$r = $pedir('VendasHojeIntent');
checar('hoje: fala', $fala($r), 'HOJE');
checar('hoje: encerra', $r['response']['shouldEndSession'], true);
checar('periodo: ontem', $fala($pedir('VendasPeriodoIntent', ['periodo' => '2026-11-10'])), 'PERIODO');
checar('periodo: hoje vai para a fala de hoje', $fala($pedir('VendasPeriodoIntent', ['periodo' => '2026-11-11'])), 'HOJE');
checar('periodo: sem entender', $fala($pedir('VendasPeriodoIntent', ['periodo' => '2026-SU'])),
    'Não entendi o período. Tente ontem, semana passada ou o nome do mês.');
checar('mais vendidos: sem periodo e hoje', $fala($pedir('MaisVendidosIntent')), 'MAIS');
checar('meta', $fala($pedir('MetaIntent')), 'META');
checar('validade', $fala($pedir('ValidadeIntent')), 'VALIDADE');
checar('compra: dia e hora', $fala($pedir('CompraIntent', ['dia' => '2026-11-10', 'hora' => '15:30'])), 'COMPRA');
checar('compra: sem dia vale hoje', $fala($pedir('CompraIntent', ['hora' => '09:05'])), 'COMPRA');
checar('compra: sem hora pergunta', $pedir('CompraIntent', ['dia' => '2026-11-10'])['response']['shouldEndSession'], false);
checar('compra: periodo do dia pergunta', $fala($pedir('CompraIntent', ['hora' => 'AF'])),
    'Preciso do horário da compra, por exemplo: às 15 e 30. Qual o horário?');
checar('compra: semana nao e dia', str_starts_with((string) $fala($pedir('CompraIntent', ['dia' => '2026-W45', 'hora' => '15:30'])), 'Não entendi o dia'), true);
checar('consultas chamadas', $vistos,
    ['hoje', 'hoje', 'periodo 2026-11-10', 'hoje', 'mais 2026-11-11', 'meta', 'validade',
     'compra 2026-11-10 15:30', 'compra 2026-11-11 09:05']);
checar('ajuda: sessao aberta', $pedir('AMAZON.HelpIntent')['response']['shouldEndSession'], false);
checar('parar', $fala($pedir('AMAZON.StopIntent')), 'Até mais!');
checar('fallback: explica', str_starts_with((string) $fala($pedir('AMAZON.FallbackIntent')), 'Isso eu ainda não sei'), true);
checar('sessao encerrada: resposta vazia',
    json_encode(alexa_atender(['request' => ['type' => 'SessionEndedRequest']], $fontes, $hoje)), '{"version":"1.0","response":{}}');

printf("%d ok, %d falharam\n", $ok, $falhou);
exit($falhou ? 1 : 0);
