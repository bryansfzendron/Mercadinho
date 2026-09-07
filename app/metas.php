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
 * O plano diario da meta do mes.
 *
 * Duas colunas que respondem perguntas diferentes, e por isso sao calculadas
 * diferente:
 *
 *  - **meta acumulada** e a linha reta de referencia: `meta do mes / dias do
 *    mes x dia`. E onde voce deveria estar hoje se o mes fosse parelho, e por
 *    isso ela fecha exatamente na meta do mes no ultimo dia. E contra ela que
 *    o realizado acumulado se compara.
 *  - **meta do dia** e o plano recalculado: o que ainda falta dividido pelos
 *    dias que ainda restam. Fica para tras, ela sobe; adianta, ela desce.
 *
 * A versao anterior somava o deficit *em cima* da base e realimentava o
 * resultado no dia seguinte, entao a meta compunha sozinha: com R$ 3.000 de
 * meta e nada vendido, a meta acumulada terminava em R$ 46.500 e o dia 30
 * pedia R$ 23.300. Tambem dividia a meta pelos dias da JANELA (que para o mes
 * corrente termina hoje) em vez dos dias do MES, o que inflava a meta diaria
 * quanto mais cedo no mes voce olhasse.
 */
function metas_breakdown_diario(float $meta_mes, float $realizado_mes, string $de, string $ate, ?int $pdv_id = null): array
{
    $inicio = new DateTimeImmutable($de);
    $hoje   = new DateTimeImmutable(date('Y-m-d'));

    // Receita por dia no periodo, do mesmo relatorio que a tela de vendas usa.
    $f = ['de' => $de, 'ate' => $ate, 'agrupar' => 'dia'];
    if ($pdv_id) {
        $f['pdv_id'] = $pdv_id;
    }
    $r = vendas_relatorio($f);

    $por_dia = [];
    foreach ($r['linhas'] as $l) {
        $por_dia[(string) $l['grupo']] = (float) $l['receita'];
    }

    // O mes inteiro, nao a janela: a janela do mes corrente termina hoje, e
    // dividir a meta por ela faria a meta diaria crescer quanto mais cedo no
    // mes voce abrisse a tela. Os dias que ainda nao chegaram entram como
    // plano, que e justamente o que da para agir em cima.
    $dias_do_mes = (int) $inicio->format('t');
    $meta_diaria_base = $dias_do_mes > 0 ? $meta_mes / $dias_do_mes : 0.0;

    $diario = [];
    $acum_meta = 0.0;
    $acum_real = 0.0;

    for ($i = 0; $i < $dias_do_mes; $i++) {
        $data = $inicio->modify('+' . $i . ' day');
        $dia_str = $data->format('Y-m-d');
        $realizado_dia = $por_dia[$dia_str] ?? 0.0;

        // O que ainda falta, dividido pelos dias que ainda restam (contando
        // este). Sobrando, o que falta e negativo e a meta do dia zera.
        $restam = $dias_do_mes - $i;
        $meta_dia = $restam > 0 ? max(0.0, $meta_mes - $acum_real) / $restam : 0.0;

        // A referencia acumulada e a linha reta, senao ela nunca fecharia na
        // meta do mes.
        $acum_meta += $meta_diaria_base;
        $acum_real += $realizado_dia;

        $diario[] = [
            'dia'           => (int) $data->format('j'),
            'data'          => $dia_str,
            'meta_dia'      => round($meta_dia, 2),
            'realizado_dia' => round($realizado_dia, 2),
            'diff_dia'      => round($realizado_dia - $meta_dia, 2),
            'acum_meta'     => round($acum_meta, 2),
            'acum_real'     => round($acum_real, 2),
            'diff_acum'     => round($acum_real - $acum_meta, 2),
            'passado'       => $data < $hoje,
            'hoje'          => $dia_str === $hoje->format('Y-m-d'),
            'futuro'        => $data > $hoje,
        ];
    }

    $restam_hoje = 0;
    foreach ($diario as $l) {
        if (!$l['passado']) {
            $restam_hoje++;
        }
    }

    return [
        'diario' => $diario,
        'resumo' => [
            'meta_total'       => $meta_mes,
            'realizado'        => $realizado_mes,
            'falta'            => max(0.0, $meta_mes - $realizado_mes),
            'dias_total'       => $dias_do_mes,
            'dias_passados'    => $dias_do_mes - $restam_hoje,
            'dias_restantes'   => $restam_hoje,
            'meta_diaria_base' => round($meta_diaria_base, 2),
            // O que precisa sair por dia daqui para frente para fechar o mes.
            'precisa_por_dia'  => $restam_hoje > 0
                ? round(max(0.0, $meta_mes - $realizado_mes) / $restam_hoje, 2)
                : round(max(0.0, $meta_mes - $realizado_mes), 2),
        ],
    ];
}
