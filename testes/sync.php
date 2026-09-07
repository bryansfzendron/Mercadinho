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

// ---------------------------------------------------- o portao do cron
// O cron roda de 5 em 5 minutos; quem decide se dispara e esta funcao.
$agora = '2026-09-06 18:00:30';

// Nunca sincronizou: pode ir.
checar('sem historico dispara', sync_motivo_para_pular(null, 5, $agora), null);

// Carga correndo nao ganha companhia.
$correndo = sync_motivo_para_pular(linha(['atualizado_em' => '2026-09-06 18:00:20']), 5, $agora);
checar('carga rodando segura o cron', is_string($correndo), true);
checar('e diz em que lote esta', str_contains((string) $correndo, 'lote 7 de 25'), true);

// Carga travada nao pode segurar a fila para sempre.
checar('carga travada libera',
    sync_motivo_para_pular(linha(['atualizado_em' => '2026-09-06 17:30:00']), 5, $agora) === null
    || !str_contains((string) sync_motivo_para_pular(linha(['atualizado_em' => '2026-09-06 17:30:00']), 5, $agora), 'rodando'),
    true);

// Terminou ha 2 minutos, intervalo de 5: espera.
$cedo = sync_motivo_para_pular(
    linha(['status' => 'ok', 'iniciado_em' => '2026-09-06 17:58:30', 'atualizado_em' => '2026-09-06 17:58:40']),
    5, $agora
);
checar('cedo demais segura', is_string($cedo), true);
checar('e diz quanto falta', str_contains((string) $cedo, 'faltam'), true);

// Terminou ha 6 minutos, intervalo de 5: vai.
checar('passado o intervalo, dispara', sync_motivo_para_pular(
    linha(['status' => 'ok', 'iniciado_em' => '2026-09-06 17:54:00', 'atualizado_em' => '2026-09-06 17:55:00']),
    5, $agora
), null);

// Estoque tem intervalo proprio: 30 min segura o que 5 liberaria.
$doze_min = linha(['status' => 'ok', 'iniciado_em' => '2026-09-06 17:48:00', 'atualizado_em' => '2026-09-06 17:49:00']);
checar('com 5 min ja podia', sync_motivo_para_pular($doze_min, 5, $agora), null);
checar('com 30 min ainda nao', is_string(sync_motivo_para_pular($doze_min, 30, $agora)), true);

// Carga que falhou nao fica de castigo alem do intervalo.
checar('erro nao bloqueia depois do intervalo', sync_motivo_para_pular(
    linha(['status' => 'erro', 'iniciado_em' => '2026-09-06 17:50:00', 'atualizado_em' => '2026-09-06 17:50:10']),
    5, $agora
), null);

// ------------------------------------------------------- batimento do cron
// Sem isto o cron e uma caixa preta: quando ele nao roda, a tela fica igual a
// quando ele roda e nao acha nada novo.
$agora = '2026-09-06 18:00:30';

$nunca = cron_formatar(null, $agora);
checar('sem batimento o cron nunca passou', $nunca['nunca'], true);
checar('e isso conta como atrasado', $nunca['atrasado'], true);
checar('sem batimento nao ha minutos', $nunca['minutos'], null);

$agorinha = cron_formatar(
    ['status' => 'ok', 'mensagem' => 'vendas disparado', 'atualizado_em' => '2026-09-06 17:57:30'],
    $agora
);
checar('passou ha 3 minutos', $agorinha['minutos'], 3);
checar('e nao esta atrasado', $agorinha['atrasado'], false);
checar('conta o que fez', $agorinha['mensagem'], 'vendas disparado');
checar('sem erro', $agorinha['erro'], false);

// Tres rodadas perdidas: ou o cron nao esta configurado, ou o PHP dele morre
// antes de chegar la.
checar('meia hora calado esta atrasado',
    cron_formatar(['status' => 'ok', 'atualizado_em' => '2026-09-06 17:30:00'], $agora)['atrasado'], true);
checar('no limite ainda nao acusa',
    cron_formatar(['status' => 'ok', 'atualizado_em' => '2026-09-06 17:40:31'], $agora)['atrasado'], false);

// Disparo que falhou aparece como erro, mesmo com o cron passando na hora: sao
// duas coisas diferentes, e a tela precisa distinguir.
$ruim = cron_formatar(
    ['status' => 'erro', 'mensagem' => 'vendas: n8n respondeu HTTP 500', 'atualizado_em' => '2026-09-06 17:58:00'],
    $agora
);
checar('disparo que falhou marca erro', $ruim['erro'], true);
checar('mas o cron passou', $ruim['atrasado'], false);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
