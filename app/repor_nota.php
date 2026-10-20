<?php
declare(strict_types=1);

/**
 * Repor os itens de uma nota fiscal.
 *
 * A nota ja sabe o que o Repor precisa: o EAN, a quantidade e o custo unitario
 * LIQUIDO (desconto abatido). Falta cruzar com o que esta na maquina: o preco, a
 * necessaria, o critico, o estoque e a validade do planograma.
 *
 * Esta tela nao grava nada no TouchPay. Ela monta as linhas no formato do Repor
 * em lote (repor_ler_linhas) e cria um lote; quem confere e grava, uma linha por
 * vez e de forma idempotente, e a tela do lote.
 *
 * Funcoes puras primeiro (testes/repor_nota.php); banco e TouchPay no fim.
 */

/** Margem sobre o preco de venda, em %. Pura. Sem custo ou preco, null. */
function repor_nota_margem(?float $preco, ?float $custo): ?float
{
    if ($preco === null || $custo === null || $preco <= 0 || $custo <= 0) {
        return null;
    }
    return round(($preco - $custo) / $preco * 100, 1);
}

/** Quanto o preco novo muda em relacao ao atual, em %. Pura. */
function repor_nota_variacao(?float $atual, ?float $novo): ?float
{
    if ($atual === null || $novo === null || $atual <= 0 || $novo <= 0) {
        return null;
    }
    return round(($novo - $atual) / $atual * 100, 1);
}

/**
 * O EAN de um item da nota, do jeito que o planograma o entende. Pura.
 * O do produto (o que o app amarrou) ganha do que a nota mandou.
 */
function repor_nota_ean(array $item): string
{
    return mercado_chave($item['produto_ean'] ?? '') ?? mercado_chave($item['ean_original'] ?? '') ?? '';
}

/**
 * Cada item da nota cruzado com o planograma e o inventario do PDV. Pura.
 *
 * @param array $itens      nota_carregar()['itens']
 * @param array $planograma tp_planograma_tudo(): entradas cruas do TouchPay
 * @param array $inventario tp_inventario_tudo(): itens crus (so p/ a validade)
 * @param array $feitos     [nota_item_id => [['pdv'=>, 'quando'=>], ...]]
 * @param float $taxa       taxa inicial (a tela recalcula no navegador)
 * @param bool  $lido       false quando o TouchPay nao respondeu: dai "fora do
 *                          planograma" seria mentira, e a situacao vira 'desconhecida'
 * @return list<array>
 */
function repor_nota_itens(array $itens, array $planograma, array $inventario, array $feitos, float $taxa,
                          bool $lido = true): array
{
    // Planograma por codigo (sem o "OM" da frente) e validade por produto.
    $por_codigo = [];
    foreach ($planograma as $e) {
        $cod = mercado_chave(pg_codigo_limpo($e['productCode'] ?? ''));
        if ($cod !== null && !isset($por_codigo[$cod])) {
            $por_codigo[$cod] = pg_entrada_normalizar($e);
        }
    }
    $validade = [];
    foreach ($inventario as $i) {
        $pid = (int) ($i['productId'] ?? 0);
        $iso = pg_data_iso($i['productExpirationDate'] ?? null);
        if ($pid > 0 && $iso !== null && (!isset($validade[$pid]) || $iso < $validade[$pid])) {
            $validade[$pid] = $iso; // a que vence primeiro manda
        }
    }

    $saida = [];
    $vistos = []; // ean => item_num
    foreach ($itens as $n => $it) {
        $ean = repor_nota_ean($it);
        $qtd = (float) $it['quantidade'];
        $custo = (float) $it['valor_unitario_liquido'];
        $num = (int) ($it['item_num'] ?? 0) ?: ($n + 1);
        $atual = $ean !== '' && isset($por_codigo[$ean]) ? $por_codigo[$ean] : null;
        if ($atual !== null) {
            $atual['validade'] = $validade[$atual['produto_id']] ?? null;
            unset($atual['bruto'], $atual['imagem']);
        }

        $situacao = $ean === '' ? 'sem_ean'
            : ($atual !== null ? 'planograma' : ($lido ? 'fora' : 'desconhecida'));
        $aviso = null;
        $travado = false;
        if ($situacao === 'sem_ean') {
            $travado = true;
            $aviso = 'Sem código de barras: corrija o item na nota para poder repor.';
        } elseif (isset($vistos[$ean])) {
            $travado = true;
            $aviso = 'Mesmo produto do item ' . $vistos[$ean] . ': reponha só um deles (some as quantidades).';
        }
        if ($ean !== '' && !isset($vistos[$ean])) {
            $vistos[$ean] = $num;
        }

        $f = $feitos[(int) $it['id']] ?? [];
        $fracionada = abs($qtd - round($qtd)) > 0.0009;

        $saida[] = [
            'id'          => (int) $it['id'],
            'num'         => $num,
            'descricao'   => (string) $it['descricao_original'],
            'ean'         => $ean,
            'unidade'     => (string) ($it['unidade'] ?: 'UN'),
            'qtd'         => $qtd,
            'custo'       => $custo,
            'custo_bruto' => (float) ($it['valor_unitario'] ?? $custo),
            'desconto'    => (float) ($it['desconto'] ?? 0),
            'situacao'    => $situacao,
            'atual'       => $atual,
            'entrada'     => $fracionada ? $qtd : round($qtd),
            'fracionada'  => $fracionada,
            'sugerido'    => repor_preco(null, $custo, $taxa),
            'feitos'      => $f,
            'travado'     => $travado,
            // Marcado por padrao, menos o que ja foi reposto: e o que deixa o
            // resto da nota para o outro PDV sem refazer os primeiros.
            'marcado'     => !$travado && !$f,
            'aviso'       => $aviso,
        ];
    }
    return $saida;
}

/**
 * O formulario vira as linhas do Repor em lote. Pura.
 *
 * "Manter" nao manda preco nenhum: o app so mexe no que foi pedido. "Trocar"
 * manda o preco novo digitado (o sugerido, ja editado ou nao).
 *
 * @param array $post   $_POST['itens']: id => {sel, entrada, validade, escolha, preco, necessaria, critico}
 * @param list<array> $itens saida de repor_nota_itens() (ou so id/ean/descricao)
 * @return array{linhas:array<int,array<string,string>>, mapa:array<int,int>, erros:list<array{linha:int,msg:string}>}
 */
function repor_nota_linhas_do_post(array $post, array $itens): array
{
    $linhas = [];
    $mapa = [];   // linha => id do item da nota
    $erros = [];
    $usados = [];

    foreach ($itens as $it) {
        $p = $post[$it['id']] ?? null;
        if (!is_array($p) || empty($p['sel'])) {
            continue;
        }
        $num = (int) ($it['num'] ?? 0);
        while ($num <= 0 || isset($usados[$num])) {
            $num = ($usados ? max(array_keys($usados)) : 0) + 1;
        }
        $usados[$num] = true;

        if (($it['ean'] ?? '') === '') {
            $erros[] = ['linha' => $num, 'msg' => '"' . $it['descricao'] . '" não tem código de barras.'];
            continue;
        }
        $troca = ($p['escolha'] ?? 'manter') === 'trocar';
        $linhas[$num] = [
            'codigo'     => (string) $it['ean'],
            'descricao'  => (string) $it['descricao'],
            'entrada'    => trim((string) ($p['entrada'] ?? '')),
            'validade'   => trim((string) ($p['validade'] ?? '')),
            'preco'      => $troca ? trim((string) ($p['preco'] ?? '')) : '',
            'custo'      => '',
            'taxa'       => '',
            'necessaria' => trim((string) ($p['necessaria'] ?? '')),
            'critico'    => trim((string) ($p['critico'] ?? '')),
            'pdv'        => '',
        ];
        $mapa[$num] = (int) $it['id'];
    }
    if (!$linhas && !$erros) {
        $erros[] = ['linha' => 1, 'msg' => 'Marque pelo menos um item para repor.'];
    }
    return ['linhas' => $linhas, 'mapa' => $mapa, 'erros' => $erros];
}

/**
 * Quais itens da nota ja foram repostos, e onde. Pura.
 *
 * @param list<array{dados:string, atualizado_em:string, pdv:?string}> $rows linhas 'gravada'
 * @return array<int, list<array{pdv:string, quando:string}>>
 */
function repor_nota_feitos_mapa(array $rows): array
{
    $mapa = [];
    foreach ($rows as $r) {
        $d = json_decode((string) $r['dados'], true);
        $id = (int) ($d['nota_item_id'] ?? 0);
        if ($id > 0) {
            $mapa[$id][] = ['pdv' => (string) ($r['pdv'] ?? 'PDV'), 'quando' => (string) $r['atualizado_em']];
        }
    }
    return $mapa;
}

/**
 * O que mudou num item da nota depois que o lote dele nasceu. Pura.
 *
 * O lote guarda o codigo e a entrada do momento em que foi criado. Corrigir o
 * item na nota depois (o GTIN errado, a caixa aberta em unidades) nao chegava
 * nele, e a conferencia seguia procurando o codigo antigo no planograma.
 *
 * A entrada so acompanha a quantidade quando ainda e a que a nota sugeriu:
 * a que a pessoa digitou na mao e dela, e aqui so ganha um aviso.
 *
 * @param array  $dados  o JSON da linha do lote (entrada, nota_qtd)
 * @param string $codigo o codigo gravado na linha
 * @param array  $item   o item da nota agora: produto_ean, ean_original,
 *                       quantidade, descricao_original
 * @return ?array{codigo:string, descricao:string, dados:array} null quando nada mudou
 */
function repor_nota_linha_seguir(array $dados, string $codigo, array $item): ?array
{
    $ean = repor_nota_ean($item);
    $qtd = (float) ($item['quantidade'] ?? 0);
    $antes = isset($dados['nota_qtd']) ? (float) $dados['nota_qtd'] : null;

    // Item que perdeu o GTIN na correcao nao tem com o que procurar: fica o
    // codigo que a linha ja tinha.
    $trocou_codigo = $ean !== '' && $ean !== $codigo;
    $trocou_qtd = $antes !== null && $qtd > 0 && abs($qtd - $antes) > 0.0009;
    if (!$trocou_codigo && !$trocou_qtd) {
        return null;
    }

    $avisos = [];
    if ($trocou_codigo) {
        $avisos[] = 'Código trocado de ' . $codigo . ' para ' . $ean . ' pela correção na nota.';
    }
    $entrada = $dados['entrada'] ?? null;
    if ($trocou_qtd) {
        $sugerida = $entrada !== null && abs((float) $entrada - round($antes)) <= 0.0009;
        $inteira  = abs($qtd - round($qtd)) <= 0.0009;
        if ($sugerida && $inteira) {
            $dados['entrada'] = round($qtd);
            $avisos[] = 'Entrada ajustada para ' . qtd_fmt($qtd) . ', a quantidade nova da nota.';
        } else {
            $avisos[] = 'A quantidade na nota mudou para ' . qtd_fmt($qtd) . ': confira a entrada.';
        }
    } elseif ($antes === null && $entrada !== null) {
        // Lote de antes desta correcao: nao da para saber se a entrada era a
        // da nota ou digitada.
        $avisos[] = 'Confira a entrada (' . qtd_fmt($entrada) . '): é a de antes da correção.';
    }
    if ($qtd > 0) {
        $dados['nota_qtd'] = $qtd;
    }

    $codigo_novo = $trocou_codigo ? $ean : $codigo;
    $descricao   = mb_substr(trim((string) ($item['descricao_original'] ?? '')), 0, 255)
                   ?: (string) ($dados['descricao'] ?? '');
    $dados['codigo']    = $codigo_novo;
    $dados['descricao'] = $descricao;
    $dados['corrigido'] = implode(' ', $avisos);

    return ['codigo' => $codigo_novo, 'descricao' => $descricao, 'dados' => $dados];
}

// =====================================================================
// Banco e TouchPay
// =====================================================================

/** Os itens da nota que ja foram gravados no TouchPay por algum lote desta nota. */
function repor_nota_feitos(int $nota_id, int $usuario_id): array
{
    return repor_nota_feitos_mapa(q(
        'SELECT l.dados, l.atualizado_em, p.nome AS pdv
           FROM planograma_lote_linhas l
           JOIN planograma_lotes t ON t.id = l.lote_id
      LEFT JOIN loja_pdvs p ON p.id = l.pdv_id
          WHERE t.nota_id = ? AND t.usuario_id = ? AND l.status = ?
       ORDER BY l.atualizado_em',
        [$nota_id, $usuario_id, 'gravada']
    ));
}

/**
 * Traz para a linha do lote a correcao feita no item da nota depois que o
 * lote nasceu. Linha de planilha (sem item de nota), gravada ou no meio de
 * uma gravacao fica como esta.
 *
 * Linha com marcador de gravacao tambem fica: uma tentativa pode ter somado
 * a entrada no produto do codigo antigo, e trocar o codigo esconderia isso.
 */
function repor_linha_seguir_nota(array $l, int $usuario_id): array
{
    $item_id = (int) ($l['dados']['nota_item_id'] ?? 0);
    if ($item_id <= 0 || in_array($l['status'], ['gravada', 'gravando'], true)
        || isset($l['plano']['gravacao'])) {
        return $l;
    }
    $item = q1(
        'SELECT i.quantidade, i.descricao_original, i.ean_original, p.ean AS produto_ean
           FROM itens i
           JOIN notas n ON n.id = i.nota_id
      LEFT JOIN produtos p ON p.id = i.produto_id
          WHERE i.id = ? AND n.usuario_id = ?',
        [$item_id, $usuario_id]
    );
    // Item removido da nota: a linha segue com o que tinha.
    if (!$item) {
        return $l;
    }
    $novo = repor_nota_linha_seguir($l['dados'], $l['codigo'], $item);
    if ($novo === null) {
        return $l;
    }

    // Volta a pendente e sem plano: o que foi conferido era de outro codigo.
    exec_sql(
        'UPDATE planograma_lote_linhas
            SET codigo = ?, descricao = ?, dados = ?, status = ?, plano = NULL, mensagem = NULL
          WHERE id = ? AND status NOT IN (?, ?)',
        [$novo['codigo'], $novo['descricao'] !== '' ? $novo['descricao'] : null,
         json_encode($novo['dados'], JSON_UNESCAPED_UNICODE), 'pendente', $l['id'], 'gravada', 'gravando']
    );
    return array_merge($l, [
        'codigo' => $novo['codigo'], 'descricao' => $novo['descricao'], 'dados' => $novo['dados'],
        'status' => 'pendente', 'plano' => null, 'mensagem' => '',
    ]);
}

/**
 * O planograma e o inventario INTEIROS do PDV: duas leituras, nao uma por item.
 *
 * @return array{planograma:array, inventario:array}
 * @throws TouchPayErro
 */
function repor_nota_ler_pdv(array $pdv): array
{
    $plano = planograma_ativo($pdv);
    $inv = (int) ($pdv['inventario_id'] ?? 0);
    return [
        'planograma' => tp_planograma_tudo($plano),
        'inventario' => $inv > 0 ? tp_inventario_tudo($inv) : [],
    ];
}
