<?php
declare(strict_types=1);

/**
 * Sincronização automática, para rodar no cron da Hostinger — de 5 em 5
 * minutos, apontando para este arquivo:
 *
 *   php ~/domains/bryanzendron.com.br/public_html/mercadinho/cron.php
 *
 * (a expressão do crontab está no README; ela não cabe num comentário de
 * bloco do PHP, porque a barra do "a cada 5" fecha o comentário)
 *
 * Também responde por HTTP, para quem preferir chamar de fora:
 *
 *   curl "https://mercadinho.bryanzendron.com.br/cron.php?token=SEU-CRON-TOKEN"
 *
 * Por que aqui e não um Schedule Trigger no n8n: as credenciais do TouchPay
 * moram só no config.php, e o disparo as manda no corpo. Um gatilho de
 * horário dentro do n8n não teria corpo nenhum, e a senha teria que passar a
 * viver lá dentro — que é justamente o que este desenho evita.
 *
 * Cada fonte tem seu intervalo mínimo: venda muda o tempo todo, preço e
 * estoque não. E nada dispara enquanto a carga anterior ainda está correndo.
 */

require __DIR__ . '/app/bootstrap.php';

$pelo_cli = PHP_SAPI === 'cli';
if (!$pelo_cli) {
    header('Content-Type: text/plain; charset=utf-8');

    $esperado = (string) cfg('cron_token');
    $recebido = (string) ($_GET['token'] ?? '');
    if ($esperado === '' || $esperado === 'TROQUE-ME' || !hash_equals($esperado, $recebido)) {
        http_response_code(401);
        exit("token invalido\n");
    }
}

/** Escreve uma linha do log: o cron manda por e-mail, o navegador mostra. */
function dizer(string $msg): void
{
    echo date('Y-m-d H:i:s') . '  ' . $msg . "\n";
}

$agora = date('Y-m-d H:i:s');
$forcar = isset($_GET['forcar']) || in_array('--forcar', $argv ?? [], true);

$fontes = [
    'vendas' => [
        'minutos'  => max(1, (int) cfg('cron_vendas_min', 5)),
        'disparar' => static fn (): array => vendas_disparar_sync(),
        'nome'     => 'vendas',
    ],
    'loja' => [
        // Preco e estoque mudam devagar e a coleta e pesada (o inventario
        // inteiro de cada PDV): nao faz sentido no mesmo ritmo das vendas.
        'minutos'  => max(1, (int) cfg('cron_loja_min', 30)),
        'disparar' => static fn (): array => loja_disparar_sync(),
        'nome'     => 'preços e estoque',
    ],
];

foreach ($fontes as $fonte => $cfg) {
    $estado = q1('SELECT * FROM sync_estado WHERE fonte = ?', [$fonte]);
    $motivo = sync_motivo_para_pular($estado, $cfg['minutos'], $agora);

    if ($motivo !== null && !$forcar) {
        dizer($cfg['nome'] . ': pulou — ' . $motivo);
        continue;
    }

    $r = $cfg['disparar']();
    if ($r['ok']) {
        $janela = isset($r['desde']) ? ' (' . $r['desde'] . ' a ' . $r['ate'] . ')' : '';
        dizer($cfg['nome'] . ': disparado' . $janela);
    } else {
        dizer($cfg['nome'] . ': FALHOU — ' . ($r['erro'] ?? 'sem detalhe'));
    }
}
