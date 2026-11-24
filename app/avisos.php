<?php
declare(strict_types=1);

/**
 * Avisos por notificacao (Web Push): validade chegando e margem baixa.
 *
 * O envio e um push SEM corpo: so acorda o aparelho. O service worker (sw.js)
 * busca o texto em /api/push/mensagem, que responde pelo hash do endpoint do
 * proprio aparelho. Assim nao precisa da criptografia do corpo (RFC 8291) nem
 * de biblioteca: o PHP so assina o cabecalho VAPID (ES256) com o openssl.
 *
 * Tudo aqui tolera a falta das tabelas (setup.php ainda nao rodou): vira "nao
 * configurado" em vez de erro 500.
 */

/** As tabelas dos avisos existem? */
function avisos_tabelas_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            q1('SELECT 1 FROM push_inscricoes LIMIT 1');
            q1('SELECT 1 FROM aviso_config LIMIT 1');
            q1('SELECT 1 FROM aviso_envios LIMIT 1');
            q1('SELECT 1 FROM push_chave LIMIT 1');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Base64 "url safe", sem padding: o formato do VAPID e da Web Push. */
function push_b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * O par de chaves VAPID, criado na primeira vez e guardado no banco.
 *
 * @return array{publica:string, privada:string} publica em base64url (ponto
 *         nao comprimido, 65 bytes) e privada em PEM
 */
function push_chave(): array
{
    $l = q1('SELECT publica, privada FROM push_chave WHERE id = 1');
    if ($l) {
        return ['publica' => $l['publica'], 'privada' => $l['privada']];
    }
    $par = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($par === false) {
        throw new RuntimeException('O PHP nao conseguiu gerar a chave VAPID (openssl).');
    }
    openssl_pkey_export($par, $pem);
    $ec = openssl_pkey_get_details($par)['ec'];
    $publica = push_b64u("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
    exec_sql('INSERT IGNORE INTO push_chave (id, publica, privada, criado_em) VALUES (1, ?, ?, ?)',
        [$publica, $pem, date('Y-m-d H:i:s')]);
    $l = q1('SELECT publica, privada FROM push_chave WHERE id = 1');
    return ['publica' => $l['publica'], 'privada' => $l['privada']];
}

/** Assinatura DER do openssl -> r||s de 64 bytes, que e o que o JWT ES256 pede. Funcao pura. */
function push_der_para_raw(string $der): string
{
    $pos = 2;
    if ((ord($der[1]) & 0x80) !== 0) {
        $pos += ord($der[1]) & 0x7f;
    }
    $partes = [];
    for ($i = 0; $i < 2; $i++) {
        $tam = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $tam);
        $pos += 2 + $tam;
        $partes[] = str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
    }
    return $partes[0] . $partes[1];
}

/** O JWT do VAPID para o servidor de push deste endpoint. */
function push_jwt(string $endpoint, array $chave, ?int $agora = null): string
{
    $p = parse_url($endpoint);
    $aud = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    $base = (string) cfg('base_url', '');
    $sub = str_starts_with($base, 'https://') ? rtrim($base, '/') : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $cab = push_b64u((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $cor = push_b64u((string) json_encode(['aud' => $aud, 'exp' => ($agora ?? time()) + 12 * 3600, 'sub' => $sub]));
    $chv = openssl_pkey_get_private($chave['privada']);
    if (!openssl_sign($cab . '.' . $cor, $der, $chv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Falha ao assinar o VAPID.');
    }
    return $cab . '.' . $cor . '.' . push_b64u(push_der_para_raw($der));
}

/**
 * Acorda um aparelho. Devolve o status HTTP (0 = sem resposta).
 * 404 e 410 dizem que a inscricao morreu (app desinstalado, permissao tirada): apaga.
 */
function push_enviar(array $insc): int
{
    $chave = push_chave();
    $ch = curl_init($insc['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: vapid t=' . push_jwt($insc['endpoint'], $chave) . ', k=' . $chave['publica'],
            'TTL: 86400',
            'Urgency: normal',
            'Content-Length: 0',
        ],
    ]);
    curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http === 404 || $http === 410) {
        exec_sql('DELETE FROM push_inscricoes WHERE id = ?', [$insc['id']]);
    }
    return $http;
}

/**
 * Manda um aviso a todos os aparelhos da conta. O texto fica na inscricao
 * para o service worker buscar. Devolve quantos aparelhos aceitaram.
 */
function push_notificar(int $conta, string $titulo, string $corpo, string $url = '/'): int
{
    $n = 0;
    foreach (q('SELECT * FROM push_inscricoes WHERE conta_id = ?', [$conta]) as $i) {
        exec_sql('UPDATE push_inscricoes SET titulo = ?, corpo = ?, url = ?, enviado_em = ? WHERE id = ?',
            [mb_substr($titulo, 0, 160), mb_substr($corpo, 0, 400), $url, date('Y-m-d H:i:s'), $i['id']]);
        $http = push_enviar($i);
        if ($http >= 200 && $http < 300) {
            $n++;
        } else {
            error_log('[avisos] push ' . $http . ' para inscricao ' . $i['id']);
        }
    }
    return $n;
}

/** A conta como o banco guarda: 0 quando o app ainda e de uma loja so. */
function avisos_conta(?int $conta = null): int
{
    return (int) ($conta ?? conta_atual() ?? 0);
}

/** @return array{ligado:bool, dias_validade:int, fator_minimo:float} */
function avisos_config(?int $conta = null): array
{
    $padrao = ['ligado' => true, 'dias_validade' => 10, 'fator_minimo' => 2.0];
    try {
        $l = q1('SELECT ligado, dias_validade, fator_minimo FROM aviso_config WHERE conta_id = ?', [avisos_conta($conta)]);
    } catch (Throwable $e) {
        return $padrao;
    }
    return $l ? ['ligado' => (bool) $l['ligado'], 'dias_validade' => (int) $l['dias_validade'],
                 'fator_minimo' => (float) $l['fator_minimo']] : $padrao;
}

function avisos_salvar(bool $ligado, int $dias, float $fator, ?int $conta = null): void
{
    $dias  = max(1, min(365, $dias));
    $fator = max(0.1, min(99.0, $fator));
    exec_sql(
        'INSERT INTO aviso_config (conta_id, ligado, dias_validade, fator_minimo) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE ligado = VALUES(ligado), dias_validade = VALUES(dias_validade),
                                 fator_minimo = VALUES(fator_minimo)',
        [avisos_conta($conta), $ligado ? 1 : 0, $dias, $fator]
    );
}

/**
 * Itens com estoque e validade vencida ou a vencer em ate $dias.
 *
 * @return array{vencidos:int, vencendo:int, nomes:string[]} nomes: os 3 que vencem primeiro
 */
function avisos_validade(int $dias, ?string $hoje = null): array
{
    $hoje = $hoje ?? date('Y-m-d');
    $ate  = date('Y-m-d', strtotime($hoje . ' +' . $dias . ' days'));
    $linhas = q(
        'SELECT li.descricao, li.validade
           FROM loja_itens li
           JOIN loja_pdvs p ON p.id = li.pdv_id
          WHERE p.ativo = 1 AND p.unificado_para IS NULL AND ' . conta_sql('p.conta_id') . '
            AND li.validade IS NOT NULL AND li.estoque > 0 AND li.validade <= ?
       ORDER BY li.validade ASC',
        [$ate]
    );
    $venc = $vendo = 0;
    $nomes = [];
    foreach ($linhas as $l) {
        if ($l['validade'] < $hoje) {
            $venc++;
        } else {
            $vendo++;
        }
        if (count($nomes) < 3 && !in_array($l['descricao'], $nomes, true)) {
            $nomes[] = $l['descricao'];
        }
    }
    return ['vencidos' => $venc, 'vencendo' => $vendo, 'nomes' => $nomes];
}

/** O texto do aviso de validade. Funcao pura. @return array{titulo:string, corpo:string} */
function avisos_texto_validade(array $r, int $dias): array
{
    $partes = [];
    if ($r['vencidos'] > 0) {
        $partes[] = $r['vencidos'] . ($r['vencidos'] === 1 ? ' vencido' : ' vencidos');
    }
    if ($r['vencendo'] > 0) {
        $partes[] = $r['vencendo'] . ($r['vencendo'] === 1 ? ' vence' : ' vencem') . ' em até ' . $dias . ' dias';
    }
    return [
        'titulo' => 'Validade: ' . implode(' · ', $partes),
        'corpo'  => implode(', ', $r['nomes']) . ($r['vencidos'] + $r['vencendo'] > count($r['nomes']) ? ' e outros' : ''),
    ];
}

/**
 * Produtos com fator (venda / custo) abaixo do minimo escolhido.
 *
 * @return array{total:int, nomes:string[]} nomes: os 3 piores
 */
function avisos_margem(float $minimo): array
{
    $total = 0;
    $nomes = [];
    foreach (margens_listar('') as $i) { // ja vem do pior para o melhor
        if ($i['fator'] === null || $i['fator'] >= $minimo) {
            continue;
        }
        $total++;
        if (count($nomes) < 3 && !in_array($i['descricao'], $nomes, true)) {
            $nomes[] = $i['descricao'];
        }
    }
    return ['total' => $total, 'nomes' => $nomes];
}

/** O texto do aviso de margem. Funcao pura. @return array{titulo:string, corpo:string} */
function avisos_texto_margem(array $r, float $minimo): array
{
    return [
        'titulo' => 'Margem: ' . $r['total'] . ($r['total'] === 1 ? ' produto abaixo de ' : ' produtos abaixo de ')
                    . number_format($minimo, 2, ',', '.') . 'x',
        'corpo'  => implode(', ', $r['nomes']) . ($r['total'] > count($r['nomes']) ? ' e outros' : ''),
    ];
}

/**
 * O que o cron faz a cada passada: se for a hora e ainda nao avisou hoje,
 * confere e manda. Um aviso de cada tipo por dia, a partir das 8h.
 *
 * @return string[] linhas para o log do cron
 */
function avisos_rodar(?int $conta, bool $forcar = false): array
{
    if (!avisos_tabelas_ok()) {
        return [];
    }
    $id  = avisos_conta($conta);
    $cfg = avisos_config($conta);
    if (!$cfg['ligado'] || (!$forcar && (int) date('G') < 8)) {
        return [];
    }
    if (!q1('SELECT 1 FROM push_inscricoes WHERE conta_id = ? LIMIT 1', [$id])) {
        return [];
    }

    $hoje = date('Y-m-d');
    $log = [];
    $tipos = [
        'validade' => static function () use ($cfg): ?array {
            $r = avisos_validade($cfg['dias_validade']);
            return $r['vencidos'] + $r['vencendo'] > 0 ? avisos_texto_validade($r, $cfg['dias_validade']) + ['url' => '/promocoes'] : null;
        },
        'margem' => static function () use ($cfg): ?array {
            $r = avisos_margem($cfg['fator_minimo']);
            return $r['total'] > 0 ? avisos_texto_margem($r, $cfg['fator_minimo']) + ['url' => '/margens?filtro=abaixo2'] : null;
        },
    ];
    foreach ($tipos as $tipo => $montar) {
        if (!$forcar && q1('SELECT 1 FROM aviso_envios WHERE conta_id = ? AND tipo = ? AND dia = ?', [$id, $tipo, $hoje])) {
            continue;
        }
        $msg = $montar();
        if ($msg === null) {
            continue;
        }
        // Reserva o dia ANTES de mandar: duas passadas do cron juntas nao duplicam o aviso.
        if (exec_sql('INSERT IGNORE INTO aviso_envios (conta_id, tipo, dia) VALUES (?, ?, ?)', [$id, $tipo, $hoje]) === 0 && !$forcar) {
            continue;
        }
        $n = push_notificar($id, $msg['titulo'], $msg['corpo'], $msg['url']);
        $log[] = 'aviso ' . $tipo . ': ' . $msg['titulo'] . ' (' . $n . ' aparelho(s))';
    }
    return $log;
}
