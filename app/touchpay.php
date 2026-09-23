<?php
declare(strict_types=1);

/**
 * O painel do TouchPay, falado direto daqui.
 *
 * O espelho da loja (loja.php) passa pelo n8n porque e raspagem pesada e
 * demorada: dispara, esquece, e o callback chega quando chegar. Repor gondola
 * e o contrario disso — bipa, ve, corrige, salva, tudo com o carrinho parado
 * no corredor. Callback assincrono nessa tela seria pedir para a pessoa
 * recarregar a pagina para descobrir se o preco pegou.
 *
 * Entao aqui e cURL direto, igual ao off_buscar() do mercado.php. As
 * credenciais ja moram no config.php e ja eram as mesmas que o n8n recebia.
 *
 * ESTA E A UNICA PARTE DO APP QUE ESCREVE NA LOJA DE VERDADE. Preco errado
 * daqui e preco errado cobrado do cliente no caixa — por isso toda alteracao
 * passa por planograma_salvar(), que rele o valor atual antes de gravar e
 * registra de/para em planograma_log.
 */

const TP_BASE = 'https://touchpay.market';

/**
 * O painel e um SPA de navegador; pedir com cara de navegador e o que ele
 * espera. Mesmo user-agent do coletor do n8n, de proposito: se um dia eles
 * passarem a barrar, os dois param juntos e o motivo fica obvio.
 */
const TP_NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                   . '(KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

/** Paciencia de cada chamada. Quem esta na gondola desiste antes do servidor. */
const TP_TIMEOUT = 12;

/**
 * Folga antes do vencimento do JWT.
 *
 * O token dura uma hora. Usar ate o ultimo segundo garante que, uma hora
 * depois de logar, alguma chamada vai vencer no meio do caminho — e a que
 * vence pode ser justamente o PUT do preco, ja com a alteracao confirmada na
 * tela. Dois minutos de folga fazem a renovacao cair sempre numa leitura.
 */
const TP_FOLGA_EXP = 120;

/** Erro vindo do TouchPay. Separado para a rota saber que a culpa nao e nossa. */
class TouchPayErro extends RuntimeException
{
}

/** Tem login configurado? Sem isso a tela inteira nao faz sentido. */
function tp_configurado(): bool
{
    return trim((string) cfg('touchpay_email')) !== ''
        && trim((string) cfg('touchpay_senha')) !== '';
}

/**
 * Quando este JWT vence, em epoch. Funcao pura.
 *
 * Le o `exp` do proprio token em vez de cravar uma hora no relogio daqui: o
 * dia em que eles mudarem a duracao, isto acompanha sozinho. Token que nao se
 * deixa ler devolve null, e quem chama trata como "vence logo".
 */
function tp_jwt_exp(string $jwt): ?int
{
    $partes = explode('.', $jwt);
    if (count($partes) < 2 || $partes[1] === '') {
        return null;
    }

    // base64url: '-' e '_' no lugar de '+' e '/', e sem o '=' do fim.
    $b64 = strtr($partes[1], '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);

    $corpo = base64_decode($b64, true);
    if (!is_string($corpo)) {
        return null;
    }
    $json = json_decode($corpo, true);
    $exp = is_array($json) ? ($json['exp'] ?? null) : null;

    return is_numeric($exp) ? (int) $exp : null;
}

/**
 * Faz login e devolve o JWT.
 *
 * O /account/login responde 200 com CORPO VAZIO: o token vem no header
 * `authorization`. Procurar no body devolve nada — foi o que o n8n descobriu
 * primeiro (n8n/codigo/tp-02-pegar-token.js) e vale o mesmo aqui.
 */
function tp_entrar(): string
{
    $email = (string) cfg('touchpay_email');
    $senha = (string) cfg('touchpay_senha');
    if ($email === '' || $senha === '') {
        throw new TouchPayErro('touchpay_email/touchpay_senha nao configurados no config.php');
    }

    $cabecalhos = [];
    $ch = curl_init(TP_BASE . '/account/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'password' => $senha]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json;charset=UTF-8',
            'Accept: application/json, text/plain, */*',
            'Referer: ' . TP_BASE . '/',
        ],
        CURLOPT_USERAGENT      => TP_NAVEGADOR,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => TP_TIMEOUT,
        CURLOPT_HEADERFUNCTION => static function ($ch, $linha) use (&$cabecalhos) {
            $par = explode(':', $linha, 2);
            if (count($par) === 2) {
                $cabecalhos[strtolower(trim($par[0]))] = trim($par[1]);
            }
            return strlen($linha);
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new TouchPayErro('Nao consegui falar com o TouchPay: ' . curl_strerror($errno));
    }
    if ($http === 401 || $http === 400) {
        throw new TouchPayErro('O TouchPay recusou o login. Confira touchpay_email e touchpay_senha.');
    }

    $jwt = trim(preg_replace('/^Bearer\s+/i', '', $cabecalhos['authorization'] ?? ''));
    if ($jwt === '') {
        throw new TouchPayErro('O TouchPay nao devolveu o header authorization (HTTP ' . $http . ').');
    }
    return $jwt;
}

/**
 * O JWT de agora: o guardado, enquanto valer, ou um login novo.
 *
 * Guardado no banco e nao na sessao do PHP porque quem repoe a gondola abre a
 * tela pelo celular e o cron tambem passa por aqui: um login por hora para o
 * app inteiro, em vez de um por aparelho e por aba.
 */
function tp_token(bool $renovar = false): string
{
    static $memoria = null;

    if ($renovar) {
        $memoria = null;
    } elseif ($memoria !== null) {
        return $memoria;
    }

    if (!$renovar) {
        $linha = q1('SELECT jwt, expira_em FROM touchpay_sessao WHERE id = 1');
        if ($linha && strtotime((string) $linha['expira_em']) > time()) {
            return $memoria = (string) $linha['jwt'];
        }
    }

    $jwt = tp_entrar();
    $exp = tp_jwt_exp($jwt);
    // Token ilegivel nao e motivo para parar: vale meia hora e a proxima
    // renovacao resolve.
    $expira = $exp !== null ? $exp - TP_FOLGA_EXP : time() + 1800;

    exec_sql(
        'INSERT INTO touchpay_sessao (id, jwt, expira_em, criado_em) VALUES (1, ?, ?, ?)
         ON DUPLICATE KEY UPDATE jwt = VALUES(jwt), expira_em = VALUES(expira_em),
                                 criado_em = VALUES(criado_em)',
        [$jwt, date('Y-m-d H:i:s', $expira), date('Y-m-d H:i:s')]
    );

    return $memoria = $jwt;
}

/**
 * Uma chamada ao painel, ja autenticada.
 *
 * `$corpo === null` manda requisicao sem corpo — o PUT do estoque e assim,
 * com a quantidade na propria URL e Content-Length zero.
 *
 * 401 renova o token e repete UMA vez. Sem isso, o primeiro toque depois de
 * uma hora parada sempre falharia; com mais de uma vez, um login que passou a
 * ser recusado viraria um laco de tentativas contra o servidor deles.
 */
function tp_chamar(string $metodo, string $caminho, ?array $corpo = null, bool $renovou = false)
{
    $jwt = tp_token($renovou);

    $cabecalhos = [
        'Accept: application/json, text/plain, */*',
        'Authorization: Bearer ' . $jwt,
        'Referer: ' . TP_BASE . '/',
    ];
    $opcoes = [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_USERAGENT      => TP_NAVEGADOR,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => TP_TIMEOUT,
    ];

    if ($corpo !== null) {
        $cabecalhos[] = 'Content-Type: application/json';
        $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($metodo !== 'GET') {
        // PUT sem corpo: sem isto o cURL nao manda Content-Length e parte dos
        // servidores devolve 411.
        $cabecalhos[] = 'Content-Length: 0';
    }
    $opcoes[CURLOPT_HTTPHEADER] = $cabecalhos;

    $ch = curl_init(TP_BASE . $caminho);
    curl_setopt_array($ch, $opcoes);
    $resposta = curl_exec($ch);
    $errno    = curl_errno($ch);
    $http     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new TouchPayErro('Nao consegui falar com o TouchPay: ' . curl_strerror($errno));
    }
    if ($http === 401 && !$renovou) {
        return tp_chamar($metodo, $caminho, $corpo, true);
    }
    if ($http >= 400) {
        throw new TouchPayErro(tp_erro_legivel($http, is_string($resposta) ? $resposta : ''));
    }

    // 204 e 200-com-corpo-vazio sao respostas validas de escrita.
    if (!is_string($resposta) || trim($resposta) === '') {
        return [];
    }
    $json = json_decode($resposta, true);
    return is_array($json) ? $json : [];
}

/**
 * O que deu errado, em portugues.
 *
 * O corpo do erro deles as vezes e JSON com `message`, as vezes e HTML de
 * pagina de erro inteira. Jogar HTML cru numa tela de celular nao ajuda
 * ninguem, entao so o que couber numa linha sai daqui.
 */
function tp_erro_legivel(int $http, string $corpo): string
{
    $json = json_decode($corpo, true);
    if (is_array($json)) {
        foreach (['message', 'Message', 'error', 'title'] as $chave) {
            $msg = trim((string) ($json[$chave] ?? ''));
            if ($msg !== '') {
                return 'TouchPay (HTTP ' . $http . '): ' . mb_substr($msg, 0, 180);
            }
        }
    }
    if ($http === 403) {
        return 'TouchPay recusou (HTTP 403): este login nao tem permissao para esta alteracao.';
    }
    return 'TouchPay respondeu HTTP ' . $http . '.';
}

// ---------------------------------------------------------------------
// As chamadas que a tela usa
// ---------------------------------------------------------------------

/** Pontos de venda, com o planograma ativo de cada um. */
function tp_pdvs(): array
{
    return pg_itens(tp_chamar('GET', '/api/pointsOfSale'));
}

/** Os planogramas de um PDV — sao mais de um, e so um esta valendo. */
function tp_planogramas(int $pos_id): array
{
    $r = tp_chamar('GET', '/api/Planograms?posId=' . $pos_id);
    return pg_itens($r);
}

/**
 * Procura um produto dentro de um planograma.
 *
 * pageSize pequeno de proposito: isto responde um bipe, nao monta relatorio.
 */
function tp_planograma_buscar(int $planograma_id, string $termo): array
{
    return pg_itens(tp_chamar(
        'GET',
        '/api/Planograms/' . $planograma_id
        . '?page=1&pageSize=20&sortOrder=quantityToSupply&descending=true'
        . '&search=' . rawurlencode($termo) . '&showOnlyCritical=false'
    ));
}

/** Procura no cadastro de produtos — o degrau de quem nao esta no planograma. */
function tp_catalogo_buscar(string $termo): array
{
    return pg_itens(tp_chamar(
        'GET',
        '/api/products/productBaseSimpleInfo?page=1&pageSize=30&descending=false'
        . '&search=' . rawurlencode($termo) . '&showProductGroups=true'
    ));
}

/** Altera uma linha que JA esta no planograma. O corpo vai inteiro de volta. */
function tp_entrada_alterar(array $entrada): array
{
    return tp_chamar('PUT', '/api/PlanogramEntries', $entrada);
}

/** Inclui um produto do cadastro no planograma. */
function tp_entrada_incluir(array $dados): array
{
    return tp_chamar('POST', '/api/PlanogramEntries', $dados);
}

/**
 * Quantos itens a resposta paginada diz ter, quando diz. Funcao pura.
 *
 * Vem em `entries.totalItems` (planograma) ou `totalItems` (inventario), e
 * as vezes nao vem. Null quer dizer "o servidor nao contou", e ai quem pagina
 * para pelo tamanho da pagina.
 */
function tp_total($r): ?int
{
    if (!is_array($r)) {
        return null;
    }
    foreach ([['entries', 'totalItems'], ['totalItems']] as $caminho) {
        $no = $r;
        foreach ($caminho as $chave) {
            $no = is_array($no) && isset($no[$chave]) ? $no[$chave] : null;
        }
        if (is_numeric($no)) {
            return (int) $no;
        }
    }
    return null;
}

/**
 * Uma pagina de cada vez ate acabar.
 *
 * A pagina pedida e enorme de proposito — o coletor do n8n ja descobriu que
 * eles entregam a loja inteira numa requisicao so. O laco fica mesmo assim,
 * para o dia em que o servidor decidir limitar a pagina por conta propria:
 * sem ele, esse dia chegaria como "metade do planograma sumiu".
 *
 * Quem manda na parada e o totalItems quando existe. Contar pelo tamanho da
 * pagina pararia cedo demais justamente no caso que importa, que e o servidor
 * devolver menos do que foi pedido.
 */
function tp_paginar(callable $url): array
{
    $itens = [];
    $total = null;

    for ($pagina = 1; $pagina <= 40; $pagina++) {
        $r = tp_chamar('GET', $url($pagina, 10000));
        $lista = pg_itens($r);
        $total = tp_total($r) ?? $total;
        if (!$lista) {
            break;
        }
        foreach ($lista as $item) {
            $itens[] = $item;
        }
        if ($total !== null ? count($itens) >= $total : count($lista) < 10000) {
            break;
        }
    }

    return $itens;
}

/**
 * O planograma INTEIRO, nao so o que casa com um bipe.
 *
 * A operacao de inventario (tp_operacao) quer a lista completa de itens, e e
 * daqui que sai o inventoryItemId de cada um.
 */
function tp_planograma_tudo(int $planograma_id): array
{
    return tp_paginar(static fn (int $p, int $n) =>
        '/api/Planograms/' . $planograma_id
        . '?page=' . $p . '&pageSize=' . $n
        . '&sortOrder=quantityToSupply&descending=true&search=&showOnlyCritical=false');
}

/**
 * O inventario INTEIRO de um ponto de venda.
 *
 * E o unico lugar onde a VALIDADE existe: o planograma nao a carrega. Mesma
 * URL do coletor do n8n, inclusive o timezoneOffset de 180 — validade e dia
 * cheio, e pedir com o fuso errado e como se pedisse o inventario de ontem.
 */
/**
 * A URL do inventario. Funcao pura, separada so para poder ser testada.
 *
 * O `date` vai com o INSTANTE de agora, nao com a meia-noite do dia. Eles
 * respondem a foto do estoque naquele momento: pedindo meia-noite, o numero
 * e o do comeco do dia e nao anda conforme o pessoal compra. E depois das
 * 21h de Brasilia a meia-noite UTC de "hoje" ja e de um dia que ainda nao
 * comecou aqui — pedir esse dia devolve um estoque que nao existe.
 *
 * Isto importa alem da leitura: a quantidade daqui vira `confirmedQuantity`
 * na operacao que grava validade. Confirmar a contagem da manha depois de um
 * dia de vendas mandaria o estoque de volta para o numero da manha.
 */
function tp_inventario_url(int $inventario_id, ?int $produto_id, int $pagina,
                           int $por_pagina, string $instante): string
{
    return '/api/web/inventory/items?page=' . $pagina . '&pageSize=' . $por_pagina
        . '&sortOrder=quantity&descending=false&search='
        . '&inventoryIds=' . $inventario_id
        . '&productId=' . ($produto_id > 0 ? $produto_id : '')
        . '&inventoryTypes=pointOfSale&date=' . rawurlencode($instante)
        . '&timezoneOffset=180&showTotals=false';
}

/** O instante de agora do jeito que eles querem: ISO 8601 UTC com milesimos. */
function tp_instante(): string
{
    return gmdate('Y-m-d\TH:i:s') . '.000Z';
}

function tp_inventario_tudo(int $inventario_id, ?int $produto_id = null): array
{
    $instante = tp_instante();
    return tp_paginar(static fn (int $p, int $n) =>
        tp_inventario_url($inventario_id, $produto_id, $p, $n, $instante));
}

/**
 * A URL das transacoes. Funcao pura, separada para poder ser testada.
 *
 * `sortOrder=date&descending=false` — do mais velho para o mais novo, e nao
 * e detalhe: e o que faz uma carga interrompida continuar de onde parou. Como
 * a janela seguinte nasce do MAX(data_hora) ja gravado, uma coleta que morra
 * na metade retoma sozinha; se viesse do mais novo para o mais velho, o
 * buraco ficaria no meio e ninguem perceberia.
 *
 * Os campos vazios vao todos explicitos, do jeito que o painel deles manda.
 */
function tp_transacoes_url(string $de, string $ate, int $pagina, int $por_pagina): string
{
    return '/api/Transactions?customerId=&localId=&pointOfSaleId=&paymentMethod='
        . '&minAmount=&maxAmount=&cardHolder=&cpf=&minTime=&maxTime=&productId='
        . '&onlyWithCpf=false&timezoneOffset=180&sortOrder=date&descending=false'
        . '&minDate=' . rawurlencode($de) . '&maxDate=' . rawurlencode($ate)
        . '&page=' . $pagina . '&pageSize=' . $por_pagina;
}

/**
 * Uma pagina de transacoes, com o total que o servidor diz ter.
 *
 * Mil por pagina: medido contra a conta real, mil transacoes voltam em ~600ms
 * e um ano inteiro (12,5 mil) sai em 13 requisicoes.
 *
 * @return array{itens:array, total:?int}
 */
function tp_transacoes_pagina(string $de, string $ate, int $pagina, int $por_pagina = 1000): array
{
    $r = tp_chamar('GET', tp_transacoes_url($de, $ate, $pagina, $por_pagina));
    return ['itens' => pg_itens($r), 'total' => tp_total($r)];
}

/**
 * Fecha uma operacao de inventario — e o unico jeito de gravar validade.
 *
 * Nao existe endpoint de "altera a validade deste item": o que existe e
 * fechar uma reposicao inteira, com a lista completa de itens, marcando os
 * que foram tocados. Quem monta o corpo e pg_operacao_montar(), que confere
 * a lista antes de deixar sair daqui.
 *
 * Responde 200 com CORPO VAZIO. Nao da para ler de volta o que ficou gravado:
 * quem quiser certeza rele o item depois.
 */
function tp_operacao(array $corpo): array
{
    return tp_chamar('POST', '/api/inventory/operation', $corpo);
}

/**
 * DEFINE o estoque de um item — nao soma.
 *
 * A quantidade vai na URL e o corpo e vazio. Conferido: `.../quantity/3` faz
 * o estoque passar a ser 3, seja qual for o numero que estava la. Quem quer
 * "entrou mais 3" soma ANTES de chamar; aqui ja chega o total que vai ficar.
 */
function tp_estoque_definir(int $pos_id, int $item_id, float $quantidade): array
{
    // Inteiro quando for inteiro: ".../quantity/3.0" nao e o que a tela deles
    // manda, e nao vale descobrir na pratica se o servidor aceita.
    $n = fmod($quantidade, 1.0) === 0.0
        ? (string) (int) $quantidade
        : rtrim(rtrim(number_format($quantidade, 3, '.', ''), '0'), '.');

    return tp_chamar('PUT', '/api/inventory/' . $pos_id . '/' . $item_id . '/quantity/' . $n);
}
