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
require APP . '/sync.php';
require APP . '/metas.php';
require APP . '/custos.php';

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
checar('sem migracao: tudo e a conta legada', conta_legada(), true);
checar('sem migracao: placar sem conta no nome', sync_chave('vendas'), 'vendas');

// Depois da migracao.
conta_separada_forcar(true);

// Usuario sem conta conectada: nao ve nada e nao fala com o TouchPay.
conta_definir(null);
checar('sem conta: conta_sql fecha tudo', conta_sql('p.conta_id'), '1=0');
checar('sem conta: conta_pdv_sql fecha tudo', conta_pdv_sql('v.pdv_id'), '1=0');
checar('sem conta: vendas fecham', conta_venda_sql('v.pdv_id'), '1=0');
checar('sem conta: notas fecham', conta_notas_sql('n.usuario_id'), '1=0');
checar('sem conta: nao e a legada', conta_legada(), false);
checar('sem conta: aviso pede para conectar', str_contains(conta_aviso_sem_touchpay(), 'Conecte'), true);

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
checar('dono: e a conta legada', conta_legada(), true);
checar('dono: placar de sempre (o n8n bate nele)', sync_chave('vendas'), 'vendas');

// Outra conta (2): so a dela, e venda orfa do sync nao entra.
conta_definir(2);
checar('outra conta: filtra pela conta 2', conta_sql('p.conta_id'), 'p.conta_id = 2');
checar('outra conta: venda orfa fica de fora',
    conta_venda_sql('v.pdv_id'), 'v.pdv_id IN (SELECT cp.id FROM loja_pdvs cp WHERE cp.conta_id = 2)');
checar('outra conta: nao e a legada', conta_legada(), false);
checar('outra conta: placar proprio', sync_chave('vendas'), 'vendas#2');
checar('outra conta: placar cabe na coluna (20)', strlen(sync_chave('vendas')) <= 20, true);
checar('outra conta: aviso pede a senha neste aparelho',
    str_contains(conta_aviso_sem_touchpay(), 'neste aparelho'), true);

// O numero vai literal no SQL: so inteiro entra.
conta_definir((int) '3 OR 1=1');
checar('id esquisito vira inteiro', conta_sql('p.conta_id'), 'p.conta_id = 3');

// Conta nova nao herda as taxas e metas de exemplo; a legada segue com elas.
conta_separada_forcar(false);
checar('sem migracao: usa os padroes', custos_usa_padrao(), true);
conta_separada_forcar(true);
conta_definir(1);
checar('legada: usa os padroes', custos_usa_padrao(), true);
conta_definir(2);
checar('conta nova: nao usa os padroes', custos_usa_padrao(), false);
checar('conta nova: base toda em zero', array_values(array_unique(custos_base_vazia())), [0.0]);
checar('conta nova: base tem todas as chaves de custo e meta',
    array_keys(custos_base_vazia()), array_keys(custos_padrao() + metas_padrao()));
conta_definir(null);
checar('sem conta: nao usa os padroes', custos_usa_padrao(), false);

echo "\n{$ok} passaram, {$falhou} falharam\n";
exit($falhou > 0 ? 1 : 0);
