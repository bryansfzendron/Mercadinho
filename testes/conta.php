<?php
declare(strict_types=1);

/*
 * Testa o filtro de conta da loja (app/conta.php): cada usuario so enxerga a
 * loja da conta TouchPay que conectou, e antes da migracao nada muda.
 * Nada aqui toca o MySQL nem a rede.
 *
 *   php testes/conta.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/conta.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

// Antes do setup.php: uma loja so, como sempre foi.
conta_separada_forcar(false);
conta_sync_forcar(1);
conta_definir(null);
checar('sem migracao: conta_sql nao filtra', conta_sql('p.conta_id'), '1=1');
checar('sem migracao: conta_pdv_sql nao filtra', conta_pdv_sql('v.pdv_id'), '1=1');
checar('sem migracao: venda nao filtra', conta_venda_sql('v.pdv_id'), '1=1');
checar('sem migracao: notas nao filtram', conta_notas_sql('n.usuario_id'), '1=1');
checar('sem migracao: INSERT sem coluna nova', conta_colunas(), []);
checar('sem migracao: todo mundo fala com o TouchPay', conta_e_do_sync(), true);

// Depois da migracao.
conta_separada_forcar(true);

// Usuario sem conta conectada: nao ve nada e nao fala com o TouchPay.
conta_definir(null);
checar('sem conta: conta_sql fecha tudo', conta_sql('p.conta_id'), '1=0');
checar('sem conta: conta_pdv_sql fecha tudo', conta_pdv_sql('v.pdv_id'), '1=0');
checar('sem conta: vendas fecham', conta_venda_sql('v.pdv_id'), '1=0');
checar('sem conta: notas fecham', conta_notas_sql('n.usuario_id'), '1=0');
checar('sem conta: nao fala com o TouchPay', conta_e_do_sync(), false);
checar('sem conta: aviso pede para conectar', str_contains(conta_aviso_sem_sync(), 'Conecte'), true);

// O dono (conta 1, a do sync).
conta_definir(1);
checar('dono: filtra pela conta 1', conta_sql('p.conta_id'), 'p.conta_id = 1');
checar('dono: PDVs da conta 1',
    conta_pdv_sql('li.pdv_id'), 'li.pdv_id IN (SELECT cp.id FROM loja_pdvs cp WHERE cp.conta_id = 1)');
checar('dono: venda orfa do sync conta para ele',
    conta_venda_sql('v.pdv_id'),
    '(v.pdv_id IS NULL OR v.pdv_id IN (SELECT cp.id FROM loja_pdvs cp WHERE cp.conta_id = 1))');
checar('dono: notas dos usuarios da conta 1',
    conta_notas_sql('n.usuario_id'),
    'n.usuario_id IN (SELECT ut.usuario_id FROM usuario_touchpay ut WHERE ut.conta_id = 1)');
checar('dono: INSERT leva a conta', conta_colunas(), ['conta_id' => 1]);
checar('dono: INSERT do sync leva a conta do sync', conta_colunas(conta_sync()), ['conta_id' => 1]);
checar('dono: fala com o TouchPay', conta_e_do_sync(), true);

// Outra conta (2): so a dela, e venda orfa do sync nao entra.
conta_definir(2);
checar('outra conta: filtra pela conta 2', conta_sql('p.conta_id'), 'p.conta_id = 2');
checar('outra conta: venda orfa fica de fora',
    conta_venda_sql('v.pdv_id'), 'v.pdv_id IN (SELECT cp.id FROM loja_pdvs cp WHERE cp.conta_id = 2)');
checar('outra conta: ainda nao fala com o TouchPay (fase 2)', conta_e_do_sync(), false);
checar('outra conta: aviso de sync pendente', str_contains(conta_aviso_sem_sync(), 'próxima atualização'), true);

// O numero vai literal no SQL: so inteiro entra.
conta_definir((int) '3 OR 1=1');
checar('id esquisito vira inteiro', conta_sql('p.conta_id'), 'p.conta_id = 3');

echo "\n{$ok} passaram, {$falhou} falharam\n";
exit($falhou > 0 ? 1 : 0);
