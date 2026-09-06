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
