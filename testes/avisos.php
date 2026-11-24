<?php
declare(strict_types=1);

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
function cfg(string $k, $p = null) { return $p; }
require APP . '/avisos.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true));
}

// Assinatura VAPID: o JWT tem que verificar com a chave publica.
$par = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
openssl_pkey_export($par, $pem);
$jwt = push_jwt('https://fcm.googleapis.com/fcm/send/abc', ['privada' => $pem, 'publica' => ''], 1000);
[$cab, $cor, $sig] = explode('.', $jwt);
$raw = base64_decode(strtr($sig, '-_', '+/'));
checar('assinatura tem 64 bytes', strlen($raw), 64);
$int = static function (string $b): string {
    $b = ltrim($b, "\0");
    if ($b === '' || (ord($b[0]) & 0x80)) { $b = "\0" . $b; }
    return "\x02" . chr(strlen($b)) . $b;
};
$corpo = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
$der = "\x30" . chr(strlen($corpo)) . $corpo;
checar('JWT verifica', openssl_verify($cab . '.' . $cor, $der, openssl_pkey_get_details($par)['key'], OPENSSL_ALGO_SHA256), 1);
checar('aud', json_decode(base64_decode(strtr($cor, '-_', '+/')), true)['aud'], 'https://fcm.googleapis.com');

// Textos
checar('texto validade', avisos_texto_validade(['vencidos' => 2, 'vencendo' => 5, 'nomes' => ['A', 'B', 'C']], 10),
    ['titulo' => 'Validade: 2 vencidos · 5 vencem em até 10 dias', 'corpo' => 'A, B, C e outros']);
checar('texto validade singular', avisos_texto_validade(['vencidos' => 0, 'vencendo' => 1, 'nomes' => ['Leite']], 10),
    ['titulo' => 'Validade: 1 vence em até 10 dias', 'corpo' => 'Leite']);
checar('texto margem', avisos_texto_margem(['total' => 1, 'nomes' => ['X']], 2.0),
    ['titulo' => 'Margem: 1 produto abaixo de 2,00x', 'corpo' => 'X']);

echo $falhou === 0 ? "avisos: $ok ok\n" : "avisos: $falhou falhou, $ok ok\n";
exit($falhou === 0 ? 0 : 1);
