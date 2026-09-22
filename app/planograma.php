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
// Validade
// ---------------------------------------------------------------------

/**
 * Uma data, venha de onde vier, em AAAA-MM-DD. Funcao pura.
 *
 * Aceita o que o TouchPay manda ("2027-02-14T00:00:00Z"), o que o campo
 * <input type="date"> manda ("2027-02-14") e o que um dedo digita
 * ("14/02/2027"). Qualquer outra coisa e null — e null aqui quer dizer "nao
 * encostei nesta validade", nunca "apague a validade".
 *
 * checkdate() no fim porque 2027-02-31 passa em qualquer regex e nao existe.
 */
function pg_data_iso($v): ?string
{
    $t = trim((string) ($v ?? ''));
    if ($t === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m)) {
        [$a, $mes, $d] = [$m[1], $m[2], $m[3]];
    } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $t, $m)) {
        [$a, $mes, $d] = [$m[3], $m[2], $m[1]];
    } else {
        return null;
    }
    return checkdate((int) $mes, (int) $d, (int) $a) ? $a . '-' . $mes . '-' . $d : null;
}

/** "2027-02-14" -> "14/02/2027". Funcao pura; sem data, o travessao. */
function pg_data_br(?string $iso): string
{
    return $iso === null ? '—' : substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4);
}

/** Do jeito que o TouchPay quer receber de volta. Funcao pura. */
function pg_data_tp(?string $iso): ?string
{
    return $iso === null ? null : $iso . 'T00:00:00Z';
}

/**
 * Esta data e digitavel por gente? Funcao pura.
 *
 * No inventario deles ha um item com validade em **5027** — alguem digitou 5
 * no lugar de 2 e o sistema engoliu. E o mesmo erro do preco que vai de
 * R$ 9,50 para R$ 950,00 porque a virgula nao entrou, e aqui ele tem a mesma
 * cara: um numero perfeitamente valido, so que errado por mil anos.
 *
 * A janela e larga de proposito. Enlatado com cinco anos de prateleira
 * existe; validade daqui a onze anos, nao. E para tras vale ate dois anos,
 * porque cadastrar a validade de um produto que JA venceu e exatamente o que
 * se faz quando se descobre que ele venceu.
 */
function pg_validade_plausivel(?string $iso, ?string $hoje = null): bool
{
    if ($iso === null) {
        return false;
    }
    $ts  = strtotime($iso);
    $ref = strtotime($hoje ?? date('Y-m-d'));
    if ($ts === false || $ref === false) {
        return false;
    }
    $dias = ($ts - $ref) / 86400;
    return $dias >= -730 && $dias <= 3650;
}

/**
 * O que fazer com a validade, dado o que ja esta la e o que veio da nota.
 * Funcao pura.
 *
 * O campo do TouchPay e UM so por item, mas a gondola tem mistura: o que ja
 * estava e o que esta entrando agora. A data que vale e a que vence PRIMEIRO,
 * porque e ela que manda na hora de tirar o produto da prateleira. Entao
 * repor com um lote mais novo nao pode empurrar a validade para a frente e
 * esconder o pacote velho que continua la atras.
 *
 * Isto devolve a RECOMENDACAO, nao a decisao: quem escolhe e quem esta de pe
 * no corredor e consegue ver se o lote antigo ainda existe. Se ele vendeu
 * tudo e repos, a data nova e a certa — e so a pessoa sabe disso.
 *
 * @return array{acao:string, data:?string, recomendado:string, motivo:string}
 */
function pg_validade_decidir(?string $atual, ?string $nova): array
{
    if ($nova === null) {
        return ['acao' => 'nada', 'data' => null, 'recomendado' => 'nada',
                'motivo' => 'Nenhuma validade digitada.'];
    }
    if ($atual === null) {
        return ['acao' => 'gravar', 'data' => $nova, 'recomendado' => 'gravar',
                'motivo' => 'Nao havia validade cadastrada.'];
    }
    if ($atual === $nova) {
        return ['acao' => 'nada', 'data' => $atual, 'recomendado' => 'nada',
                'motivo' => 'A validade cadastrada ja e essa.'];
    }
    if ($nova < $atual) {
        return ['acao' => 'gravar', 'data' => $nova, 'recomendado' => 'gravar',
                'motivo' => 'A que voce esta repondo vence antes (' . pg_data_br($nova) . ').'];
    }
    return ['acao' => 'manter', 'data' => $atual, 'recomendado' => 'manter',
            'motivo' => 'A do estoque vence antes (' . pg_data_br($atual) . ').'];
}

/** Um uuid v4 para a operacao. Eles aceitam o que o cliente gerar. */
function pg_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
         . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * O corpo da operacao de inventario. Funcao pura.
 *
 * Nao existe "altera a validade deste item" na API deles: o que existe e
 * FECHAR UMA REPOSICAO INTEIRA, com a lista completa de itens, marcando os
 * que foram tocados. Foi assim que o app deles fez, e mandar lista parcial
 * seria deixar o servidor decidir sozinho o que fazer com os que faltaram —
 * e o que ele faz, ninguem aqui sabe.
 *
 * Por isso cada item inerte vai de volta com a validade que JA TEM. Se fosse
 * montado so a partir do planograma (que nao carrega validade), todo item
 * viajaria com `productExpirationDate: null` — e se o servidor ler null como
 * "apague", uma gravacao de validade apagaria a validade da loja inteira. A
 * lista nasce do cruzamento planograma x inventario justamente para que
 * nenhum item precise adivinhar a propria data.
 *
 * @param array $entradas   linhas do planograma (traz inventoryItemId)
 * @param array $inventario itens do inventario (traz validade e quantidade)
 * @return array{corpo:array, alvo:?array}
 */
function pg_operacao_montar(array $entradas, array $inventario, int $planograma_id,
                            int $produto_alvo, ?string $data_iso,
                            string $uuid, string $inicio, string $fim): array
{
    // O inventario pelo produto, que e a chave que os dois lados tem em comum.
    $porProduto = [];
    foreach ($inventario as $i) {
        $pid = (int) ($i['productId'] ?? 0);
        if ($pid > 0) {
            $porProduto[$pid] = $i;
        }
    }

    $itens = [];
    $alvo  = null;

    foreach ($entradas as $e) {
        $pid = (int) ($e['productId'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $inv = $porProduto[$pid] ?? null;

        // previousQuantity sai do inventario quando ele conhece o produto: e
        // de la que o numero vem, e e com ele que a confirmacao tem de bater.
        $quantidade = $inv !== null
            ? (float) ($inv['quantity'] ?? 0)
            : (float) ($e['currentQuantity'] ?? 0);

        $item = [
            'productId'             => $pid,
            'selection'             => (int) ($e['selection'] ?? 0),
            'inventoryItemId'       => (int) ($e['inventoryItemId'] ?? 0),
            'previousQuantity'      => $quantidade,
            'quantityToSupply'      => (float) ($e['quantityToSupply'] ?? 0),
            'capacity'              => (float) ($e['capacity'] ?? 0),
            'confirmedQuantity'     => null,
            'suppliedQuantity'      => null,
            'productExpirationDate' => pg_data_tp(pg_data_iso($inv['productExpirationDate'] ?? null)),
            'dateConfirmed'         => null,
            'actions'               => [],
            'parentInventoryItemId' => null,
            'removeExpirationDate'  => false,
        ];

        if ($pid === $produto_alvo) {
            // Confirmar o item e o que faz a validade pegar — foi o que o app
            // deles mandou. E confirmar carrega quantidade, entao ela vai
            // igual ao previousQuantity que o proprio TouchPay acabou de
            // dizer: uma contagem que confirma o numero que ja esta la nao
            // move estoque nenhum, seja qual for esse numero.
            $item['confirmedQuantity']     = $quantidade;
            $item['dateConfirmed']         = $fim;
            $item['productExpirationDate'] = pg_data_tp($data_iso);
            $alvo = $item;
        }

        $itens[] = $item;
    }

    return [
        'corpo' => [
            'uuid'             => $uuid,
            'type'             => 'Inventory',
            'supplyType'       => 'PickList',
            'planogramId'      => $planograma_id,
            'inventoryId'      => null,
            'pickListId'       => null,
            'dateStarted'      => $inicio,
            'dateCompleted'    => $fim,
            'supplyItems'      => $itens,
            'isBlindOperation' => false,
            'comments'         => '',
        ],
        'alvo' => $alvo,
    ];
}

/**
 * O corpo esta em condicoes de sair daqui? Funcao pura.
 *
 * Esta operacao escreve na loja inteira de uma vez; um corpo torto nao erra
 * um produto, erra todos. Entao antes de mandar, quatro perguntas — e
 * qualquer "nao" para a gravacao em vez de tentar a sorte.
 *
 * A terceira e a que mais importa: se um item tinha validade e o corpo vai
 * sair sem ela, alguma coisa se perdeu no cruzamento, e mandar assim
 * apagaria a validade de quem nunca foi tocado.
 *
 * @return ?string a queixa, ou null quando esta tudo certo
 */
function pg_operacao_conferir(array $corpo, array $inventario, int $produto_alvo): ?string
{
    $itens = $corpo['supplyItems'] ?? [];
    if (!$itens) {
        return 'a operacao saiu sem nenhum item';
    }

    $tinhaValidade = [];
    foreach ($inventario as $i) {
        if (pg_data_iso($i['productExpirationDate'] ?? null) !== null) {
            $tinhaValidade[(int) ($i['productId'] ?? 0)] = true;
        }
    }

    $confirmados = 0;
    foreach ($itens as $it) {
        $pid = (int) ($it['productId'] ?? 0);

        if ((int) ($it['inventoryItemId'] ?? 0) <= 0) {
            return 'o produto ' . $pid . ' entrou na operacao sem item de inventario';
        }
        if ($it['dateConfirmed'] !== null) {
            $confirmados++;
            if ($pid !== $produto_alvo) {
                return 'a operacao ia confirmar o produto ' . $pid . ', que ninguem pediu';
            }
        }
        // Validade que existia nao pode sair pelo caminho — menos a do alvo,
        // que e justamente a que esta sendo trocada.
        if ($pid !== $produto_alvo
            && isset($tinhaValidade[$pid])
            && $it['productExpirationDate'] === null) {
            return 'a validade do produto ' . $pid . ' ia embora sem ninguem pedir';
        }
    }

    if ($confirmados !== 1) {
        return 'a operacao marcou ' . $confirmados . ' itens em vez de um';
    }
    return null;
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
        $entrada = pg_entrada_normalizar($achado);
        // A validade nao vem no planograma, so no inventario — e e ela que
        // deixa a pessoa decidir se mantem a do estoque ou grava a da nota.
        // Vale a ida extra: sem ver a que esta la, a escolha vira chute.
        $entrada['validade'] = planograma_validade_atual($pdv, $entrada['produto_id']);
        return ['onde' => 'planograma', 'entrada' => $entrada] + $vazio;
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
 * A validade que o TouchPay tem para este produto AGORA.
 *
 * Le o inventario filtrado pelo produto: resposta pequena, uma ida so. O
 * espelho local tambem tem a validade, mas o espelho pode ter meia hora — e
 * a decisao de manter ou trocar a data se toma contra o que esta valendo,
 * nao contra o que estava no ultimo sync.
 */
function planograma_validade_atual(array $pdv, int $produto_id): ?string
{
    $inventario_id = (int) ($pdv['inventario_id'] ?? 0);
    if ($inventario_id <= 0 || $produto_id <= 0) {
        return null;
    }
    foreach (tp_inventario_tudo($inventario_id, $produto_id) as $i) {
        if ((int) ($i['productId'] ?? 0) === $produto_id) {
            return pg_data_iso($i['productExpirationDate'] ?? null);
        }
    }
    return null;
}

/**
 * Grava a validade, se for o caso — e so depois do estoque.
 *
 * A ordem nao e detalhe. Gravar validade exige CONFIRMAR o item, e confirmar
 * carrega quantidade junto; se isso rodasse antes do PUT do estoque, a
 * confirmacao levaria o numero velho e desfaria o ajuste que a pessoa acabou
 * de fazer. Rodando por ultimo, a leitura de agora ja inclui o estoque novo.
 *
 * Tres ideias mandam aqui, e todas as tres ja regem o resto desta tela:
 * reler antes de gravar, parar em vez de desfazer o trabalho de outra pessoa,
 * e registrar o de/para de quem mexeu.
 */
function pg_validade_etapa(array $u, array $pdv, int $planograma_id, array $antes,
                           array $dados, array $r): array
{
    $nova    = pg_data_iso($dados['validade'] ?? null);
    $escolha = (string) ($dados['validade_acao'] ?? 'auto');

    // Campo em branco e "nao encostei nesta validade" — igual ao preco vazio,
    // que nao vira zero. Apagar validade nao se faz por aqui.
    if ($nova === null || $escolha === 'manter') {
        return $r;
    }
    if (!pg_validade_plausivel($nova)) {
        throw new TouchPayErro(
            'Validade fora do razoavel (' . pg_data_br($nova) . '). Confira o ano.'
        );
    }

    $inventario_id = (int) ($pdv['inventario_id'] ?? 0);
    if ($inventario_id <= 0) {
        throw new TouchPayErro(
            'Este ponto de venda nao tem inventario conhecido. Abra a tela de novo para reconferir.'
        );
    }

    $produto_id = (int) $antes['produto_id'];
    $inicio     = date('Y-m-d\TH:i:s') . '.000';

    // A lista COMPLETA, dos dois lados: o planograma tem o inventoryItemId, o
    // inventario tem a validade de cada um. Mandar operacao montada so com o
    // planograma faria todo item viajar sem validade.
    $entradas   = tp_planograma_tudo($planograma_id);
    $inventario = tp_inventario_tudo($inventario_id);

    $atual = null;
    foreach ($inventario as $i) {
        if ((int) ($i['productId'] ?? 0) === $produto_id) {
            $atual = pg_data_iso($i['productExpirationDate'] ?? null);
            break;
        }
    }

    // Alguem mexeu na validade depois que esta tela leu? Entao a escolha de
    // manter ou trocar foi tomada contra um numero que ja nao existe.
    if (array_key_exists('validade', $dados['visto'] ?? [])) {
        $visto = pg_data_iso($dados['visto']['validade']);
        if ($visto !== $atual) {
            return ['ok' => false, 'conflito' => true, 'mudancas' => [], 'entrada' => $antes];
        }
    }

    $decisao = pg_validade_decidir($atual, $nova);
    $acao    = $escolha === 'gravar' ? 'gravar' : $decisao['acao'];
    if ($acao !== 'gravar' || $atual === $nova) {
        return $r;
    }

    $fim = date('Y-m-d\TH:i:s') . '.000';
    $op  = pg_operacao_montar($entradas, $inventario, $planograma_id, $produto_id,
                              $nova, pg_uuid(), $inicio, $fim);

    // A ultima porta antes de escrever na loja inteira. Corpo torto aqui nao
    // erra um produto, erra todos — entao na duvida nao manda.
    $queixa = pg_operacao_conferir($op['corpo'], $inventario, $produto_id);
    if ($queixa !== null || $op['alvo'] === null) {
        pg_registrar($u, $pdv, $planograma_id, $produto_id, $antes['ean'], $antes['descricao'],
            'validade', 'validade', $atual, $nova, $queixa ?? 'o produto nao estava no planograma lido');
        throw new TouchPayErro(
            'Nao gravei a validade: ' . ($queixa ?? 'o produto sumiu do planograma entre a leitura e a gravacao') . '.'
        );
    }

    tp_operacao($op['corpo']);
    pg_registrar($u, $pdv, $planograma_id, $produto_id, $antes['ean'], $antes['descricao'],
        'validade', 'validade', $atual, $nova);

    // O espelho local acompanha na hora: esperar o proximo sync faria a lista
    // da Loja mostrar a validade velha logo depois de a pessoa te-la trocado.
    try {
        exec_sql(
            'UPDATE loja_itens SET validade = ? WHERE pdv_id = ? AND externo_produto_id = ?',
            [$nova, (int) $pdv['id'], $produto_id]
        );
    } catch (Throwable $e) {
        error_log('espelho da validade: ' . $e->getMessage());
    }

    $r['mudancas'][] = ['campo' => 'validade', 'de' => $atual, 'para' => $nova];
    return $r;
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
        $r = pg_aplicar($u, $pdv, $planograma_id, $pos_id, $antes, $querido, $mudancas, true);
        // Produto que acabou de entrar nao tinha validade nenhuma, entao aqui
        // nao ha o que manter: o que a pessoa digitou e o que vale.
        return pg_validade_etapa($u, $pdv, $planograma_id, $antes, $dados, $r);
    }

    // ---- alterar: ja esta la ----
    $antes    = pg_entrada_normalizar($atual);
    $mudancas = pg_mudancas($antes, $querido);

    // Mexer so na validade e um caso comum: bipa, ve que o preco e o estoque
    // estao certos, e corrige a data da nota. Sair aqui sem passar pela
    // validade transformaria isso em "nada mudou".
    if (!$mudancas) {
        return pg_validade_etapa($u, $pdv, $planograma_id, $antes, $dados,
            ['ok' => true, 'mudancas' => [], 'entrada' => $antes]);
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

    $r = pg_aplicar($u, $pdv, $planograma_id, $pos_id, $antes, $querido, $mudancas, false);
    return pg_validade_etapa($u, $pdv, $planograma_id, $antes, $dados, $r);
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
