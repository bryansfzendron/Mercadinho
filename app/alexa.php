<?php
declare(strict_types=1);

/**
 * Skill da Alexa: "Alexa, pergunta pro mercadinho quanto vendeu hoje".
 *
 * A Amazon chama POST /alexa direto, sem Lambda no meio. O preco disso e que
 * a verificacao que a Lambda faria fica aqui: cada pedido vem assinado com um
 * certificado da Amazon, e o que nao passar por alexa_pedido_autentico() e
 * recusado antes de tocar no banco. Sem isso, qualquer um que achasse a URL
 * ouviria o faturamento da loja.
 *
 * Uso de uma pessoa so: nao ha vinculacao de conta. O config.php diz qual
 * skill pode chamar (alexa_skill_id) e de qual usuario do app sao os numeros
 * (alexa_usuario_id). A loja e a da conta TouchPay desse usuario, a mesma que
 * ele ve no app. A Alexa so le o que o sync ja trouxe: a senha do TouchPay
 * continua so no celular, e por isso toda resposta diz de quando sao os dados.
 *
 * As funcoes que montam a fala sao puras (testes/alexa.php). As que consultam
 * o banco so juntam as funcoes que as telas ja usam, para a Alexa e o app
 * nunca darem numeros diferentes.
 */

/** Tolerancia do carimbo de tempo do pedido, como a Amazon exige: 150 s. */
const ALEXA_TOLERANCIA_S = 150;

// ---------------------------------------------------------------------
// Autenticidade do pedido
// ---------------------------------------------------------------------

/**
 * A URL do certificado e da Amazon? Funcao pura.
 *
 * Regras da documentacao da Alexa: https, host s3.amazonaws.com (sem
 * diferenciar maiusculas), porta 443 se vier, e caminho comecando com
 * /echo.api/ (diferenciando) depois de resolver "..".
 */
function alexa_url_certificado_ok(string $url): bool
{
    $p = parse_url($url);
    if (!$p || strtolower($p['scheme'] ?? '') !== 'https') {
        return false;
    }
    if (strtolower($p['host'] ?? '') !== 's3.amazonaws.com') {
        return false;
    }
    if (isset($p['port']) && (int) $p['port'] !== 443) {
        return false;
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    // Resolve "." e ".." antes de olhar o prefixo: /echo.api/../x nao vale.
    $partes = [];
    foreach (explode('/', $p['path'] ?? '') as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($partes);
            continue;
        }
        $partes[] = $seg;
    }
    return ($partes[0] ?? '') === 'echo.api' && count($partes) > 1;
}

/** O carimbo do pedido esta dentro da tolerancia? Funcao pura. */
function alexa_horario_ok(string $carimbo, int $agora): bool
{
    $t = strtotime($carimbo);
    return $t !== false && abs($agora - $t) <= ALEXA_TOLERANCIA_S;
}

/** Os certificados de um PEM com a cadeia inteira, na ordem. Funcao pura. */
function alexa_pem_certificados(string $pem): array
{
    preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);
    return $m[0];
}

/** Onde o PHP desta maquina guarda as CAs raiz confiaveis. */
function alexa_cas_do_sistema(): array
{
    $loc = openssl_get_cert_locations();
    $cas = [];
    foreach (['default_cert_file', 'default_cert_dir'] as $k) {
        if (!empty($loc[$k]) && file_exists($loc[$k])) {
            $cas[] = $loc[$k];
        }
    }
    // Alguns PHPs apontam para um caminho que nao existe; o do sistema existe.
    foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt'] as $f) {
        if (!$cas && is_file($f)) {
            $cas[] = $f;
        }
    }
    return $cas;
}

/**
 * Valida a cadeia do certificado e devolve a chave publica para conferir a
 * assinatura. Null em qualquer duvida.
 *
 * O certificado folha tem de estar no prazo, ter echo-api.amazon.com no SAN e
 * fechar uma cadeia ate uma CA raiz confiavel, usando os intermediarios que
 * vieram no mesmo arquivo.
 *
 * @param string[] $cas arquivos/pastas de CAs raiz (injetavel para teste)
 */
function alexa_certificado_ok(string $pem, int $agora, array $cas)
{
    $certs = alexa_pem_certificados($pem);
    if (!$certs || !$cas) {
        return null;
    }
    $folha = openssl_x509_read($certs[0]);
    $info  = $folha ? openssl_x509_parse($folha) : false;
    if (!$info) {
        return null;
    }
    if ($agora < (int) $info['validFrom_time_t'] || $agora > (int) $info['validTo_time_t']) {
        return null;
    }
    $san = array_map('trim', explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')));
    if (!in_array('DNS:echo-api.amazon.com', $san, true)) {
        return null;
    }

    $intermediarios = null;
    if (count($certs) > 1) {
        $intermediarios = tempnam(sys_get_temp_dir(), 'alexa');
        file_put_contents($intermediarios, implode("\n", array_slice($certs, 1)) . "\n");
    }
    try {
        $confia = $intermediarios
            ? openssl_x509_checkpurpose($folha, X509_PURPOSE_ANY, $cas, $intermediarios)
            : openssl_x509_checkpurpose($folha, X509_PURPOSE_ANY, $cas);
    } finally {
        if ($intermediarios) {
            @unlink($intermediarios);
        }
    }
    if ($confia !== true) {
        return null;
    }
    return openssl_pkey_get_public($folha) ?: null;
}

/** A assinatura (base64, SHA-256) confere com o corpo cru? */
function alexa_assinatura_ok(string $corpo, string $assinatura_b64, $chave): bool
{
    $assinatura = base64_decode($assinatura_b64, true);
    if ($assinatura === false || $assinatura === '') {
        return false;
    }
    return openssl_verify($corpo, $assinatura, $chave, OPENSSL_ALGO_SHA256) === 1;
}

/**
 * Baixa o certificado da Amazon, guardando um dia no temp: a cadeia e a mesma
 * em todo pedido, e buscar no S3 a cada pergunta deixaria a resposta lenta.
 */
function alexa_baixar_certificado(string $url): ?string
{
    $cache = sys_get_temp_dir() . '/mercadinho-alexa-' . sha1($url) . '.pem';
    if (is_file($cache) && filemtime($cache) > time() - 86400) {
        $pem = (string) file_get_contents($cache);
        if ($pem !== '') {
            return $pem;
        }
    }
    $ctx = stream_context_create(['http' => ['timeout' => 5], 'ssl' => ['verify_peer' => true]]);
    $pem = @file_get_contents($url, false, $ctx);
    if (!is_string($pem) || !alexa_pem_certificados($pem)) {
        return null;
    }
    @file_put_contents($cache, $pem);
    return $pem;
}

/**
 * O pedido veio mesmo da Amazon, da nossa skill, agora? Devolve o motivo da
 * recusa, ou null quando passou.
 */
function alexa_pedido_autentico(string $corpo, array $pedido, array $cabecalhos, int $agora): ?string
{
    $skill = (string) cfg('alexa_skill_id', '');
    if ($skill === '' || !cfg('alexa_usuario_id')) {
        return 'skill nao configurada';
    }
    $url = (string) ($cabecalhos['signaturecertchainurl'] ?? '');
    $assinatura = (string) ($cabecalhos['signature-256'] ?? '');
    if ($url === '' || $assinatura === '' || !alexa_url_certificado_ok($url)) {
        return 'sem assinatura valida';
    }
    $pem = alexa_baixar_certificado($url);
    $chave = $pem ? alexa_certificado_ok($pem, $agora, alexa_cas_do_sistema()) : null;
    if (!$chave || !alexa_assinatura_ok($corpo, $assinatura, $chave)) {
        return 'assinatura recusada';
    }
    if (!alexa_horario_ok((string) ($pedido['request']['timestamp'] ?? ''), $agora)) {
        return 'pedido fora do horario';
    }
    $app = $pedido['context']['System']['application']['applicationId']
        ?? $pedido['session']['application']['applicationId'] ?? '';
    if ($app !== $skill) {
        return 'skill desconhecida';
    }
    // Opcional: prender a uma conta Amazon especifica, se a skill sair do
    // modo de desenvolvimento um dia.
    $donos = (array) cfg('alexa_user_ids', []);
    $quem = $pedido['context']['System']['user']['userId'] ?? $pedido['session']['user']['userId'] ?? '';
    if ($donos && !in_array($quem, $donos, true)) {
        return 'usuario da Alexa nao autorizado';
    }
    return null;
}

// ---------------------------------------------------------------------
// Fala (funcoes puras)
// ---------------------------------------------------------------------

/**
 * Dinheiro do jeito que se fala. Funcao pura.
 *
 * Centavos so abaixo de mil: "1.234 reais e 56 centavos" ninguem guarda
 * de ouvido, e o numero inteiro ja responde a pergunta.
 */
function alexa_reais(float $v): string
{
    $v = round($v, 2);
    if (abs($v) >= 1000) {
        $int = (int) round($v);
        return number_format($int, 0, ',', '.') . ' reais';
    }
    $int = (int) floor($v);
    $cent = (int) round(($v - $int) * 100);
    if ($cent === 100) {
        $int++;
        $cent = 0;
    }
    $txt = $int === 1 ? '1 real' : $int . ' reais';
    if ($int === 0 && $cent > 0) {
        return $cent . ($cent === 1 ? ' centavo' : ' centavos');
    }
    if ($cent > 0) {
        $txt .= ' e ' . $cent . ($cent === 1 ? ' centavo' : ' centavos');
    }
    return $txt;
}

/** "1 venda", "87 vendas". Funcao pura. */
function alexa_qtd(int $n, string $um, string $varios): string
{
    return $n . ' ' . ($n === 1 ? $um : $varios);
}

/** "A, B e C". Funcao pura. */
function alexa_lista(array $itens): string
{
    $itens = array_values($itens);
    if (count($itens) <= 1) {
        return (string) ($itens[0] ?? '');
    }
    $ultimo = array_pop($itens);
    return implode(', ', $itens) . ' e ' . $ultimo;
}

/**
 * Nome de produto para falar: o TouchPay manda tudo em maiusculas, e a Alexa
 * as vezes soletra palavra em maiusculas como sigla. Funcao pura.
 */
function alexa_nome_produto(string $descricao): string
{
    $s = trim(preg_replace('/\s+/', ' ', $descricao));
    return mb_strtolower($s);
}

/**
 * De quando sao os numeros. Funcao pura.
 *
 * O sync so roda enquanto o token da conta vive (cerca de 1h depois do ultimo
 * uso do app), entao numero velho e normal, e a Alexa tem de dizer.
 *
 * @return array{texto:string, velho:bool}
 */
function alexa_frescor(?string $quando, int $agora): array
{
    $t = $quando ? strtotime($quando) : false;
    if (!$t) {
        return ['texto' => 'Ainda não tem nenhuma sincronização com o TouchPay.', 'velho' => true];
    }
    $hora = (int) date('G', $t);
    $min  = (int) date('i', $t);
    $h    = $min === 0 ? $hora . ' horas' : $hora . ' e ' . $min;
    $velho = ($agora - $t) > 2 * 3600;
    if (date('Y-m-d', $t) === date('Y-m-d', $agora)) {
        $texto = 'Dados atualizados às ' . $h . '.';
    } else {
        $texto = 'Dados atualizados em ' . alexa_data_falada(date('Y-m-d', $t)) . ', às ' . $h . '.';
    }
    if ($velho) {
        $texto .= ' Abra o app para atualizar.';
    }
    return ['texto' => $texto, 'velho' => $velho];
}

/** Variacao em relacao a outro numero, falada. Funcao pura. */
function alexa_variacao(float $agora, float $antes, string $quando): string
{
    if ($antes <= 0) {
        return '';
    }
    $pct = (int) round(($agora - $antes) / $antes * 100);
    if ($pct === 0) {
        return ' Igual a ' . $quando . '.';
    }
    return ' ' . abs($pct) . '% ' . ($pct > 0 ? 'a mais' : 'a menos') . ' que ' . $quando . '.';
}

/**
 * Fala das vendas de hoje. Funcao pura.
 *
 * A comparacao e com o mesmo dia da semana passada ATE A MESMA HORA: as 11h
 * de hoje contra o dia inteiro da quarta passada daria sempre "menos".
 */
function alexa_fala_hoje(float $total, int $vendas, ?float $semana_passada, string $frescor): string
{
    if ($vendas === 0) {
        $txt = 'Hoje ainda não tem venda registrada.';
    } else {
        $txt = 'Hoje o mercadinho vendeu ' . alexa_reais($total) . ' em ' . alexa_qtd($vendas, 'venda', 'vendas') . '.';
        if ($semana_passada !== null) {
            $txt .= alexa_variacao($total, $semana_passada, 'no mesmo horário da semana passada');
        }
    }
    return $txt . ' ' . $frescor;
}

/** Fala das vendas de um periodo fechado. Funcao pura. */
function alexa_fala_periodo(string $rotulo, float $total, int $vendas, int $dias): string
{
    if ($vendas === 0) {
        return ucfirst($rotulo) . ' não tem venda registrada.';
    }
    $txt = ucfirst($rotulo) . ' o mercadinho vendeu ' . alexa_reais($total)
         . ' em ' . alexa_qtd($vendas, 'venda', 'vendas') . '.';
    if ($dias > 1) {
        $txt .= ' Média de ' . alexa_reais($total / $dias) . ' por dia.';
    }
    return $txt;
}

/**
 * Fala da meta do mes. Funcao pura.
 *
 * @param array $prog meta_progresso() com 'restam'
 */
function alexa_fala_meta(array $prog, string $mes): string
{
    if (!$prog['tem_meta']) {
        return 'Não tem meta de faturamento cadastrada para ' . $mes . '. O mês está em '
             . alexa_reais($prog['realizado']) . ', e no ritmo atual fecha em ' . alexa_reais($prog['projecao']) . '.';
    }
    $txt = 'A meta de ' . $mes . ' é ' . alexa_reais($prog['meta']) . '. Até agora entraram '
         . alexa_reais($prog['realizado']) . ', ' . (int) round($prog['pct']) . '% da meta.';
    if ($prog['batida']) {
        return $txt . ' A meta já foi batida!';
    }
    $txt .= ' No ritmo atual, o mês fecha em ' . alexa_reais($prog['projecao']) . '.';
    if ($prog['restam'] > 0) {
        $txt .= ' Para bater, faltam ' . alexa_reais($prog['falta']) . ', ou '
              . alexa_reais($prog['por_dia']) . ' por dia nos ' . alexa_qtd((int) $prog['restam'], 'dia', 'dias') . ' que restam.';
    } else {
        $txt .= ' Faltam ' . alexa_reais($prog['falta']) . ' para bater hoje.';
    }
    return $txt;
}

/**
 * Fala dos mais vendidos. Funcao pura.
 *
 * @param array $lista de vendas_produtos_juntar(), ja ordenada
 */
function alexa_fala_mais_vendidos(string $rotulo, array $lista): string
{
    if (!$lista) {
        return ucfirst($rotulo) . ' não tem venda registrada.';
    }
    $partes = [];
    foreach (array_slice($lista, 0, 3) as $p) {
        $partes[] = alexa_nome_produto((string) $p['descricao']) . ', '
                  . qtd_fmt($p['quantidade']) . ((float) $p['quantidade'] == 1.0 ? ' unidade' : ' unidades')
                  . ', ' . alexa_reais((float) $p['total']);
    }
    $txt = ucfirst($rotulo) . ' o que mais vendeu foi ' . array_shift($partes) . '.';
    if ($partes) {
        $txt .= ' Depois vêm ' . alexa_lista($partes) . '.';
    }
    return $txt;
}

/**
 * Fala da validade: o que ja venceu e o que vence em ate 7 dias, com
 * estoque. Funcao pura.
 *
 * @param array $faixas de loja_faixas_validade()
 */
function alexa_fala_validade(array $faixas, ?string $hoje = null): string
{
    $por = [];
    foreach ($faixas as $f) {
        $por[$f['chave']] = $f['itens'];
    }
    // O mesmo produto em dois PDVs e uma linha por PDV, mas para quem ouve e
    // um produto so: conta e fala cada nome uma vez, com o prazo mais curto
    // (a lista ja vem em ordem de validade).
    $unicos = static function (array $itens, bool $com_prazo) use ($hoje): array {
        $vistos = [];
        foreach ($itens as $l) {
            $nome = alexa_nome_produto((string) $l['descricao']);
            if (isset($vistos[$nome])) {
                continue;
            }
            $e = validade_estado($l['validade'] ?? null, $hoje);
            $vistos[$nome] = $com_prazo && $e['dias'] !== null
                ? $nome . ($e['dias'] === 0 ? ' vence hoje' : ($e['dias'] === 1 ? ' vence amanhã' : ' vence em ' . $e['dias'] . ' dias'))
                : $nome;
        }
        return array_values($vistos);
    };
    $falar = static function (array $lista): string {
        $resto = count($lista) - 5;
        return alexa_lista(array_slice($lista, 0, 5)) . ($resto > 0 ? ', e mais ' . $resto : '');
    };

    $txt = [];
    if (!empty($por['vencido'])) {
        $lista = $unicos($por['vencido'], false);
        $n = count($lista);
        $txt[] = ($n === 1 ? 'Tem 1 produto vencido em estoque: ' : 'Tem ' . $n . ' produtos vencidos em estoque: ')
               . $falar($lista) . '.';
    }
    if (!empty($por['semana'])) {
        $lista = $unicos($por['semana'], true);
        $n = count($lista);
        $txt[] = ($n === 1 ? 'Nos próximos 7 dias, ' : 'Nos próximos 7 dias vencem ' . $n . ' produtos: ')
               . $falar($lista) . '.';
    }
    if (!$txt) {
        $mes = count($por['mes'] ?? []);
        return 'Nenhum produto vencido nem vencendo esta semana.'
             . ($mes ? ' ' . ($mes === 1 ? '1 produto vence' : $mes . ' produtos vencem') . ' nos próximos 30 dias.' : '');
    }
    return implode(' ', $txt);
}

/**
 * Hora do slot AMAZON.TIME ("15:30") em "HH:MM". Funcao pura.
 *
 * "MO", "AF", "EV", "NI" (manha, tarde...) e "15" sozinho nao dizem a hora
 * certa: null, e a Alexa pede o horario.
 */
function alexa_hora(string $valor): ?string
{
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($valor), $m)) {
        return null;
    }
    return sprintf('%02d:%s', (int) $m[1], $m[2]);
}

/** "15:32" -> "15 e 32", "15:00" -> "15 horas". Funcao pura. */
function alexa_hora_falada(string $hhmm): string
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $m === 0 ? $h . ' horas' : $h . ' e ' . $m;
}

/** Janela de busca da compra, em minutos para cada lado da hora pedida. */
const ALEXA_JANELA_MIN = 10;

/**
 * Fala de uma compra: hora, valor, forma de pagamento e itens. Funcao pura.
 *
 * @param array  $vendas linhas de vendas ja ordenadas da mais proxima da hora
 *                       pedida para a mais distante (data_hora, valor_pago, forma_pagamento)
 * @param array  $itens  itens da primeira venda (descricao, quantidade)
 * @param string $quando "hoje", "ontem" ou "em 5 de novembro"
 */
function alexa_fala_compra(array $vendas, array $itens, string $quando, string $hora_pedida): string
{
    if (!$vendas) {
        return 'Não achei nenhuma venda perto das ' . alexa_hora_falada($hora_pedida) . ' ' . $quando
             . ', numa janela de ' . ALEXA_JANELA_MIN . ' minutos.';
    }
    $v = $vendas[0];
    $hhmm = date('H:i', strtotime((string) $v['data_hora']));
    $txt = 'Às ' . alexa_hora_falada($hhmm) . ' ' . $quando . ', uma venda de ' . alexa_reais((float) $v['valor_pago']);
    $forma = mb_strtolower(trim((string) ($v['forma_pagamento'] ?? '')));
    if ($forma !== '') {
        $txt .= ' no ' . $forma;
    }
    if (!$itens) {
        $txt .= ', mas sem a lista de itens.';
    } else {
        $partes = [];
        foreach ($itens as $i) {
            $q = (float) $i['quantidade'];
            $partes[] = qtd_fmt($q) . ' ' . alexa_nome_produto((string) $i['descricao']);
        }
        $resto = count($partes) - 5;
        $txt .= ': ' . alexa_lista(array_slice($partes, 0, 5)) . ($resto > 0 ? ', e mais ' . $resto : '') . '.';
    }
    if (count($vendas) > 1) {
        $txt .= ' Tem mais ' . alexa_qtd(count($vendas) - 1, 'venda', 'vendas')
              . ' nessa janela. Diga o horário mais exato para escolher outra.';
    }
    return $txt;
}

// ---------------------------------------------------------------------
// Periodo falado (AMAZON.DATE)
// ---------------------------------------------------------------------

const ALEXA_MESES = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                     'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/** '2026-11-05' -> "5 de novembro". Funcao pura. */
function alexa_data_falada(string $iso): string
{
    $t = strtotime($iso);
    return (int) date('j', $t) . ' de ' . ALEXA_MESES[(int) date('n', $t)];
}

/**
 * Traduz o valor do slot AMAZON.DATE num intervalo. Funcao pura.
 *
 * A Alexa manda "2026-11-10" (dia), "2026-W45" (semana ISO), "2026-W45-WE"
 * (fim de semana), "2026-11" (mes), "2026" (ano) ou "PRESENT_REF" (agora).
 * Dia da semana dito sozinho ("segunda") ela resolve para a PROXIMA; quem
 * pergunta quanto vendeu fala da passada, entao o futuro proximo volta 7
 * dias, e o mes "outubro" dito em novembro volta um ano.
 *
 * @return array{de:string, ate:string, rotulo:string}|null null = nao entendi
 *         ou e futuro
 */
function alexa_periodo(string $valor, string $hoje): ?array
{
    $meses = ALEXA_MESES;
    $h = new DateTimeImmutable($hoje);
    $valor = trim($valor);

    if ($valor === '' || $valor === 'PRESENT_REF') {
        return ['de' => $hoje, 'ate' => $hoje, 'rotulo' => 'hoje'];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor)) {
        $d = new DateTimeImmutable($valor);
        if ($d > $h && $d <= $h->modify('+7 days')) {
            $d = $d->modify('-7 days');
        }
        if ($d > $h) {
            return null;
        }
        $iso = $d->format('Y-m-d');
        $diff = (int) $h->diff($d)->days;
        $rotulo = $diff === 0 ? 'hoje' : ($diff === 1 ? 'ontem' : 'em ' . alexa_data_falada($iso));
        return ['de' => $iso, 'ate' => $iso, 'rotulo' => $rotulo];
    }
    if (preg_match('/^(\d{4})-W(\d{2})(-WE)?$/', $valor, $m)) {
        $seg = (new DateTimeImmutable())->setISODate((int) $m[1], (int) $m[2], 1);
        [$de, $ate] = !empty($m[3])
            ? [$seg->modify('+5 days'), $seg->modify('+6 days')]
            : [$seg, $seg->modify('+6 days')];
        if ($de > $h) {
            return null;
        }
        $semana_atual = $h->format('o-W') === $seg->format('o-W');
        $passada = $h->modify('-7 days')->format('o-W') === $seg->format('o-W');
        if (!empty($m[3])) {
            $rotulo = $semana_atual ? 'neste fim de semana' : ($passada ? 'no fim de semana passado' : 'no fim de semana de ' . alexa_data_falada($de->format('Y-m-d')));
        } else {
            $rotulo = $semana_atual ? 'nesta semana' : ($passada ? 'na semana passada' : 'na semana de ' . alexa_data_falada($de->format('Y-m-d')));
        }
        return ['de' => $de->format('Y-m-d'), 'ate' => min($ate->format('Y-m-d'), $hoje), 'rotulo' => $rotulo];
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $valor, $m)) {
        $ini = new DateTimeImmutable($valor . '-01');
        if ($ini > $h) {
            $ini = $ini->modify('-1 year');
        }
        if ($ini > $h) {
            return null;
        }
        $mes = $meses[(int) $ini->format('n')];
        $rotulo = $ini->format('Y-m') === $h->format('Y-m') ? 'neste mês'
                : ($ini->format('Y') === $h->format('Y') ? 'em ' . $mes : 'em ' . $mes . ' de ' . $ini->format('Y'));
        return ['de' => $ini->format('Y-m-01'), 'ate' => min($ini->format('Y-m-t'), $hoje), 'rotulo' => $rotulo];
    }
    if (preg_match('/^(\d{4})$/', $valor)) {
        if ($valor > $h->format('Y')) {
            return null;
        }
        return ['de' => $valor . '-01-01', 'ate' => min($valor . '-12-31', $hoje),
                'rotulo' => $valor === $h->format('Y') ? 'neste ano' : 'em ' . $valor];
    }
    return null;
}

/** Dias corridos de um intervalo, contando as pontas. Funcao pura. */
function alexa_dias(string $de, string $ate): int
{
    return (int) (new DateTimeImmutable($de))->diff(new DateTimeImmutable($ate))->days + 1;
}

// ---------------------------------------------------------------------
// Respostas no formato da Alexa
// ---------------------------------------------------------------------

/** @param ?string $pergunta com ela, a sessao fica aberta esperando resposta */
function alexa_resposta(string $texto, ?string $pergunta = null): array
{
    $r = [
        'outputSpeech'     => ['type' => 'PlainText', 'text' => $pergunta ? $texto . ' ' . $pergunta : $texto],
        'card'             => ['type' => 'Simple', 'title' => 'Mercadinho', 'content' => $texto],
        'shouldEndSession' => $pergunta === null,
    ];
    if ($pergunta) {
        $r['reprompt'] = ['outputSpeech' => ['type' => 'PlainText', 'text' => $pergunta]];
    }
    return ['version' => '1.0', 'response' => $r];
}

const ALEXA_AJUDA = 'Você pode perguntar quanto vendeu hoje, ontem ou no mês, como está a meta, '
                  . 'o que mais vendeu, o que está vencendo, ou o que foi comprado num dia e horário.';

/** O valor de um slot, ou ''. Funcao pura. */
function alexa_slot(array $pedido, string $nome): string
{
    return (string) ($pedido['request']['intent']['slots'][$nome]['value'] ?? '');
}

/**
 * Decide o que responder. As consultas entram por $fontes para o teste poder
 * trocar o banco por numeros fixos.
 *
 * @param array<string,callable> $fontes hoje, periodo, meta, mais_vendidos, validade, compra
 */
function alexa_atender(array $pedido, array $fontes, string $hoje): array
{
    $tipo = (string) ($pedido['request']['type'] ?? '');
    if ($tipo === 'LaunchRequest') {
        return alexa_resposta($fontes['hoje'](), 'O que mais você quer saber?');
    }
    if ($tipo === 'SessionEndedRequest') {
        return ['version' => '1.0', 'response' => new stdClass()];
    }
    if ($tipo !== 'IntentRequest') {
        return alexa_resposta('Não entendi o pedido.');
    }

    $intent = (string) ($pedido['request']['intent']['name'] ?? '');
    switch ($intent) {
        case 'VendasHojeIntent':
            return alexa_resposta($fontes['hoje']());
        case 'VendasPeriodoIntent':
        case 'MaisVendidosIntent':
            $valor = alexa_slot($pedido, 'periodo');
            $p = alexa_periodo($valor, $hoje);
            if (!$p) {
                return alexa_resposta('Não entendi o período. Tente ontem, semana passada ou o nome do mês.');
            }
            if ($intent === 'MaisVendidosIntent') {
                return alexa_resposta($fontes['mais_vendidos']($p));
            }
            if ($p['de'] === $hoje && $p['ate'] === $hoje) {
                return alexa_resposta($fontes['hoje']());
            }
            return alexa_resposta($fontes['periodo']($p));
        case 'CompraIntent':
            $hora = alexa_hora(alexa_slot($pedido, 'hora'));
            if ($hora === null) {
                return alexa_resposta('Preciso do horário da compra, por exemplo: às 15 e 30.', 'Qual o horário?');
            }
            $p = alexa_periodo(alexa_slot($pedido, 'dia'), $hoje);
            if (!$p || $p['de'] !== $p['ate']) {
                return alexa_resposta('Não entendi o dia. Tente hoje, ontem ou uma data.', 'Qual o dia?');
            }
            return alexa_resposta($fontes['compra']($p, $hora));
        case 'MetaIntent':
            return alexa_resposta($fontes['meta']());
        case 'ValidadeIntent':
            return alexa_resposta($fontes['validade']());
        case 'AMAZON.HelpIntent':
            return alexa_resposta(ALEXA_AJUDA, 'O que você quer saber?');
        case 'AMAZON.StopIntent':
        case 'AMAZON.CancelIntent':
        case 'AMAZON.NavigateHomeIntent':
            return alexa_resposta('Até mais!');
        case 'AMAZON.FallbackIntent':
        default:
            return alexa_resposta('Isso eu ainda não sei responder. ' . ALEXA_AJUDA, 'O que você quer saber?');
    }
}

// ---------------------------------------------------------------------
// Consultas (banco)
// ---------------------------------------------------------------------

/** Total e quantidade de vendas entre dois instantes, com o corte do relatorio. */
function alexa_vendas_entre(string $de_dia, string $ate_datahora): array
{
    [$onde, $args] = vendas_filtro_sql(['de' => $de_dia]);
    $onde .= ' AND v.data_hora <= ?';
    $args[] = $ate_datahora;
    $r = q1('SELECT COUNT(*) AS n, COALESCE(SUM(v.valor_pago), 0) AS total FROM vendas v WHERE ' . $onde, $args);
    return ['total' => (float) ($r['total'] ?? 0), 'vendas' => (int) ($r['n'] ?? 0)];
}

/** As consultas de verdade, para alexa_atender(). */
function alexa_fontes(int $agora): array
{
    $hoje = date('Y-m-d', $agora);
    $ultimo = sync_ultimo();
    $frescor = alexa_frescor($ultimo, $agora)['texto'];

    return [
        'hoje' => static function () use ($hoje, $agora, $ultimo, $frescor): string {
            $p = vendas_painel($hoje)['hoje'];
            // A semana passada ate a hora dos dados de hoje, nao ate agora: se
            // o sync parou as 10h, hoje so tem ate as 10h.
            $corte = $ultimo && date('Y-m-d', strtotime($ultimo)) === $hoje ? strtotime($ultimo) : $agora;
            $antes = alexa_vendas_entre(
                date('Y-m-d', $corte - 7 * 86400),
                date('Y-m-d H:i:s', $corte - 7 * 86400)
            );
            return alexa_fala_hoje((float) $p['total'], (int) $p['vendas'], $antes['vendas'] ? $antes['total'] : null, $frescor);
        },
        'periodo' => static function (array $p): string {
            [$onde, $args] = vendas_filtro_sql(['de' => $p['de'], 'ate' => $p['ate']]);
            $r = q1('SELECT COUNT(*) AS n, COALESCE(SUM(v.valor_pago), 0) AS total FROM vendas v WHERE ' . $onde, $args);
            return alexa_fala_periodo($p['rotulo'], (float) $r['total'], (int) $r['n'], alexa_dias($p['de'], $p['ate']));
        },
        'meta' => static function () use ($hoje): string {
            // A mesma conta da tela de Metas (rota_metas), para todos os PDVs.
            $de = date('Y-m-01', strtotime($hoje));
            $r = vendas_relatorio(['de' => $de, 'ate' => $hoje, 'agrupar' => 'produto']);
            [$dia, $no_mes] = metas_dias($hoje);
            $alvos = metas_alvos(0, custos_parametros(), metas_do_pdv(0), metas_de_todos_pdvs($hoje));
            $prog = meta_progresso((float) $r['resultado']['receita'], $alvos['faturamento'], $dia, $no_mes);
            return alexa_fala_meta($prog, ALEXA_MESES[(int) date('n', strtotime($hoje))]);
        },
        'mais_vendidos' => static function (array $p): string {
            $cru = vendas_produtos_por_dia($p['de'], $p['ate']);
            return alexa_fala_mais_vendidos($p['rotulo'], vendas_produtos_juntar($cru, $p['de'], $p['ate'], 3));
        },
        'compra' => static function (array $p, string $hora): string {
            $alvo = strtotime($p['de'] . ' ' . $hora . ':00');
            $j = ALEXA_JANELA_MIN * 60;
            [$onde, $args] = vendas_filtro_sql([]);
            $onde .= ' AND v.data_hora BETWEEN ? AND ?';
            array_push($args, date('Y-m-d H:i:s', $alvo - $j), date('Y-m-d H:i:59', $alvo + $j));
            // Hora cheia pedida ("15 e 30") vale o minuto inteiro: 15:30:00 a 15:30:59.
            $vendas = q(
                'SELECT v.id, v.data_hora, v.valor_pago, v.forma_pagamento
                   FROM vendas v WHERE ' . $onde . '
               ORDER BY ABS(TIMESTAMPDIFF(SECOND, v.data_hora, ?)), v.id LIMIT 10',
                array_merge($args, [date('Y-m-d H:i:30', $alvo)])
            );
            $itens = $vendas ? (vendas_itens_das([(int) $vendas[0]['id']])[(int) $vendas[0]['id']] ?? []) : [];
            return alexa_fala_compra($vendas, $itens, $p['rotulo'], $hora);
        },
        'validade' => static function () use ($hoje): string {
            // So o que tem estoque: produto vencido que ja saiu da prateleira
            // nao e tarefa de ninguem.
            $itens = loja_listar('', 0, 'validade', 'com', 400);
            return alexa_fala_validade(loja_faixas_validade($itens, $hoje), $hoje);
        },
    ];
}

/** POST /alexa */
function rota_alexa(): void
{
    $corpo = file_get_contents('php://input') ?: '';
    $pedido = json_decode($corpo, true);
    if (!is_array($pedido)) {
        json_resposta(['erro' => 'corpo invalido'], 400);
    }
    $cabecalhos = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
    // Sem getallheaders (FastCGI antigo), os cabecalhos vem em $_SERVER.
    $cabecalhos += [
        'signaturecertchainurl' => $_SERVER['HTTP_SIGNATURECERTCHAINURL'] ?? '',
        'signature-256'         => $_SERVER['HTTP_SIGNATURE_256'] ?? '',
    ];

    $agora = time();
    $recusa = alexa_pedido_autentico($corpo, $pedido, $cabecalhos, $agora);
    if ($recusa !== null) {
        error_log('[mercadinho] alexa recusada: ' . $recusa);
        json_resposta(['erro' => 'nao autorizado'], 400);
    }

    $usuario_id = (int) cfg('alexa_usuario_id');
    $conta = tp_conta_do_usuario($usuario_id)['conta_id'] ?? null;
    conta_definir($conta, $usuario_id);
    if ($conta === null && conta_separada()) {
        json_resposta(alexa_resposta('Seu usuário do mercadinho não tem conta do TouchPay conectada. '
            . 'Conecte em Configurações, TouchPay, no app.'));
    }

    try {
        $resposta = alexa_atender($pedido, alexa_fontes($agora), date('Y-m-d', $agora));
    } catch (Throwable $e) {
        error_log('[mercadinho] alexa: ' . $e->getMessage());
        $resposta = alexa_resposta('Tive um problema para consultar o mercadinho. Tente de novo daqui a pouco.');
    }
    json_resposta($resposta);
}
