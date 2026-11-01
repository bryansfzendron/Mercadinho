<?php
declare(strict_types=1);

/**
 * Discrepancias das notas: caixa lancada como se fosse unidade.
 *
 * A nota diz "1 CXA MAC.NISSIN LAMEN R$ 114,50", o item cai no cadastro do
 * pacote, e o app passa a achar que cada pacote custa R$ 114,50. Daí em
 * diante o custo das vendas, a margem e o preco sugerido saem errados. Esta
 * tela acha esses itens e abre a caixa em unidades com um toque, pelo mesmo
 * caminho do "Corrigir item" (nota_item_editar).
 *
 * As regras sao funcoes puras (testes/discrepancias.php); quem fala com o
 * banco so junta os numeros e chama elas.
 */

/** Tamanhos de caixa que existem de verdade, para arredondar a conta. */
const CAIXA_TAMANHOS = [2, 3, 4, 5, 6, 8, 10, 12, 15, 16, 18, 20, 24, 25, 30, 36, 40,
                        48, 50, 60, 72, 80, 96, 100, 120, 144, 150, 200];

/** Digito verificador de um GTIN a partir dos digitos sem ele. Funcao pura. */
function gtin_digito(string $sem_digito): int
{
    $soma = 0;
    $peso = 3;
    for ($i = strlen($sem_digito) - 1; $i >= 0; $i--) {
        $soma += (int) $sem_digito[$i] * $peso;
        $peso = $peso === 3 ? 1 : 3;
    }
    return (10 - $soma % 10) % 10;
}

/**
 * O codigo da unidade escondido no codigo da caixa. Funcao pura.
 *
 * O GTIN-14 de uma caixa e o EAN-13 da unidade com um digito indicador na
 * frente (1 a 8) e o verificador refeito: 1 789107900022 6 e a caixa do
 * 789107900022 9. Nem todo fabricante segue, entao isso e uma sugestao, nunca
 * uma certeza — a tela mostra o nome que o codigo tem antes de aplicar.
 */
function gtin_unidade_da_caixa(?string $gtin): ?string
{
    $d = so_digitos((string) $gtin);
    if (strlen($d) !== 14 || $d[0] < '1' || $d[0] > '8') {
        return null;
    }
    $base = substr($d, 1, 12);
    return $base . gtin_digito($base);
}

/**
 * Por que o item parece caixa lancada como unidade, ou null se nao parece.
 * Funcao pura.
 *
 * @param float  $custo     custo unitario do item (liquido)
 * @param ?float $venda     preco de venda do produto vinculado na maquina
 * @param ?float $ref       custo tipico do mesmo produto nas outras compras
 * @param ?float $conhecida unidades por caixa de quando esse produto ja foi
 *                          aberto como caixa noutra nota
 */
function discrepancia_motivo(float $custo, ?float $venda, ?float $ref, ?float $conhecida): ?string
{
    if ($conhecida !== null && $conhecida >= 2) {
        return 'Esse produto já foi aberto como caixa de ' . qtd_fmt($conhecida) . ' noutra nota.';
    }
    if ($venda !== null && $venda > 0 && $custo > $venda) {
        return 'Custou ' . moeda($custo) . ' a unidade, mas a unidade vende a ' . moeda($venda) . '.';
    }
    if ($ref !== null && $ref > 0 && $custo >= 3 * $ref) {
        return 'Custou ' . moeda($custo) . ', ' . number_format($custo / $ref, 0, ',', '.')
             . ' vezes o que costuma custar (' . moeda($ref) . ').';
    }
    return null;
}

/**
 * Quantas unidades devem vir na caixa: palpites para a pessoa tocar. Pura.
 *
 * Primeiro o que ja se sabe (a caixa aberta antes), depois o que a descricao
 * diz ("C/12", "12X350ML"), por fim a conta custo da caixa / custo tipico da
 * unidade, arredondada para os tamanhos de caixa que existem.
 *
 * @return list<int>
 */
function discrepancia_por_caixa(float $custo, ?float $ref, string $descricao, ?float $conhecida = null): array
{
    $palpites = [];
    if ($conhecida !== null && $conhecida >= 2) {
        $palpites[] = (int) round($conhecida);
    }

    $d = normalizar_texto(str_replace('/', ' C ', $descricao));
    $padroes = ['/\bC (\d{1,3})\b/', '/\b(\d{1,3}) ?X ?\d/', '/\bX ?(\d{1,3})\b/', '/\b(\d{1,3}) ?UN(D|IDADES)?\b/'];
    foreach ($padroes as $re) {
        if (preg_match($re, $d, $m) && (int) $m[1] >= 2) {
            $palpites[] = (int) $m[1];
        }
    }

    if ($ref !== null && $ref > 0 && $custo > $ref) {
        $razao = $custo / $ref;
        $perto = CAIXA_TAMANHOS;
        usort($perto, static fn(int $a, int $b): int =>
            abs(log($a / $razao)) <=> abs(log($b / $razao)));
        array_push($palpites, $perto[0], $perto[1]);
    }

    return array_slice(array_values(array_unique($palpites)), 0, 4);
}

/**
 * Pontua o quanto um nome da maquina parece o da nota. Funcao pura.
 *
 * A nota abrevia ("MAC.NISSIN LAMEN"), a maquina escreve por extenso
 * ("MACARRAO INSTANTANEO NISSIN"): cada palavra da nota conta se for o comeco
 * de alguma palavra do outro nome.
 */
function discrepancia_parecido(string $nota, string $nome): int
{
    static $ignorar = ['COM', 'SEM', 'CXA', 'CX', 'UND', 'UN', 'PCT', 'EMB', 'REFR', 'GR', 'ML', 'KG', 'LT'];
    $palavras = explode(' ', normalizar_texto($nome));
    $pontos = 0;
    foreach (array_unique(explode(' ', normalizar_texto(str_replace('.', ' ', $nota)))) as $t) {
        if (strlen($t) < 3 || ctype_digit($t) || in_array($t, $ignorar, true)) {
            continue;
        }
        foreach ($palavras as $p) {
            if (str_starts_with($p, $t)) {
                $pontos++;
                break;
            }
        }
    }
    return $pontos;
}

// ---------------------------------------------------------------------
// Banco
// ---------------------------------------------------------------------

/** Preco de venda de hoje por EAN e por produto, dos PDVs ativos. */
function discrepancia_precos(): array
{
    $por_ean = $por_produto = [];
    foreach (q('SELECT li.ean, li.produto_id, li.descricao, li.preco_venda
                  FROM loja_itens li
                  JOIN loja_pdvs p ON p.id = li.pdv_id
                 WHERE p.ativo = 1 AND p.unificado_para IS NULL AND li.preco_venda > 0') as $l) {
        $preco = (float) $l['preco_venda'];
        if ($l['ean']) {
            $por_ean[$l['ean']] ??= ['nome' => (string) $l['descricao'], 'venda' => $preco];
        }
        if ($l['produto_id']) {
            $por_produto[(int) $l['produto_id']] = max($por_produto[(int) $l['produto_id']] ?? 0.0, $preco);
        }
    }
    return [$por_ean, $por_produto];
}

/**
 * Os itens que parecem caixa lancada como unidade, de um dono (e de uma nota,
 * se for o caso). Caixa ja aberta e item marcado como certo ficam de fora.
 *
 * @return list<array> o item, com 'motivo', 'venda', 'ref' e 'conhecida'
 */
function discrepancias_itens(int $usuario_id, ?int $nota_id = null): array
{
    $itens = q(
        'SELECT i.id, i.nota_id, i.produto_id, i.descricao_original, i.quantidade, i.unidade,
                i.valor_total, i.desconto, i.valor_total_liquido, i.valor_unitario_liquido,
                i.ean_original, i.cod_interno, i.por_caixa, i.discrepancia_ok,
                n.emissao, est.nome AS loja, p.ean AS produto_ean, p.descricao AS produto_nome
           FROM itens i
           JOIN notas n ON n.id = i.nota_id
      LEFT JOIN estabelecimentos est ON est.id = n.estabelecimento_id
      LEFT JOIN produtos p ON p.id = i.produto_id
          WHERE n.usuario_id = ? AND n.status = ?
       ORDER BY n.emissao DESC, i.id',
        [$usuario_id, 'ok']
    );

    // Custo de cada compra por produto, para o "costuma custar".
    $custos = [];
    foreach ($itens as $i) {
        if ($i['produto_id'] !== null && (float) $i['valor_unitario_liquido'] > 0) {
            $custos[(int) $i['produto_id']][(int) $i['id']] = (float) $i['valor_unitario_liquido'];
        }
    }
    // Produto que ja foi caixa aberta noutra nota: quantas vinham e qual a unidade.
    $caixas = [];
    foreach (q('SELECT i.caixa_produto_id, i.por_caixa, i.produto_id
                  FROM itens i JOIN notas n ON n.id = i.nota_id
                 WHERE n.usuario_id = ? AND i.caixa_produto_id IS NOT NULL AND i.por_caixa >= 2
              ORDER BY i.id', [$usuario_id]) as $c) {
        $caixas[(int) $c['caixa_produto_id']] = ['por_caixa' => (float) $c['por_caixa'], 'unidade' => (int) $c['produto_id']];
    }
    [, $venda_por_produto] = discrepancia_precos();

    $saida = [];
    foreach ($itens as $i) {
        if ($i['por_caixa'] !== null || $i['discrepancia_ok'] !== null) {
            continue;
        }
        if ($nota_id !== null && (int) $i['nota_id'] !== $nota_id) {
            continue;
        }
        $pid = $i['produto_id'] !== null ? (int) $i['produto_id'] : 0;
        $outros = $custos[$pid] ?? [];
        unset($outros[(int) $i['id']]);
        sort($outros);
        $ref = $outros ? $outros[intdiv(count($outros), 2)] : null;
        $venda = $venda_por_produto[$pid] ?? null;
        $conhecida = $caixas[$pid] ?? null;

        $motivo = discrepancia_motivo((float) $i['valor_unitario_liquido'], $venda, $ref, $conhecida['por_caixa'] ?? null);
        if ($motivo === null) {
            continue;
        }
        $saida[] = $i + ['motivo' => $motivo, 'venda' => $venda, 'ref' => $ref, 'conhecida' => $conhecida];
    }
    return $saida;
}

/** Quantos itens a conferir: o numero do aviso nas telas de notas. */
function discrepancias_contar(int $usuario_id, ?int $nota_id = null): int
{
    try {
        return count(discrepancias_itens($usuario_id, $nota_id));
    } catch (PDOException $e) {
        return 0; // banco sem a coluna discrepancia_ok ainda: setup.php
    }
}

/**
 * Quem pode ser a unidade daquela caixa, do mais provavel ao menos.
 *
 * @param array $item linha de discrepancias_itens()
 * @param array $por_ean ean => [nome, venda] (discrepancia_precos)
 * @return list<array{ean:string, nome:string, venda:?float, origem:string}>
 */
function discrepancia_candidatos(array $item, array $por_ean): array
{
    $lista = [];
    $por = static function (?string $ean, string $origem, ?string $nome = null) use (&$lista, $por_ean): void {
        $ean = ean_normalizado($ean);
        if ($ean === null || isset($lista[$ean])) {
            return;
        }
        $nome = $por_ean[$ean]['nome']
            ?? ($nome ?: (string) (qv('SELECT descricao FROM produtos WHERE ean = ?', [$ean])
            ?: qv('SELECT nome FROM ean_nomes WHERE ean = ?', [$ean]) ?: ''));
        $lista[$ean] = ['ean' => $ean, 'nome' => $nome, 'venda' => $por_ean[$ean]['venda'] ?? null, 'origem' => $origem];
    };

    $caixa = ean_normalizado($item['ean_original'] ?? null);
    if ($item['conhecida'] !== null) {
        $por(qv('SELECT ean FROM produtos WHERE id = ?', [$item['conhecida']['unidade']]), 'aberta antes');
    }
    $por(gtin_unidade_da_caixa($caixa), 'tirado do código da caixa');
    if ($item['produto_ean'] !== null && $item['produto_ean'] !== $caixa) {
        $por($item['produto_ean'], 'cadastro vinculado hoje', $item['produto_nome']);
    }

    // Nome parecido entre o que a maquina vende.
    $pontos = [];
    foreach ($por_ean as $ean => $p) {
        $n = discrepancia_parecido((string) $item['descricao_original'] . ' ' . (string) $item['produto_nome'], $p['nome']);
        if ($n >= 2) {
            $pontos[$ean] = $n;
        }
    }
    arsort($pontos);
    foreach (array_slice(array_keys($pontos), 0, 3) as $ean) {
        $por((string) $ean, 'nome parecido');
    }

    // O codigo da caixa nao serve de unidade dela mesma.
    unset($lista[$caixa ?? '']);
    return array_values($lista);
}

/**
 * Abre de uma vez as caixas que ja foram abertas noutra nota: mesma unidade,
 * mesma quantidade. Nao ha o que decidir — e repetir a correcao que a pessoa
 * ja fez uma vez.
 *
 * @return array{0:int,1:list<string>} quantas abriu e as mensagens de falha
 */
function discrepancias_abrir_conhecidas(int $usuario_id, ?int $nota_id = null): array
{
    $abertas = 0;
    $falhas = [];
    foreach (discrepancias_itens($usuario_id, $nota_id) as $i) {
        if ($i['conhecida'] === null) {
            continue;
        }
        $ean = (string) qv('SELECT ean FROM produtos WHERE id = ?', [$i['conhecida']['unidade']]);
        $r = discrepancia_abrir((int) $i['id'], $usuario_id, $ean, (string) $i['conhecida']['por_caixa']);
        if ($r['ok']) {
            $abertas++;
        } else {
            $falhas[] = $i['descricao_original'] . ': ' . $r['msg'];
        }
    }
    return [$abertas, $falhas];
}

/** "Esta certo": o item para de aparecer como discrepancia. Respeita o dono. */
function discrepancia_dispensar(int $item_id, int $usuario_id): bool
{
    return exec_sql(
        'UPDATE itens i JOIN notas n ON n.id = i.nota_id
            SET i.discrepancia_ok = ?
          WHERE i.id = ? AND n.usuario_id = ?',
        [date('Y-m-d H:i:s'), $item_id, $usuario_id]
    ) > 0;
}

/**
 * Abre a caixa: o item vira N unidades do produto unitario, com o mesmo total
 * pago. E o "Corrigir item" da nota com os campos ja preenchidos — a caixa
 * ganha cadastro proprio pelo codigo dela, e o codigo interno da loja passa a
 * apontar para a caixa, entao a proxima nota ja cai no lugar.
 */
function discrepancia_abrir(int $item_id, int $usuario_id, string $ean_unidade, string $por_caixa): array
{
    $i = q1(
        'SELECT i.*, p.ean AS produto_ean FROM itens i
           JOIN notas n ON n.id = i.nota_id
      LEFT JOIN produtos p ON p.id = i.produto_id
          WHERE i.id = ? AND n.usuario_id = ?',
        [$item_id, $usuario_id]
    );
    if (!$i) {
        return ['ok' => false, 'msg' => 'Não encontrei esse item.', 'nota_id' => null];
    }
    $un = ean_normalizado($ean_unidade);
    if ($un === null) {
        return ['ok' => false, 'msg' => 'Escolha a unidade ou digite um código de barras válido.', 'nota_id' => (int) $i['nota_id']];
    }
    // Sem isto, um campo vazio "salvaria" o item como ja esta, sem abrir nada.
    if (num_br($por_caixa) < 2) {
        return ['ok' => false, 'msg' => 'Quantas unidades vêm na caixa? Informe 2 ou mais.', 'nota_id' => (int) $i['nota_id']];
    }
    $caixa = ean_normalizado($i['ean_original'] ?? null);
    $r = nota_item_editar((int) $i['nota_id'], $item_id, $usuario_id, [
        'descricao'   => $i['descricao_original'],
        // So o codigo da caixa entra como "codigo": vazio deixa o vinculo como esta.
        'ean'         => $caixa !== null && $caixa !== $un ? $caixa : '',
        'quantidade'  => $i['quantidade'],
        'unidade'     => $i['unidade'],
        'valor_total' => $i['valor_total'],
        'desconto'    => $i['desconto'],
        'ean_unidade' => $un,
        'por_caixa'   => $por_caixa,
    ]);
    return $r + ['nota_id' => (int) $i['nota_id']];
}
