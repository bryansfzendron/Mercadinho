<?php
declare(strict_types=1);

/**
 * A conta TouchPay de cada usuario do app.
 *
 * A promessa para quem conecta a propria conta: o e-mail e a senha do TouchPay
 * NAO ficam gravados no servidor. Eles moram num cookie do aparelho da pessoa,
 * cifrado (AES-256-GCM) com uma chave que so o servidor tem. O banco nao ve
 * nenhum dos dois; a copia do banco ou do cookie sozinha nao abre nada.
 *
 * O que o banco guarda e so o vinculo "este usuario usa esta conta", e a conta
 * e identificada por um HMAC do e-mail — da para saber que dois usuarios usam
 * a mesma conta (socios veem a mesma loja) sem que o e-mail esteja la.
 *
 * O limite honesto: na hora de entrar no TouchPay o servidor ve a senha,
 * porque e ele quem faz a chamada. Ve e esquece.
 *
 * Fase 1: so a tela e o cofre. O resto do app ainda fala com o TouchPay pela
 * conta do config.php; as fases seguintes passam a usar a de cada usuario.
 */

const TP_COFRE_COOKIE = 'tp_cofre';
/** Um ano: e o "lembrar neste aparelho". Trocar de celular pede a senha de novo. */
const TP_COFRE_DIAS = 365;
const TP_COFRE_VERSAO = 'v1';

/**
 * A chave do cofre: 32 bytes que so o servidor conhece.
 *
 * Vem de `touchpay_cofre_chave` no config.php. Instalacao que ainda nao tem a
 * chave deriva uma dos segredos que ja estao la (senha do banco e tokens) —
 * continua fora do banco e fora do repositorio. Trocar qualquer um deles
 * invalida os cookies: cada pessoa digita a senha de novo, e nada se perde.
 */
function tp_cofre_chave(): string
{
    $propria = trim((string) cfg('touchpay_cofre_chave', ''));
    if ($propria !== '' && $propria !== 'TROQUE-ME') {
        return hash('sha256', 'tp-cofre|' . $propria, true);
    }
    $base = implode('|', [
        (string) cfg('db_senha', ''),
        (string) cfg('setup_token', ''),
        (string) cfg('cron_token', ''),
        (string) cfg('n8n_token', ''),
    ]);
    if (trim($base, '|') === '') {
        throw new RuntimeException('Sem segredo no config.php para cifrar a senha do TouchPay '
            . '(defina touchpay_cofre_chave).');
    }
    return hash('sha256', 'tp-cofre-derivada|' . $base, true);
}

/**
 * Fecha e-mail e senha num texto opaco, preso a um usuario do app. Pura.
 *
 * O id do usuario entra como dado autenticado: o cookie de um login nao serve
 * para outro login no mesmo aparelho, mesmo que alguem o copie.
 */
function tp_cofre_selar(string $email, string $senha, int $usuario_id, string $chave): string
{
    $iv = random_bytes(12);
    $tag = '';
    $claro = json_encode(['e' => $email, 's' => $senha, 't' => time()], JSON_UNESCAPED_UNICODE);
    $cifrado = openssl_encrypt($claro, 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag,
        tp_cofre_aad($usuario_id));
    if ($cifrado === false) {
        throw new RuntimeException('Nao consegui cifrar a senha do TouchPay.');
    }
    return TP_COFRE_VERSAO . '.' . tp_b64url($iv . $tag . $cifrado);
}

/**
 * Abre o que tp_cofre_selar() fechou. Pura.
 *
 * Qualquer coisa errada — cookie mexido, de outro usuario, chave trocada —
 * devolve null: para a tela e so "este aparelho nao tem a senha".
 *
 * @return ?array{email:string, senha:string, quando:int}
 */
function tp_cofre_abrir(string $selado, int $usuario_id, string $chave): ?array
{
    $partes = explode('.', $selado, 2);
    if (count($partes) !== 2 || $partes[0] !== TP_COFRE_VERSAO) {
        return null;
    }
    $bruto = tp_b64url_ler($partes[1]);
    if ($bruto === null || strlen($bruto) < 12 + 16 + 1) {
        return null;
    }
    $claro = openssl_decrypt(substr($bruto, 28), 'aes-256-gcm', $chave, OPENSSL_RAW_DATA,
        substr($bruto, 0, 12), substr($bruto, 12, 16), tp_cofre_aad($usuario_id));
    if ($claro === false) {
        return null;
    }
    $d = json_decode($claro, true);
    if (!is_array($d) || !is_string($d['e'] ?? null) || !is_string($d['s'] ?? null)) {
        return null;
    }
    return ['email' => $d['e'], 'senha' => $d['s'], 'quando' => (int) ($d['t'] ?? 0)];
}

function tp_cofre_aad(int $usuario_id): string
{
    return 'mercadinho/tp-cofre/usuario:' . $usuario_id;
}

function tp_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function tp_b64url_ler(string $txt): ?string
{
    if ($txt === '' || preg_match('/[^A-Za-z0-9_-]/', $txt)) {
        return null;
    }
    $b = base64_decode(strtr($txt, '-_', '+/') . str_repeat('=', (4 - strlen($txt) % 4) % 4), true);
    return is_string($b) ? $b : null;
}

/**
 * A identidade da conta TouchPay no banco: HMAC do e-mail. Pura.
 *
 * Normaliza antes (espaco, maiuscula), senao "Fulano@x.com" e "fulano@x.com "
 * virariam duas lojas. Sem a chave do servidor nao da para testar e-mails
 * contra a coluna.
 */
function tp_conta_chave(string $email, string $chave): string
{
    return hash_hmac('sha256', 'tp-conta|' . mb_strtolower(trim($email)), $chave);
}

/** "bryan.zendron@gmail.com" -> "br***@gmail.com". Pura. */
function tp_email_mascarado(string $email): string
{
    $email = trim($email);
    $arroba = strrpos($email, '@');
    if ($arroba === false || $arroba === 0) {
        return '***';
    }
    $nome = substr($email, 0, $arroba);
    $visivel = mb_substr($nome, 0, min(2, max(1, mb_strlen($nome) - 1)));
    return $visivel . '***' . substr($email, $arroba);
}

// ---------------------------------------------------------------------
// O cookie deste aparelho
// ---------------------------------------------------------------------

/** E-mail e senha guardados neste aparelho para este usuario, ou null. */
function tp_cofre_ler(int $usuario_id): ?array
{
    $selado = $_COOKIE[TP_COFRE_COOKIE] ?? '';
    if (!is_string($selado) || $selado === '') {
        return null;
    }
    try {
        return tp_cofre_abrir($selado, $usuario_id, tp_cofre_chave());
    } catch (RuntimeException $e) {
        return null;
    }
}

function tp_cofre_gravar(string $email, string $senha, int $usuario_id): void
{
    $valor = tp_cofre_selar($email, $senha, $usuario_id, tp_cofre_chave());
    tp_cofre_cookie($valor, time() + TP_COFRE_DIAS * 86400);
    $_COOKIE[TP_COFRE_COOKIE] = $valor;
}

function tp_cofre_apagar(): void
{
    tp_cofre_cookie('', time() - 86400);
    unset($_COOKIE[TP_COFRE_COOKIE]);
}

/**
 * HttpOnly: o JavaScript da pagina nao enxerga. Secure sempre que o site esta
 * em HTTPS. SameSite=Lax: nao viaja em POST vindo de outro site.
 */
function tp_cofre_cookie(string $valor, int $expira): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || str_starts_with((string) cfg('base_url', ''), 'https://');
    setcookie(TP_COFRE_COOKIE, $valor, [
        'expires'  => $expira,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ---------------------------------------------------------------------
// O vinculo no banco (sem e-mail, sem senha)
// ---------------------------------------------------------------------

/**
 * A conta a que este usuario esta ligado, ou null.
 *
 * Banco sem a tabela (git pull feito, setup.php ainda nao) responde null em vez
 * de derrubar a tela; quem chama avisa que falta a migracao.
 *
 * @return ?array{conta_id:int, chave:string, conectado_em:string}
 */
function tp_conta_do_usuario(int $usuario_id): ?array
{
    try {
        $l = q1(
            'SELECT c.id AS conta_id, c.chave, ut.conectado_em
               FROM usuario_touchpay ut
               JOIN touchpay_contas c ON c.id = ut.conta_id
              WHERE ut.usuario_id = ?',
            [$usuario_id]
        );
    } catch (PDOException $e) {
        return null;
    }
    return $l ? ['conta_id' => (int) $l['conta_id'], 'chave' => (string) $l['chave'],
                 'conectado_em' => (string) $l['conectado_em']] : null;
}

/** As tabelas da conta ja existem? Sem elas a tela pede o setup.php. */
function tp_conta_tabelas_ok(): bool
{
    try {
        q1('SELECT 1 FROM usuario_touchpay LIMIT 1');
        q1('SELECT 1 FROM touchpay_contas LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Liga o usuario a conta deste e-mail, criando a conta se for a primeira vez. */
function tp_conta_vincular(int $usuario_id, string $email): int
{
    $chave = tp_conta_chave($email, tp_cofre_chave());
    exec_sql('INSERT IGNORE INTO touchpay_contas (chave, criado_em) VALUES (?, ?)',
        [$chave, date('Y-m-d H:i:s')]);
    $conta_id = (int) q1('SELECT id FROM touchpay_contas WHERE chave = ?', [$chave])['id'];
    exec_sql(
        'INSERT INTO usuario_touchpay (usuario_id, conta_id, conectado_em) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE conta_id = VALUES(conta_id), conectado_em = VALUES(conectado_em)',
        [$usuario_id, $conta_id, date('Y-m-d H:i:s')]
    );
    return $conta_id;
}

function tp_conta_desvincular(int $usuario_id): void
{
    exec_sql('DELETE FROM usuario_touchpay WHERE usuario_id = ?', [$usuario_id]);
}

/** Quantos usuarios do app usam a mesma conta (para a tela dizer "voce e mais 1"). */
function tp_conta_usuarios(int $conta_id): int
{
    return (int) (q1('SELECT COUNT(*) AS n FROM usuario_touchpay WHERE conta_id = ?', [$conta_id])['n'] ?? 0);
}

/**
 * Este e-mail e o da conta ligada ao usuario? Serve para a tela distinguir
 * "este aparelho ja tem a senha" de "o cookie e de outra conta".
 */
function tp_conta_confere(array $conta, string $email): bool
{
    return hash_equals($conta['chave'], tp_conta_chave($email, tp_cofre_chave()));
}
