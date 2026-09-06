<?php
declare(strict_types=1);

/*
 * Testa a conta da barra de progresso: percentual, o que fazer sem saber o
 * total e quando dar o fluxo por perdido. Nada aqui toca o MySQL.
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/sync.php';

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

/** Uma linha de sync_estado como o banco devolve. */
function linha(array $extra = []): array
{
    return array_merge([
        'fonte'         => 'vendas',
        'status'        => 'rodando',
        'lote'          => 7,
        'lotes'         => 25,
        'itens'         => 3500,
        'mensagem'      => null,
        'iniciado_em'   => '2026-09-06 18:00:00',
        'atualizado_em' => '2026-09-06 18:00:20',
    ], $extra);
}

$agora = '2026-09-06 18:00:30';

// ------------------------------------------------------------- progresso
$e = sync_formatar(linha(), $agora);
checar('percentual de 7 em 25', $e['pct'], 28);
checar('segue rodando', $e['status'], 'rodando');
checar('marca que esta rodando', $e['rodando'], true);
checar('conta os itens', $e['itens'], 3500);

// Ultimo lote fecha em 100%, mesmo que o status ainda diga rodando.
checar('ultimo lote fecha', sync_formatar(linha(['status' => 'ok', 'lote' => 25]), $agora)['pct'], 100);
checar('ok nao esta mais rodando', sync_formatar(linha(['status' => 'ok']), $agora)['rodando'], false);

// Sem saber o total nao da para calcular percentual: a tela usa barra
// indeterminada em vez de mostrar numero inventado.
$semTotal = sync_formatar(linha(['lotes' => 0, 'lote' => 0]), $agora);
checar('sem total nao ha percentual', $semTotal['pct'], null);
checar('mas continua rodando', $semTotal['rodando'], true);

// Lote maior que o total (reenvio) nao pode passar de 100%.
checar('nunca passa de 100', sync_formatar(linha(['lote' => 30]), $agora)['pct'], 100);

// ---------------------------------------------------------------- estados
checar('erro nao fica rodando', sync_formatar(linha(['status' => 'erro']), $agora)['rodando'], false);
checar('erro leva a mensagem',
    sync_formatar(linha(['status' => 'erro', 'mensagem' => 'token invalido']), $agora)['mensagem'],
    'token invalido');

// Fluxo que parou de dar noticia vira "perdido": a barra travada em 28% para
// sempre faria o dono do app esperar o que nao vem.
$mudo = sync_formatar(linha(['atualizado_em' => '2026-09-06 17:45:00']), $agora);
checar('sem noticia ha 15 min esta perdido', $mudo['status'], 'perdido');
checar('perdido nao esta rodando', $mudo['rodando'], false);
// Dentro do teto continua rodando.
checar('9 minutos ainda e rodando',
    sync_formatar(linha(['atualizado_em' => '2026-09-06 17:51:30']), $agora)['status'], 'rodando');
// Terminado ha muito tempo continua terminado, nao vira perdido.
checar('ok antigo continua ok',
    sync_formatar(linha(['status' => 'ok', 'atualizado_em' => '2026-09-05 10:00:00']), $agora)['status'], 'ok');

// Nunca sincronizou: a tela nem mostra a barra.
$nada = sync_formatar(null);
checar('sem linha o status e parado', $nada['status'], 'parado');
checar('sem linha nao esta rodando', $nada['rodando'], false);
checar('sem linha o percentual e zero', $nada['pct'], 0);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
