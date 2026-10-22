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
 * As metas daquele ponto de venda. Null quer dizer "este PDV nao tem meta".
 *
 * Aqui esta a unica diferenca importante em relacao as taxas, que usam a
 * mesma tabela e o mesmo mecanismo: taxa em branco HERDA o padrao, porque um
 * percentual vale igual em qualquer container. Meta em branco NAO herda —
 * herdar faria cada ponto de venda ter de bater sozinho a meta da empresa
 * inteira, e dois containers apareceriam os dois em 50% num mes em que a
 * empresa bateu exatamente o alvo.
 *
 * Entao: com meta propria, o PDV e medido contra ela; sem, a tela diz que
 * ele nao tem meta em vez de inventar uma.
 *
 * @return array{meta_faturamento:?float, meta_lucro:?float}
 */
function metas_do_pdv(int $pdv_id): array
{
    $proprias = ['meta_faturamento' => null, 'meta_lucro' => null];
    if ($pdv_id <= 0) {
        return $proprias;
    }
    foreach (custos_overrides_pdv($pdv_id) as $chave => $valor) {
        if (array_key_exists($chave, $proprias)) {
            $proprias[$chave] = (float) $valor;
        }
    }
    return $proprias;
}

/** Inauguracao de um PDV (null = sem data, conta como aberto o mes todo). */
function metas_inauguracao(int $pdv_id): ?string
{
    $l = q('SELECT inauguracao FROM loja_pdvs WHERE id = ?', [$pdv_id]);
    return $l ? data_iso($l[0]['inauguracao']) : null;
}

/**
 * Quanto do mes de `$ate` o PDV esta aberto: a fracao que multiplica a meta,
 * os dias do mes em que ja existia e os que ja correram desde que abriu.
 * Funcao pura.
 *
 * @return array{fracao:float, no_mes:int, corridos:int}
 */
function metas_abertura(?string $inauguracao, string $ate): array
{
    $ini = date('Y-m-01', strtotime($ate));
    $fim = date('Y-m-t', strtotime($ate));
    $total = (int) date('t', strtotime($ate));
    $fracao = custos_fracao_aberto($inauguracao, $ini, $fim);
    $no_mes = (int) round($fracao * $total);
    $dia = (int) date('j', strtotime($ate));
    return ['fracao' => $fracao, 'no_mes' => $no_mes, 'corridos' => max(0, $dia - ($total - $no_mes))];
}

/** Metas de cada PDV ativo no mes de `$ate`, proporcionais aos dias abertos. */
function metas_de_todos_pdvs(string $ate): array
{
    $fora = [];
    $linhas = q(
        "SELECT c.pdv_id, c.chave, c.valor, p.inauguracao FROM custos_pdv c
         JOIN loja_pdvs p ON p.id = c.pdv_id AND p.ativo = 1
         WHERE c.chave IN ('meta_faturamento', 'meta_lucro')"
    );
    foreach ($linhas as $l) {
        $f = metas_abertura(data_iso($l['inauguracao']), $ate)['fracao'];
        $fora[(int) $l['pdv_id']][(string) $l['chave']] = (float) $l['valor'] * $f;
    }
    return array_values($fora);
}

/**
 * Qual meta vale para o filtro atual. Funcao pura.
 *
 * Sem PDV escolhido vale a soma das metas dos PDVs ativos (a da empresa so
 * entra se nenhum PDV tiver meta). Com um escolhido vale a dele, e SO a dele: comparar o
 * faturamento de um container com a meta do conjunto e medir contra um alvo
 * que nunca foi dele — num mes em que a empresa bate exatamente o alvo, dois
 * containers apareceriam os dois em 50%, e nenhum dos dois estaria mal.
 *
 * PDV sem meta propria devolve zero, que o resto da tela ja entende como "sem
 * meta": mostra o realizado e a projecao, sem alvo nem barra. `propria` diz a
 * diferenca entre "nao tem meta" e "tem meta zero", que para a tela sao a
 * mesma coisa mas para a mensagem nao.
 *
 * @param array $globais  metas da empresa (custos_parametros())
 * @param array $proprias metas daquele PDV (metas_do_pdv()), com null onde nao ha
 * @param ?array $todos metas de cada PDV ativo (metas_de_todos_pdvs()); somadas quando nao ha PDV escolhido
 * @return array{faturamento:float, lucro:float, propria:bool}
 */
function metas_alvos(int $pdv_id, array $globais, array $proprias, ?array $todos = null): array
{
    if ($pdv_id <= 0) {
        // "Todos": soma as metas dos PDVs. Se nenhum tem meta, cai na da empresa.
        $fat = $luc = 0.0;
        foreach ($todos ?? [] as $m) {
            $fat += (float) ($m['meta_faturamento'] ?? 0);
            $luc += (float) ($m['meta_lucro'] ?? 0);
        }
        if ($fat > 0 || $luc > 0) {
            return ['faturamento' => $fat, 'lucro' => $luc, 'propria' => false];
        }
        return [
            'faturamento' => (float) ($globais['meta_faturamento'] ?? 0),
            'lucro'       => (float) ($globais['meta_lucro'] ?? 0),
            'propria'     => false,
        ];
    }

    return [
        'faturamento' => (float) ($proprias['meta_faturamento'] ?? 0),
        'lucro'       => (float) ($proprias['meta_lucro'] ?? 0),
        'propria'     => ($proprias['meta_faturamento'] ?? null) !== null
                      || ($proprias['meta_lucro'] ?? null) !== null,
    ];
}

/**
 * Grava a meta de um ponto de venda. Campo vazio apaga e o PDV fica sem meta.
 *
 * Mesmo mecanismo das taxas — so muda o conjunto de chaves.
 */
function metas_salvar_pdv(int $pdv_id, array $novos): int
{
    return custos_salvar_pdv($pdv_id, $novos, metas_padrao());
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
function metas_breakdown_diario(float $meta_mes, float $realizado_mes, string $de, string $ate, ?int $pdv_id = null, ?string $inauguracao = null): array
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
    // Antes da inauguracao o PDV nao tem meta: ela se reparte so pelos dias abertos.
    $abre = data_iso($inauguracao) ?? '';
    $dias_abertos = 0;
    for ($i = 0; $i < $dias_do_mes; $i++) {
        if ($inicio->modify('+' . $i . ' day')->format('Y-m-d') >= $abre) {
            $dias_abertos++;
        }
    }
    $meta_diaria_base = $dias_abertos > 0 ? $meta_mes / $dias_abertos : 0.0;

    $diario = [];
    $abertos_antes = 0;
    $acum_meta = 0.0;
    $acum_real = 0.0;

    for ($i = 0; $i < $dias_do_mes; $i++) {
        $data = $inicio->modify('+' . $i . ' day');
        $dia_str = $data->format('Y-m-d');
        $realizado_dia = $por_dia[$dia_str] ?? 0.0;

        // O que ainda falta, dividido pelos dias que ainda restam (contando
        // este). Sobrando, o que falta e negativo e a meta do dia zera.
        $aberto = $dia_str >= $abre;
        $restam = $dias_abertos - $abertos_antes;
        $abertos_antes += $aberto ? 1 : 0;
        $meta_dia = $aberto && $restam > 0 ? max(0.0, $meta_mes - $acum_real) / $restam : 0.0;

        // A referencia acumulada e a linha reta, senao ela nunca fecharia na
        // meta do mes.
        $acum_meta += $aberto ? $meta_diaria_base : 0.0;
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
