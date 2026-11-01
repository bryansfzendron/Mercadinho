<?php
declare(strict_types=1);

/*
 * Testa o cofre da senha do TouchPay (app/touchpay_conta.php): o que vai no
 * cookie do aparelho abre so com a chave certa e so para o usuario certo.
 * Nada aqui toca o MySQL nem a rede.
 *
 *   php testes/touchpay_conta.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/touchpay_conta.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

$chave = hash('sha256', 'chave de teste', true);
$outra = hash('sha256', 'outra chave', true);

$selado = tp_cofre_selar('dono@loja.com', 'senha çom acento', 7, $chave);
$aberto = tp_cofre_abrir($selado, 7, $chave);
checar('abre com a mesma chave e usuario: e-mail', $aberto['email'] ?? null, 'dono@loja.com');
checar('abre com a mesma chave e usuario: senha', $aberto['senha'] ?? null, 'senha çom acento');
checar('nao aparece a senha em claro', str_contains($selado, 'senha'), false);
checar('nao aparece o e-mail em claro', str_contains($selado, 'dono'), false);
checar('cookie so com caracteres seguros', (bool) preg_match('/^v1\.[A-Za-z0-9_-]+$/', $selado), true);

checar('outro usuario do app nao abre', tp_cofre_abrir($selado, 8, $chave), null);
checar('outra chave nao abre', tp_cofre_abrir($selado, 7, $outra), null);

// Um caractere trocado no meio: o GCM recusa.
$meio = intdiv(strlen($selado), 2);
$mexido = substr($selado, 0, $meio) . ($selado[$meio] === 'A' ? 'B' : 'A') . substr($selado, $meio + 1);
checar('cookie mexido nao abre', tp_cofre_abrir($mexido, 7, $chave), null);
checar('versao desconhecida nao abre', tp_cofre_abrir('v9.' . substr($selado, 3), 7, $chave), null);
checar('lixo nao abre', tp_cofre_abrir('v1.!!!', 7, $chave), null);
checar('vazio nao abre', tp_cofre_abrir('', 7, $chave), null);
checar('curto demais nao abre', tp_cofre_abrir('v1.AAAA', 7, $chave), null);

checar('duas seladas da mesma senha sao diferentes (IV novo)',
    tp_cofre_selar('a@b.c', 'x', 1, $chave) === tp_cofre_selar('a@b.c', 'x', 1, $chave), false);

// A conta: mesmo e-mail com caixa e espaco diferentes e a mesma loja.
checar('conta ignora maiuscula e espaco',
    tp_conta_chave(' Dono@Loja.com ', $chave), tp_conta_chave('dono@loja.com', $chave));
checar('conta muda com o e-mail',
    tp_conta_chave('dono@loja.com', $chave) === tp_conta_chave('socio@loja.com', $chave), false);
checar('conta depende da chave do servidor',
    tp_conta_chave('dono@loja.com', $chave) === tp_conta_chave('dono@loja.com', $outra), false);
checar('conta tem 64 hex', (bool) preg_match('/^[0-9a-f]{64}$/', tp_conta_chave('a@b.c', $chave)), true);
checar('conta nao contem o e-mail', str_contains(tp_conta_chave('dono@loja.com', $chave), 'dono'), false);

checar('mascara e-mail comum', tp_email_mascarado('bryan.zendron@gmail.com'), 'br***@gmail.com');
checar('mascara nome curto', tp_email_mascarado('ab@x.com'), 'a***@x.com');
checar('mascara nome de uma letra', tp_email_mascarado('a@x.com'), 'a***@x.com');
checar('mascara sem arroba', tp_email_mascarado('sem-arroba'), '***');

echo "\n{$ok} passaram, {$falhou} falharam\n";
exit($falhou > 0 ? 1 : 0);
