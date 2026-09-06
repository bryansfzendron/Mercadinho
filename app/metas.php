<?php
declare(strict_types=1);

/**
 * Metas do mes.
 *
 * Guardadas na mesma tabela dos parametros de custo — sao a mesma coisa do
 * ponto de vista do banco: um punhado de numeros que o dono ajusta. O que a
 * tela mostra e o progresso, o ritmo e quanto falta por dia, que e a pergunta
 * de quem olha meta no meio do mes.
 */

/** Metas padrao: zero e "sem meta", e a tela convida a definir. */
function metas_padrao(): array
{
    return [
        'meta_faturamento' => 0.0,
        'meta_lucro'       => 0.0,
    ];
}

/**
 * Progresso de uma meta no mes corrente.
 *
 * @param float $realizado o que ja entrou
 * @param float $meta      o alvo do mes (0 = sem meta definida)
 * @param int   $corridos  dias ja vividos no mes, incluindo hoje
 * @param int   $no_mes    dias que o mes tem
 */
function meta_progresso(float $realizado, float $meta, int $corridos, int $no_mes): array
{
    $corridos = max(1, $corridos);
    $no_mes   = max($corridos, $no_mes);

    $ritmo    = $realizado / $corridos;
    $restam   = $no_mes - $corridos;
    $falta    = max(0.0, $meta - $realizado);

    return [
        'realizado' => $realizado,
        'meta'      => $meta,
        'tem_meta'  => $meta > 0,
        'pct'       => $meta > 0 ? $realizado / $meta * 100 : 0.0,
        'ritmo'     => $ritmo,
        // Projecao pelo ritmo atual: se o mes seguir como esta ate aqui.
        'projecao'  => $ritmo * $no_mes,
        'falta'     => $falta,
        'restam'    => $restam,
        // Quanto precisa entrar por dia no que sobra do mes. Sem dia sobrando,
        // o que falta e para hoje.
        'por_dia'   => $restam > 0 ? $falta / $restam : $falta,
        'batida'    => $meta > 0 && $realizado >= $meta,
    ];
}

/** Dias corridos e dias do mes da data dada (padrao: hoje). */
function metas_dias(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    return [(int) date('j', strtotime($hoje)), (int) date('t', strtotime($hoje))];
}

/**
 * Gera breakdown diario de meta vs realizado para um mes.
 *
 * @param float $meta_mes       Meta total do mes
 * @param float $realizado_mes  O que ja realizou no mes (ate hoje)
 * @param string $de            Inicio do periodo (YYYY-MM-DD)
 * @param string $ate           Fim do periodo (YYYY-MM-DD)
 * @param int|null $pdv_id      PDV para filtrar (null = todos)
 * @return array{diario:array, resumo:array}
 */
function metas_breakdown_diario(float $meta_mes, float $realizado_mes, string $de, string $ate, ?int $pdv_id = null): array
{
    $inicio = new DateTimeImmutable($de);
    $fim    = new DateTimeImmutable($ate);
    $hoje   = new DateTimeImmutable(date('Y-m-d'));

    // Busca vendas por dia no periodo
    $f = ['de' => $de, 'ate' => $ate, 'agrupar' => 'dia'];
    if ($pdv_id) {
        $f['pdv_id'] = $pdv_id;
    }
    $r = vendas_relatorio($f);

    // Mapa dia -> receita
    $por_dia = [];
    foreach ($r['linhas'] as $l) {
        $por_dia[$l['grupo']] = (float) $l['receita'];
    }

    $dias_mes = [];
    $iter = $inicio;
    while ($iter <= $fim) {
        $dias_mes[] = $iter->format('Y-m-d');
        $iter = $iter->modify('+1 day');
    }
    $total_dias = count($dias_mes);

    // Meta diaria base (meta_mes / total_dias)
    $meta_diaria_base = $total_dias > 0 ? $meta_mes / $total_dias : 0;

    $diario = [];
    $acum_meta = 0.0;
    $acum_real = 0.0;
    $deficit_acumulado = 0.0;

    foreach ($dias_mes as $index => $dia_str) {
        $dia_num = (int) date('j', strtotime($dia_str));
        $passado = new DateTimeImmutable($dia_str) <= $hoje;

        $realizado_dia = $por_dia[$dia_str] ?? 0.0;

        // Meta do dia = base + rateio do deficit acumulado ate ontem
        $dias_restantes = $total_dias - $index;
        $meta_dia = $meta_diaria_base;
        if ($deficit_acumulado > 0 && $dias_restantes > 0) {
            $meta_dia += $deficit_acumulado / $dias_restantes;
        }

        $acum_meta += $meta_dia;
        $acum_real += $realizado_dia;

        $diff_dia = $realizado_dia - $meta_dia;
        $deficit_acumulado += -$diff_dia; // se negativo, aumenta deficit

        $diario[] = [
            'dia'           => $dia_num,
            'data'          => $dia_str,
            'meta_dia'      => round($meta_dia, 2),
            'realizado_dia' => round($realizado_dia, 2),
            'diff_dia'      => round($diff_dia, 2),
            'acum_meta'     => round($acum_meta, 2),
            'acum_real'     => round($acum_real, 2),
            'diff_acum'     => round($acum_real - $acum_meta, 2),
            'passado'       => $passado,
            'hoje'          => $dia_str === date('Y-m-d'),
        ];
    }

    return [
        'diario' => $diario,
        'resumo' => [
            'meta_total'    => $meta_mes,
            'realizado'     => $realizado_mes,
            'falta'         => max(0, $meta_mes - $realizado_mes),
            'dias_total'    => $total_dias,
            'dias_passados' => (int) date('j', strtotime($ate)),
            'meta_diaria_base' => round($meta_diaria_base, 2),
        ],
    ];
}
