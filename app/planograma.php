<?php
declare(strict_types=1);

/**
 * Repor a gondola: bipar o produto e mexer no planograma do ponto de venda.
 *
 * A cascata tem tres degraus, e cada um so existe porque o de cima nao
 * respondeu:
 *
 *  1. o produto esta no planograma ativo    -> altera preco, necessaria,
 *     critico e estoque;
 *  2. nao esta no planograma mas esta no cadastro -> inclui no planograma e
 *     ja deixa os mesmos campos;
 *  3. nao esta em lugar nenhum -> a tela diz isso e para. Cadastrar produto
 *     novo continua sendo no painel, na mao: aqui falta foto, categoria,
 *     unidade, tributacao — coisa que ninguem preenche de pe no corredor.
 *
 * O bipe e resolvido PRIMEIRO no espelho local (loja_itens). Nao e atalho de
 * velocidade: o planograma do TouchPay so tem `productCode`, e parte dos
 * codigos vem com "OM" grudado na frente ("OM7896007811021"). O EAN de
 * verdade so existe do lado do inventario, que e justamente o que o espelho
 * ja guarda. Resolvendo aqui, a busca la vai com o productId na mao.
 */

/**
 * O codigo sem o "OM" da frente. Funcao pura.
 *
 * Mesma regra do coletor (n8n/codigo/tp-03-coletar.js): se as duas letras
 * saiam e sobra um codigo de barras, era prefixo deles.
 */
function pg_codigo_limpo($valor): string
{
    $texto = trim((string) ($valor ?? ''));
    return preg_match('/^OM(\d{6,14})$/i', $texto, $m) ? $m[1] : $texto;
}

/**
 * A lista de itens de uma resposta do TouchPay. Funcao pura.
 *
 * Eles devolvem a mesma coisa de tres jeitos conforme o endpoint: dentro de
 * `entries.items` (planograma), dentro de `items` (cadastro, paginado) ou o
 * array pelado. Adivinhar no lugar de cada chamada espalharia o mesmo `??`
 * por seis funcoes.
 */
function pg_itens($resposta): array
{
    if (!is_array($resposta)) {
        return [];
    }
    foreach ([['entries', 'items'], ['items'], ['data']] as $caminho) {
        $no = $resposta;
        foreach ($caminho as $chave) {
            $no = is_array($no) && isset($no[$chave]) ? $no[$chave] : null;
        }
        if (is_array($no)) {
            return $no;
        }
    }
    // Array pelado: so vale se for lista, senao e um objeto solto.
    return isset($resposta[0]) ? $resposta : [];
}

/**
 * Qual dos itens devolvidos e o que foi bipado. Funcao pura.
 *
 * A busca deles e por pedaco de texto, entao "789" volta meia gondola e
 * mesmo o EAN inteiro pode trazer o produto irmao. O productId ganha de tudo
 * quando o espelho local soube dizer qual e; sem ele, vale o codigo igual —
 * nunca o primeiro da lista, que e como se troca o preco do produto errado.
 */
function pg_casar(array $itens, ?int $produto_id, string $ean): ?array
{
    if ($produto_id !== null && $produto_id > 0) {
        foreach ($itens as $item) {
            if ((int) ($item['productId'] ?? 0) === $produto_id) {
                return $item;
            }
        }
    }
    if ($ean !== '') {
        foreach ($itens as $item) {
            $codigo = pg_codigo_limpo($item['productCode'] ?? $item['code'] ?? '');
            $barras = pg_codigo_limpo($item['productBarCode'] ?? '');
            if ($codigo === $ean || $barras === $ean) {
                return $item;
            }
        }
    }
    return null;
}

/** Numero que veio de campo de texto, ou null quando veio vazio. Funcao pura. */
function pg_numero($v): ?float
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    $limpo = str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', (string) $v));
    return is_numeric($limpo) ? (float) $limpo : null;
}

/**
 * Uma linha do planograma, do jeito que a tela entende. Funcao pura.
 *
 * `bruto` vai junto porque o PUT deles quer o objeto INTEIRO de volta: mandar
 * so os campos alterados zera o resto da linha.
 */
function pg_entrada_normalizar(array $e): array
{
    return [
        'produto_id'         => (int) ($e['productId'] ?? 0),
        'inventario_item_id' => isset($e['inventoryItemId']) ? (int) $e['inventoryItemId'] : null,
        'codigo'             => (string) ($e['productCode'] ?? ''),
        'ean'                => pg_codigo_limpo($e['productCode'] ?? ''),
        'descricao'          => (string) ($e['productDescription'] ?? ''),
        'imagem'             => $e['productImageUrl'] ?? null,
        'preco'              => (float) ($e['price'] ?? 0),
        'necessaria'         => (float) ($e['quantityToSupply'] ?? 0),
        'critico'            => (float) ($e['minimumQuantity'] ?? 0),
        'estoque'            => (float) ($e['currentQuantity'] ?? 0),
        'bruto'              => $e,
    ];
}

/** Um produto do cadastro, do jeito que a tela entende. Funcao pura. */
function pg_produto_normalizar(array $p): array
{
    $codigo = (string) ($p['code'] ?? $p['productCode'] ?? '');
    return [
        'produto_id' => (int) ($p['id'] ?? $p['productId'] ?? 0),
        'codigo'     => $codigo,
        'ean'        => pg_codigo_limpo($codigo),
        'descricao'  => (string) ($p['description'] ?? $p['productDescription'] ?? ''),
        'imagem'     => $p['imageUrl'] ?? $p['productImageUrl'] ?? null,
        // O cadastro nao tem preco de venda: preco e coisa do planograma, e e
        // por isso que incluir um produto exige digitar o preco.
        'preco'      => null,
    ];
}

/**
 * O que mudou, campo a campo. Funcao pura.
 *
 * Centavo e grama nao sobrevivem a comparacao de float: 9.1 vindo do JSON e
 * 9.1 digitado nao sao o mesmo bit. A folga de um milesimo e menor que
 * qualquer alteracao que alguem faca de proposito.
 *
 * @return array<int, array{campo:string, de:float, para:float}>
 */
function pg_mudancas(array $antes, array $depois): array
{
    $mudou = [];
    foreach (['preco', 'necessaria', 'critico', 'estoque'] as $campo) {
        if (!array_key_exists($campo, $depois) || $depois[$campo] === null) {
            continue;
        }
        $de   = (float) ($antes[$campo] ?? 0);
        $para = (float) $depois[$campo];
        if (abs($de - $para) > 0.0009) {
            $mudou[] = ['campo' => $campo, 'de' => $de, 'para' => $para];
        }
    }
    return $mudou;
}

// ---------------------------------------------------------------------
// O que fala com o banco e com o TouchPay
// ---------------------------------------------------------------------

/** Os PDVs que podem ser repostos: ativos e com planograma conhecido. */
function planograma_pdvs(): array
{
    return q(
        'SELECT id, nome, externo_id, planograma_id, inventario_id, atualizado_em
           FROM loja_pdvs
          WHERE ativo = 1 AND unificado_para IS NULL
       ORDER BY nome'
    );
}

/** Um PDV pelo id local, ja conferido. */
function planograma_pdv(int $pdv_id): ?array
{
    return q1(
        'SELECT id, nome, externo_id, planograma_id, inventario_id
           FROM loja_pdvs
          WHERE id = ? AND ativo = 1 AND unificado_para IS NULL',
        [$pdv_id]
    );
}

/**
 * Pergunta ao TouchPay qual planograma esta valendo em cada PDV e guarda.
 *
 * Um PDV tem varios planogramas e so um vale; quem sabe qual e o painel, nao
 * o espelho — que pode ter meia hora. Mas perguntar isso a cada bipe seria
 * uma ida extra por produto, e repor a gondola sao trinta bipes seguidos.
 * Entao pergunta UMA vez, quando a tela abre, e o resto da sessao usa o que
 * ficou guardado. Planograma nao troca no meio de uma reposicao.
 */
function planograma_sincronizar_pdvs(): void
{
    foreach (tp_pdvs() as $p) {
        $externo = (int) ($p['id'] ?? 0);
        if ($externo <= 0) {
            continue;
        }
        exec_sql(
            'UPDATE loja_pdvs SET planograma_id = ?, inventario_id = ?
              WHERE fonte = ? AND externo_id = ?',
            [
                ((int) ($p['currentPlanogramId'] ?? 0)) ?: null,
                ((int) ($p['inventoryId'] ?? 0)) ?: null,
                'touchpay',
                $externo,
            ]
        );
    }
}

/** O planograma guardado daquele PDV. Sem ele nao ha onde repor. */
function planograma_ativo(array $pdv): int
{
    $id = (int) ($pdv['planograma_id'] ?? 0);
    if ($id > 0) {
        return $id;
    }
    throw new TouchPayErro(
        'Este ponto de venda nao tem planograma ativo no TouchPay. Abra a tela de novo para reconferir.'
    );
}

/** O que o espelho local ja sabe sobre este codigo naquele PDV. */
function planograma_espelho(int $pdv_id, string $ean): ?array
{
    if ($ean === '') {
        return null;
    }
    return q1(
        'SELECT externo_produto_id, codigo, ean, descricao
           FROM loja_itens
          WHERE pdv_id = ? AND ean = ? AND externo_produto_id IS NOT NULL
          LIMIT 1',
        [$pdv_id, $ean]
    );
}

/**
 * A cascata inteira, para um codigo bipado.
 *
 * @return array{onde:string, codigo:string, planograma_id:int, entrada:?array, produto:?array}
 */
function planograma_procurar(int $pdv_id, string $codigo): array
{
    $pdv = planograma_pdv($pdv_id);
    if (!$pdv) {
        throw new TouchPayErro('Ponto de venda desconhecido.');
    }

    // mercado_chave() e nao ean_normalizado(): codigo interno de balanca nao
    // tem tamanho de GTIN e mesmo assim e um produto da gondola.
    $ean = mercado_chave($codigo) ?? '';
    if ($ean === '') {
        throw new TouchPayErro('Codigo curto demais para procurar.');
    }

    $planograma_id = planograma_ativo($pdv);
    $local = planograma_espelho($pdv_id, $ean);
    $produto_id = $local ? (int) $local['externo_produto_id'] : null;

    $vazio = [
        'onde'          => 'nenhum',
        'codigo'        => $ean,
        'planograma_id' => $planograma_id,
        'pdv'           => ['id' => (int) $pdv['id'], 'nome' => $pdv['nome'], 'externo_id' => (int) $pdv['externo_id']],
        'entrada'       => null,
        'produto'       => null,
    ];

    // 1. no planograma
    $achado = pg_casar(tp_planograma_buscar($planograma_id, $ean), $produto_id, $ean);
    if ($achado) {
        return ['onde' => 'planograma', 'entrada' => pg_entrada_normalizar($achado)] + $vazio;
    }

    // 2. no cadastro
    $noCadastro = pg_casar(tp_catalogo_buscar($ean), $produto_id, $ean);
    if ($noCadastro) {
        return ['onde' => 'cadastro', 'produto' => pg_produto_normalizar($noCadastro)] + $vazio;
    }

    // 3. lugar nenhum
    return $vazio;
}

/**
 * Grava as alteracoes.
 *
 * Rele a linha no TouchPay antes de escrever, por dois motivos. O PUT deles
 * quer o objeto inteiro, e o objeto que veio parar no celular pode ter cinco
 * minutos; e se alguem mexeu no preco nesse meio tempo, sobrescrever calado
 * seria desfazer o trabalho de outra pessoa sem ninguem ficar sabendo.
 *
 * @param array $dados preco, necessaria, critico, estoque, produto_id, visto
 * @return array{ok:bool, conflito?:bool, mudancas:array, entrada:?array}
 */
function planograma_salvar(array $u, int $pdv_id, array $dados): array
{
    $pdv = planograma_pdv($pdv_id);
    if (!$pdv) {
        throw new TouchPayErro('Ponto de venda desconhecido.');
    }

    $produto_id = (int) ($dados['produto_id'] ?? 0);
    if ($produto_id <= 0) {
        throw new TouchPayErro('Faltou dizer qual produto.');
    }

    $ean           = mercado_chave($dados['codigo'] ?? '') ?? '';
    $planograma_id = planograma_ativo($pdv);
    $pos_id        = (int) $pdv['externo_id'];

    $querido = [
        'preco'      => pg_numero($dados['preco'] ?? null),
        'necessaria' => pg_numero($dados['necessaria'] ?? null),
        'critico'    => pg_numero($dados['critico'] ?? null),
        'estoque'    => pg_numero($dados['estoque'] ?? null),
    ];

    $atual = pg_casar(tp_planograma_buscar($planograma_id, $ean !== '' ? $ean : (string) $produto_id), $produto_id, $ean);

    // ---- incluir: nao esta no planograma ----
    if (!$atual) {
        if ($querido['preco'] === null || $querido['preco'] <= 0) {
            throw new TouchPayErro('Produto novo no planograma precisa de preco.');
        }
        tp_entrada_incluir([
            'availability'       => 'Local',
            'selection'          => null,
            'planogramId'        => $planograma_id,
            'productId'          => $produto_id,
            'productDescription' => (string) ($dados['descricao'] ?? ''),
            'price'              => $querido['preco'],
            'quantityToSupply'   => $querido['necessaria'] ?? 0,
            'minimumQuantity'    => $querido['critico'] ?? 0,
        ]);
        pg_registrar($u, $pdv, $planograma_id, $produto_id, $ean, (string) ($dados['descricao'] ?? ''),
            'incluir', null, null, null);

        // O POST nao devolve o inventoryItemId; ele nasce com a linha. Sem
        // reler, nao ha como mexer no estoque do que acabou de entrar.
        $atual = pg_casar(tp_planograma_buscar($planograma_id, $ean !== '' ? $ean : (string) $produto_id), $produto_id, $ean);
        if (!$atual) {
            return ['ok' => true, 'mudancas' => [['campo' => 'incluir', 'de' => 0, 'para' => 0]], 'entrada' => null];
        }
        $antes = pg_entrada_normalizar($atual);
        // Preco, necessaria e critico ja entraram no POST; sobra o estoque.
        $mudancas = pg_mudancas($antes, ['estoque' => $querido['estoque']]);
        return pg_aplicar($u, $pdv, $planograma_id, $pos_id, $antes, $querido, $mudancas, true);
    }

    // ---- alterar: ja esta la ----
    $antes    = pg_entrada_normalizar($atual);
    $mudancas = pg_mudancas($antes, $querido);

    if (!$mudancas) {
        return ['ok' => true, 'mudancas' => [], 'entrada' => $antes];
    }

    // Alguem mexeu depois que esta tela leu? So importa nos campos que ESTA
    // pessoa esta mudando — preco alheio nao atrapalha quem so repoe estoque.
    $visto = is_array($dados['visto'] ?? null) ? $dados['visto'] : [];
    foreach ($mudancas as $m) {
        $antesVisto = pg_numero($visto[$m['campo']] ?? null);
        if ($antesVisto !== null && abs($antesVisto - $m['de']) > 0.0009) {
            return [
                'ok'       => false,
                'conflito' => true,
                'mudancas' => [],
                'entrada'  => $antes,
            ];
        }
    }

    return pg_aplicar($u, $pdv, $planograma_id, $pos_id, $antes, $querido, $mudancas, false);
}

/**
 * Manda as mudancas e registra cada uma.
 *
 * Planograma e estoque sao duas chamadas e nao ha transacao entre elas: se a
 * segunda falhar, a primeira ja passou. Por isso o log grava cada campo
 * separado e com ok/erro — quando uma metade cai, da para ver exatamente qual
 * foi, em vez de ficar adivinhando o que pegou.
 */
function pg_aplicar(array $u, array $pdv, int $planograma_id, int $pos_id,
                    array $antes, array $querido, array $mudancas, bool $novo): array
{
    $doPlano  = array_values(array_filter($mudancas, static fn ($m) => $m['campo'] !== 'estoque'));
    $doEstoque = array_values(array_filter($mudancas, static fn ($m) => $m['campo'] === 'estoque'));

    if ($doPlano) {
        // O objeto INTEIRO de volta, com os campos trocados por cima: mandar
        // so o que mudou apaga o resto da linha.
        $corpo = $antes['bruto'];
        $corpo['planogramId'] = $planograma_id;
        foreach ($doPlano as $m) {
            $chave = ['preco' => 'price', 'necessaria' => 'quantityToSupply', 'critico' => 'minimumQuantity'][$m['campo']];
            $corpo[$chave] = $m['para'];
        }
        tp_entrada_alterar($corpo);
        foreach ($doPlano as $m) {
            pg_registrar($u, $pdv, $planograma_id, $antes['produto_id'], $antes['ean'], $antes['descricao'],
                $novo ? 'incluir' : 'alterar', $m['campo'], $m['de'], $m['para']);
        }
    }

    if ($doEstoque) {
        $item = (int) ($antes['inventario_item_id'] ?? 0);
        if ($item <= 0) {
            pg_registrar($u, $pdv, $planograma_id, $antes['produto_id'], $antes['ean'], $antes['descricao'],
                'estoque', 'estoque', $doEstoque[0]['de'], $doEstoque[0]['para'],
                'sem inventoryItemId: o TouchPay nao devolveu o item de inventario');
            throw new TouchPayErro(
                'O preco foi salvo, mas o estoque nao: o TouchPay nao devolveu o item de inventario deste produto.'
            );
        }
        tp_estoque_definir($pos_id, $item, $doEstoque[0]['para']);
        pg_registrar($u, $pdv, $planograma_id, $antes['produto_id'], $antes['ean'], $antes['descricao'],
            'estoque', 'estoque', $doEstoque[0]['de'], $doEstoque[0]['para']);
    }

    return ['ok' => true, 'mudancas' => $mudancas, 'entrada' => $antes];
}

/** Uma linha do diario. Nunca derruba a gravacao: log que atrapalha e pior. */
function pg_registrar(array $u, array $pdv, int $planograma_id, int $produto_id, string $ean,
                      string $descricao, string $acao, ?string $campo,
                      $de, $para, ?string $erro = null): void
{
    try {
        inserir('planograma_log', [
            'usuario_id'         => (int) ($u['id'] ?? 0) ?: null,
            'pdv_id'             => (int) $pdv['id'],
            'planograma_id'      => $planograma_id,
            'produto_externo_id' => $produto_id ?: null,
            'ean'                => $ean !== '' ? mb_substr($ean, 0, 14) : null,
            'descricao'          => mb_substr($descricao, 0, 255) ?: null,
            'acao'               => $acao,
            'campo'              => $campo,
            'de'                 => $de === null ? null : (string) $de,
            'para'               => $para === null ? null : (string) $para,
            'ok'                 => $erro === null ? 1 : 0,
            'erro'               => $erro !== null ? mb_substr($erro, 0, 255) : null,
            'criado_em'          => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('planograma_log: ' . $e->getMessage());
    }
}

/**
 * A resposta sem o objeto cru do TouchPay.
 *
 * `bruto` existe para o PUT devolver a linha inteira, e quem faz o PUT e o
 * servidor, relendo na hora de gravar. Mandar para o celular seria carregar
 * quarenta campos por bipe que a tela nao usa — e convidar alguem a acreditar
 * que o que voltou de la e a verdade do momento da gravacao.
 */
function planograma_para_tela(array $r): array
{
    if (isset($r['entrada']) && is_array($r['entrada'])) {
        unset($r['entrada']['bruto']);
    }
    return $r;
}

/** As ultimas alteracoes, para a tela mostrar o que ja foi feito hoje. */
function planograma_log_recente(int $limite = 20): array
{
    return q(
        'SELECT l.*, p.nome AS pdv, u.nome AS usuario
           FROM planograma_log l
      LEFT JOIN loja_pdvs p ON p.id = l.pdv_id
      LEFT JOIN usuarios  u ON u.id = l.usuario_id
       ORDER BY l.id DESC
          LIMIT ' . max(1, min(100, $limite))
    );
}
