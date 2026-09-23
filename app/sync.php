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

/**
 * Por que NAO disparar esta fonte agora — ou null quando pode ir.
 *
 * Funcao pura: recebe a linha do banco e a hora, e nada mais. E o que o cron
 * chama a cada 5 minutos, entao ela precisa ser barata e obvia.
 *
 * Duas travas:
 *  - carga ainda correndo nao ganha companhia. Uma que travou (sem noticia
 *    ha mais de SYNC_TETO_MINUTOS) nao segura a fila para sempre;
 *  - intervalo minimo por fonte, contado do INICIO da ultima carga: sem isso
 *    um cron mal configurado martelaria a API do TouchPay.
 */
function sync_motivo_para_pular(?array $estado, int $minutos, ?string $agora = null): ?string
{
    if (!$estado) {
        return null;
    }

    $ref = $agora !== null ? strtotime($agora) : time();
    $e   = sync_formatar($estado, $agora);

    if ($e['rodando']) {
        return 'ainda rodando (lote ' . $e['lote'] . ' de ' . ($e['lotes'] ?: '?') . ')';
    }

    $inicio = strtotime((string) ($estado['iniciado_em'] ?? '')) ?: 0;
    if ($inicio > 0) {
        $faltam = $minutos * 60 - ($ref - $inicio);
        if ($faltam > 0) {
            return 'ultima carga ha menos de ' . $minutos . ' min (faltam ' . (int) ceil($faltam / 60) . ')';
        }
    }

    return null;
}

// ---------------------------------------------------------------------
// Disparar as duas fontes
// ---------------------------------------------------------------------

/**
 * As duas coletas do TouchPay: como se chamam, de quanto em quanto tempo no
 * maximo, e o que dispara cada uma.
 *
 * Mora aqui, e nao no cron, porque agora ha dois chamadores: o cron da
 * hospedagem e o puxar-para-atualizar do app. Os dois precisam da MESMA
 * trava — senao o gesto martelaria a API do TouchPay por fora do intervalo
 * que o cron respeita.
 *
 * @return array<string,array{nome:string,minutos:int,disparar:callable}>
 */
/**
 * Quem colhe: o PHP daqui ou o n8n.
 *
 * Preco, estoque e vendas passaram a ser colhidos aqui — sao duas ou tres
 * requisicoes a uma API JSON, nao a raspagem pesada que justificou o n8n no
 * comeco. A nota fiscal continua la, e continua sendo a razao de o n8n
 * existir: ali sao tres viagens a SEFAZ e 1,7 MB de HTML por cupom.
 *
 * A chave existe para a volta ser barata. Enquanto os workflows estiverem
 * apenas DESLIGADOS no n8n, um `sync_modo` = 'n8n' no config devolve o
 * comportamento antigo sem mexer em codigo. Desligar e reversivel; apagar
 * nao.
 */
function sync_modo(): string
{
    return (string) cfg('sync_modo', 'local') === 'n8n' ? 'n8n' : 'local';
}

/**
 * O puxao: atualiza TUDO que precisa, em pedacos.
 *
 * E o unico gesto de atualizar do app. Arrastar para baixo chama isto, e
 * isto cuida de preco, estoque e vendas — inclusive de reconferir uma janela
 * especifica, quando a tela diz qual (a de Vendas manda a que esta filtrada).
 *
 * Trabalha por ORCAMENTO DE TEMPO e volta dizendo se sobrou. Quem chamou
 * chama de novo enquanto sobrar. E o que mantem cada requisicao curta: a
 * hospedagem corta em 30s e a carga inicial de vendas sao ~12,5 mil
 * transacoes, que nunca caberiam numa requisicao so.
 *
 * Nao respeita intervalo minimo, de proposito: quem arrastou a tela quer
 * agora, nao daqui a 30 minutos. E nao trava em "ja esta rodando" porque,
 * com a coleta acontecendo dentro da requisicao, esse estado so sobra quando
 * uma requisicao anterior morreu no meio — travar ali deixaria o app sem
 * jeito de se atualizar. Colher duas vezes nao estraga nada: o espelho e
 * trocado por PDV e regravar venda nao duplica.
 *
 * @param int $orcamento segundos de trabalho por fonte nesta passada
 */
function sync_puxar(?string $de = null, ?string $ate = null, int $orcamento = 9): array
{
    $fontes  = [];
    $parcial = false;
    $erros   = [];

    foreach (['loja', 'vendas'] as $fonte) {
        $r = $fonte === 'loja'
            ? sync_coletar_loja([], $orcamento)
            : sync_coletar_vendas($de, $ate, $orcamento);

        $fontes[$fonte] = [
            'ok'      => (bool) ($r['ok'] ?? false),
            'erro'    => $r['erro'] ?? null,
            'parcial' => (bool) ($r['parcial'] ?? false),
        ];
        if (!empty($r['parcial'])) {
            $parcial = true;
        }
        if (!($r['ok'] ?? false) && !empty($r['erro'])) {
            $erros[] = $fonte . ': ' . $r['erro'];
        }
    }

    return [
        // `ok` fala do gesto, nao das fontes: uma fonte que falhou nao pode
        // fazer a tela parecer quebrada quando a outra atualizou.
        'ok'      => true,
        'parcial' => $parcial,
        'erro'    => $erros ? implode(' · ', $erros) : null,
        'fontes'  => $fontes,
    ];
}

/** Preco e estoque, pelo caminho que estiver valendo. */
function sync_coletar_loja(array $pos_ids = [], ?int $teto_segundos = null): array
{
    return sync_modo() === 'n8n'
        ? loja_disparar_sync($pos_ids)
        : loja_sincronizar_local($pos_ids, $teto_segundos);
}

/** Vendas, pelo caminho que estiver valendo. */
function sync_coletar_vendas(?string $de = null, ?string $ate = null,
                            ?int $teto_segundos = null): array
{
    return sync_modo() === 'n8n'
        ? vendas_disparar_sync($de, $ate)
        : vendas_sincronizar_local($de, $ate, $teto_segundos);
}

function sync_fontes(): array
{
    return [
        'vendas' => [
            'nome'     => 'vendas',
            'minutos'  => max(1, (int) cfg('cron_vendas_min', 5)),
            'disparar' => static fn (): array => sync_coletar_vendas(),
        ],
        'loja' => [
            // Preco e estoque mudam devagar e a coleta e pesada (o inventario
            // inteiro de cada PDV): nao faz sentido no mesmo ritmo das vendas.
            // Quinze minutos sao tres batidas do cron — o suficiente para uma
            // etiqueta trocada no corredor aparecer no app sem fazer o dobro
            // de consultas ao TouchPay que trinta faria.
            'nome'     => 'preços e estoque',
            'minutos'  => max(1, (int) cfg('cron_loja_min', 15)),
            'disparar' => static fn (): array => sync_coletar_loja(),
        ],
    ];
}

/**
 * Dispara o que estiver na hora de disparar.
 *
 * Devolve uma linha por fonte dizendo o que aconteceu — quem chama e que
 * decide se imprime (cron), se devolve em JSON (o gesto) ou se ignora.
 *
 * @return array<int,array{fonte:string,nome:string,pulou:bool,motivo:?string,ok:bool,erro:?string,desde:?string,ate:?string}>
 */
function sync_disparar_pendentes(bool $forcar = false, ?string $agora = null): array
{
    $saida = [];
    foreach (sync_fontes() as $fonte => $como) {
        $estado = q1('SELECT * FROM sync_estado WHERE fonte = ?', [$fonte]);
        $motivo = sync_motivo_para_pular($estado, $como['minutos'], $agora);

        if ($motivo !== null && !$forcar) {
            $saida[] = ['fonte' => $fonte, 'nome' => $como['nome'], 'pulou' => true,
                        'motivo' => $motivo, 'ok' => true, 'erro' => null,
                        'desde' => null, 'ate' => null];
            continue;
        }

        $r = $como['disparar']();
        $saida[] = [
            'fonte'  => $fonte,
            'nome'   => $como['nome'],
            'pulou'  => false,
            'motivo' => null,
            'ok'     => (bool) $r['ok'],
            'erro'   => $r['ok'] ? null : ($r['erro'] ?? 'sem detalhe'),
            'desde'  => $r['desde'] ?? null,
            'ate'    => $r['ate'] ?? null,
        ];
    }
    return $saida;
}

/** Alguma fonte foi mesmo disparada? E o que diz se vale a pena esperar. */
function sync_disparou_alguma(array $resultado): bool
{
    foreach ($resultado as $r) {
        if (!$r['pulou'] && $r['ok']) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------
// O cron
// ---------------------------------------------------------------------

/** Depois disto o cron de 5 em 5 minutos ja devia ter passado tres vezes. */
const CRON_TETO_MINUTOS = 20;

/**
 * Marca que o cron passou por aqui.
 *
 * Sem isto ele e uma caixa preta: quando nao roda, nao roda em silencio, e a
 * unica pista e uma tela que simplesmente nao muda — o que tambem acontece
 * quando ele roda e nao tem nada de novo. A linha mora na mesma tabela do
 * progresso, com fonte 'cron'.
 */
function cron_bateu(string $resumo, bool $ok = true): void
{
    $agora = date('Y-m-d H:i:s');
    exec_sql(
        'INSERT INTO sync_estado (fonte, status, lote, lotes, itens, mensagem, iniciado_em, atualizado_em)
              VALUES (?, ?, 0, 0, 0, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), mensagem = VALUES(mensagem),
              iniciado_em = VALUES(iniciado_em), atualizado_em = VALUES(atualizado_em)',
        ['cron', $ok ? 'ok' : 'erro', mb_substr($resumo, 0, 255), $agora, $agora]
    );
}

/**
 * O batimento do cron, pronto para a tela. Funcao pura.
 *
 * @param array|null $r linha de sync_estado com fonte 'cron'
 */
function cron_formatar(?array $r, ?string $agora = null): array
{
    if (!$r) {
        return [
            'nunca'    => true,
            'atrasado' => true,
            'minutos'  => null,
            'quando'   => null,
            'mensagem' => null,
            'erro'     => false,
        ];
    }

    $ref = $agora !== null ? strtotime($agora) : time();
    $min = (int) floor(max(0, $ref - (strtotime((string) ($r['atualizado_em'] ?? '')) ?: $ref)) / 60);

    return [
        'nunca'    => false,
        // Passou da hora tres vezes seguidas: ou o cron nao esta configurado,
        // ou o PHP dele morre antes de chegar aqui.
        'atrasado' => $min > CRON_TETO_MINUTOS,
        'minutos'  => $min,
        'quando'   => (string) $r['atualizado_em'],
        'mensagem' => isset($r['mensagem']) ? (string) $r['mensagem'] : null,
        'erro'     => ($r['status'] ?? '') === 'erro',
    ];
}
