<?php
declare(strict_types=1);

/**
 * Graficos do dashboard: HTML/CSS puro, sem SVG e sem JS.
 *
 * Nada de viewBox: um grafico de barras em SVG que estica pra largura do
 * cartao (telas diferentes, quantidade diferente de dias) escala X e Y sem
 * guardar a proporcao, e todo texto que mora dentro do SVG estica junto —
 * uma <text> vira "esticada" numa tela e normal na outra. Um flexbox com a
 * altura de cada barra em % nao tem esse problema: quem escala e o
 * navegador, do jeito que ele ja faz com qualquer outra caixa.
 *
 * Cada grafico separa o CALCULO (puro, testavel sem banco) da MONTAGEM do
 * HTML (mecanica, so formata o que o calculo ja decidiu).
 */

/**
 * A serie diaria do periodo inteiro — dias sem venda entram como zero, senao
 * o espacamento do grafico mentiria sobre quando a loja esteve parada.
 *
 * @param array<string,float> $por_dia 'Y-m-d' => receita daquele dia
 * @return array<int,array{data:string,valor:float}>
 */
function grafico_serie_diaria(array $por_dia, string $de, string $ate): array
{
    $serie = [];
    $cursor = new DateTimeImmutable($de);
    $fim = new DateTimeImmutable($ate);
    while ($cursor <= $fim) {
        $chave = $cursor->format('Y-m-d');
        $serie[] = ['data' => $chave, 'valor' => (float) ($por_dia[$chave] ?? 0.0)];
        $cursor = $cursor->modify('+1 day');
    }
    return $serie;
}

/**
 * Altura relativa (0 a 100) de cada dia e qual deles e o pico — separado da
 * montagem do HTML pra dar pra testar comparando numeros, nao string.
 *
 * Piso de 4% pra dia com venda pequena continuar visivel como barra (senao
 * um dia fraco perto de um pico gigante vira uma linha invisivel), mas dia
 * SEM venda fica em zero de verdade — nao inventa piso pra quem nao vendeu.
 *
 * @param array<int,array{data:string,valor:float}> $serie
 * @return array<int,array{data:string,valor:float,altura:float,pico:bool}>
 */
function grafico_alturas_diarias(array $serie): array
{
    $max = 0.0;
    foreach ($serie as $d) {
        $max = max($max, $d['valor']);
    }

    $indicePico = null;
    foreach ($serie as $i => $d) {
        if ($d['valor'] > 0 && ($indicePico === null || $d['valor'] > $serie[$indicePico]['valor'])) {
            $indicePico = $i;
        }
    }

    $barras = [];
    foreach ($serie as $i => $d) {
        $barras[] = [
            'data'   => $d['data'],
            'valor'  => $d['valor'],
            'altura' => $max > 0 && $d['valor'] > 0 ? max(4.0, $d['valor'] / $max * 100) : 0.0,
            'pico'   => $i === $indicePico,
        ];
    }
    return $barras;
}

/**
 * O HTML do grafico de barras diario, a partir de grafico_alturas_diarias().
 * So o dia de pico ganha rotulo direto — numero em cada barra vira ruido e
 * ninguem le; os outros dias ficam disponiveis no title (o dedo/mouse
 * revela), e o total do periodo ja esta no card de faturamento ali em cima.
 *
 * @param array<int,array{data:string,valor:float,altura:float,pico:bool}> $barras
 */
function grafico_html_barras_dia(array $barras, string $de, string $ate): string
{
    if (count($barras) < 2) {
        return '';
    }

    $html = '<div class="grafico-dia" role="img" aria-label="Faturamento por dia, de '
          . e(data_fmt($de)) . ' a ' . e(data_fmt($ate)) . '">';
    foreach ($barras as $b) {
        $classe = 'grafico-barra' . ($b['pico'] ? ' pico' : '') . ($b['valor'] <= 0 ? ' vazio' : '');
        $titulo = e(data_fmt($b['data'])) . ': ' . ($b['valor'] > 0 ? e(moeda($b['valor'])) : 'sem venda');
        $html .= '<div class="' . $classe . '" style="height:' . number_format($b['altura'], 1, '.', '') . '%" title="' . $titulo . '">';
        if ($b['pico']) {
            $html .= '<span class="grafico-rotulo">' . e(moeda_compacta($b['valor'])) . '</span>';
        }
        $html .= '</div>';
    }
    $html .= '</div><div class="grafico-eixo"><span>' . e(data_fmt($de)) . '</span><span>' . e(data_fmt($ate)) . '</span></div>';
    return $html;
}

/**
 * Barra unica segmentada, proporcional — parte-do-todo no mesmo feitio da
 * barra de armazenamento do iOS (Ajustes > Armazenamento do iPhone), em vez
 * de rosca: com poucas categorias e uma so barra, o comprimento compara
 * melhor que angulo, e da pra rotular todo mundo direto na legenda embaixo.
 *
 * Cor por forma de pagamento em ordem fixa — nunca por tamanho da fatia,
 * senao o mix mudando de mes trocaria a cor de quem decorou "aquele azul e
 * o Pix".
 *
 * @param array $por_forma linhas [forma, total] de vendas_juntar_formas()
 * @param array<string,string> $rotulos forma bruta => nome de gente
 * @param array<string,string> $cores   forma bruta => var() CSS da cor
 */
function grafico_html_pagamento(array $por_forma, array $rotulos, array $cores): string
{
    $total = 0.0;
    foreach ($por_forma as $pf) {
        $total += (float) ($pf['total'] ?? 0);
    }
    if ($total <= 0) {
        return '';
    }

    $segmentos = '';
    $legenda = '';
    foreach ($por_forma as $pf) {
        $forma = (string) ($pf['forma'] ?? '');
        $valor = (float) ($pf['total'] ?? 0);
        $pct = $valor / $total * 100;
        $cor = $cores[$forma] ?? 'var(--suave)';
        $nome = e($rotulos[$forma] ?? $forma);

        // Fatia menor que meio ponto percentual nem desenha: so some no
        // arredondamento da largura, sem sobrar um traco sem sentido.
        if ($pct >= 0.5) {
            $segmentos .= '<span class="grafico-seg" style="width:' . number_format($pct, 2, '.', '')
                . '%;background:' . e($cor) . '" title="' . $nome . ': ' . e(moeda($valor)) . ' ('
                . number_format($pct, 0) . '%)"></span>';
        }
        $legenda .= '<div class="grafico-legenda-item"><i style="background:' . e($cor) . '"></i>'
            . '<span>' . $nome . '</span><strong>' . number_format($pct, 0) . '%</strong></div>';
    }

    return '<div class="grafico-barra-segmentada">' . $segmentos . '</div>'
         . '<div class="grafico-legenda">' . $legenda . '</div>';
}
