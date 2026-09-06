<?php
declare(strict_types=1);

/**
 * Progresso da importacao.
 *
 * Os dois fluxos do TouchPay sao disparados e respondem por callback, entao o
 * navegador nao ve nada acontecendo. Aqui fica o placar: o PHP marca "rodando"
 * ao disparar e cada lote que chega avanca o contador. A tela pergunta de
 * tempos em tempos e desenha a barra com numero de verdade — lote 7 de 25 —,
 * nao uma animacao fingindo que sabe.
 */

/** Depois disto, "rodando" sem noticia e fluxo que morreu no caminho. */
const SYNC_TETO_MINUTOS = 10;

/** Marca o inicio. Zera o contador porque a carga anterior nao interessa mais. */
function sync_iniciar(string $fonte): void
{
    $agora = date('Y-m-d H:i:s');
    exec_sql(
        'INSERT INTO sync_estado (fonte, status, lote, lotes, itens, mensagem, iniciado_em, atualizado_em)
              VALUES (?, ?, 0, 0, 0, NULL, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), lote = 0, lotes = 0, itens = 0,
              mensagem = NULL, iniciado_em = VALUES(iniciado_em), atualizado_em = VALUES(atualizado_em)',
        [$fonte, 'rodando', $agora, $agora]
    );
}

/**
 * Um lote chegou.
 *
 * O maior lote manda no contador: os lotes podem chegar fora de ordem, e
 * voltar o numero faria a barra andar para tras.
 */
function sync_avancar(string $fonte, int $lote, int $lotes, int $itens, ?string $erro = null): void
{
    $agora = date('Y-m-d H:i:s');
    $status = $erro !== null ? 'erro' : (($lotes > 0 && $lote >= $lotes) ? 'ok' : 'rodando');

    exec_sql(
        'INSERT INTO sync_estado (fonte, status, lote, lotes, itens, mensagem, iniciado_em, atualizado_em)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
              status   = VALUES(status),
              lote     = GREATEST(lote, VALUES(lote)),
              lotes    = GREATEST(lotes, VALUES(lotes)),
              itens    = itens + VALUES(itens),
              mensagem = VALUES(mensagem),
              atualizado_em = VALUES(atualizado_em)',
        [$fonte, $status, max(0, $lote), max(0, $lotes), max(0, $itens),
         $erro === null ? null : mb_substr($erro, 0, 255), $agora, $agora]
    );
}

/** O placar de uma fonte, pronto para a tela. */
function sync_estado(string $fonte): array
{
    $r = q1('SELECT * FROM sync_estado WHERE fonte = ?', [$fonte]);
    if (!$r) {
        return sync_formatar(null);
    }
    return sync_formatar($r);
}

/**
 * Traduz a linha do banco no que a tela precisa. Funcao pura: e onde mora a
 * conta do percentual e a decisao de dar o fluxo por perdido.
 */
function sync_formatar(?array $r, ?string $agora = null): array
{
    if (!$r) {
        return ['status' => 'parado', 'pct' => 0, 'lote' => 0, 'lotes' => 0,
                'itens' => 0, 'mensagem' => null, 'rodando' => false];
    }

    $lote  = (int) ($r['lote'] ?? 0);
    $lotes = (int) ($r['lotes'] ?? 0);
    $status = (string) ($r['status'] ?? 'parado');

    // Fluxo que parou de dar noticia nao pode ficar "rodando" para sempre: a
    // barra travaria em 40% e o dono do app ficaria esperando o que nao vem.
    if ($status === 'rodando') {
        $desde = strtotime((string) ($r['atualizado_em'] ?? '')) ?: 0;
        $ref   = $agora !== null ? strtotime($agora) : time();
        if ($desde > 0 && $ref - $desde > SYNC_TETO_MINUTOS * 60) {
            $status = 'perdido';
        }
    }

    // Sem saber quantos lotes serao, o percentual seria invencao: a tela usa
    // isso para mostrar barra indeterminada em vez de um numero mentiroso.
    $pct = $lotes > 0 ? (int) round(min(100, $lote / $lotes * 100)) : null;
    if ($status === 'ok') {
        $pct = 100;
    }

    return [
        'status'   => $status,
        'pct'      => $pct,
        'lote'     => $lote,
        'lotes'    => $lotes,
        'itens'    => (int) ($r['itens'] ?? 0),
        'mensagem' => $r['mensagem'] ?? null,
        'rodando'  => $status === 'rodando',
    ];
}
